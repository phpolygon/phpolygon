"""VoxCPM2 worker for `phpolygon speech:sync` and `phpolygon speech:design`.

Reads jobs ({id, text, language, reference, out, seed, ...}), loads VoxCPM2 once and
writes one 48 kHz mono WAV (16-bit PCM) per job. VoxCPM2 takes no language id: it
reads the language from the text (the job's language is only logged).

  reference null + description   Voice Design: a new voice from the description
                                 ("(description)text" as the model expects it)
  reference                      cloning: the reference WAV as an isolated speaker prompt
  reference + reference_text     "ultimate" cloning: the same WAV also as a continuation
                                 prompt with its exact transcript (closest timbre and style)
  reference + description        cloning with a style instruction

Optional job fields (absent or null = the model's default, see VoxCpmParams):
  cfg_value                         guidance of the diffusion head (model: 2.0)
  inference_timesteps               diffusion steps (model: 10)
  max_len, min_len                  token limits per line (model: 4096 / 2; 6.25 tokens/s)
  retry_badcase, retry_badcase_max_times, retry_badcase_ratio_threshold
                                    retry a take that runs on (model: on, 3, 6.0)
  normalize                         text normalisation of numbers etc. (zh/en; model: off)
  description, reference_text       see above
  reference_start, reference_length excerpt of the reference WAV in seconds
  trim_silence                      cut leading/trailing silence of the output (model: off)

The worker calls the model's own generation (VoxCPM._generate step by step, to cache
the prompt of a voice across its lines) of voxcpm 2.0.3 (EXACT_VERSION); --require
pins it.

  --dtype auto|bfloat16|float16|float32
                auto = bfloat16 on CUDA from compute capability 8.0 (Ampere), float16
                below it (Turing, Volta: no native bfloat16), float32 on CPU and MPS
  --no-compile  no torch.compile (on CUDA with triton it is on: a slower first line,
                faster after)

Protocol, arguments (--jobs, --device, --threads, --inline, --require) and the
out-of-memory exit code 75: speech_worker.py, shared by every speech worker.
"""
import os
import random
import sys

import speech_worker

EXACT_VERSION = "2.0.3"
MODEL_ID = "openbmb/VoxCPM2"
# The checkpoint revision the engine pins (Hugging Face commit), so a voice stays the same voice.
MODEL_REVISION = "32279effe8c19989596f05d353d1447f51d9e915"

REFERENCE = ("reference_start", "reference_length", "reference_text")


def main() -> int:
    p = speech_worker.parser()
    p.add_argument("--dtype", default="auto", choices=["auto", "bfloat16", "float16", "float32"])
    p.add_argument("--no-compile", action="store_true")
    args = p.parse_args()
    if not speech_worker.check_version(args, "voxcpm", "speech:setup --synth=voxcpm"):
        return 3
    return speech_worker.serve(args, "voxcpm", lambda torch, device: VoxCpm(torch, device, args.dtype, not args.no_compile))


def pick_dtype(torch, device: str, wanted: str) -> str:
    if wanted != "auto":
        return wanted
    if device == "cuda":
        major, _ = torch.cuda.get_device_capability(0)
        return "bfloat16" if major >= 8 else "float16"
    return "float32"


class VoxCpm:
    """The backend of speech_worker.serve."""

    def __init__(self, torch, device: str, dtype: str, compile_model: bool):
        import numpy
        import soundfile
        import voxcpm.model.voxcpm2 as v2
        from voxcpm import VoxCPM

        self.torch = torch
        self.numpy = numpy
        self.soundfile = soundfile
        chosen = pick_dtype(torch, device, dtype)
        # The checkpoint says bfloat16; VoxCPM2Model asks pick_runtime_dtype for the
        # dtype it runs in (MPS: float32). Answer with ours, for every device.
        v2.pick_runtime_dtype = lambda _device, _configured: chosen
        from huggingface_hub import snapshot_download

        path = snapshot_download(repo_id=MODEL_ID, revision=os.environ.get("PHPOLYGON_VOXCPM_REVISION") or MODEL_REVISION)
        compile_model = compile_model and device == "cuda"
        print(f"voxcpm: {MODEL_ID}@{os.path.basename(path)[:8]} on {device} in {chosen}" + (", torch.compile" if compile_model else ""), file=sys.stderr, flush=True)
        try:
            self.model = VoxCPM(path, zipenhancer_model_path=None, enable_denoiser=False, optimize=compile_model, device=device)
        except Exception as e:
            if not compile_model or speech_worker.is_out_of_memory(e):
                raise
            # torch.compile needs triton and a C compiler; without them the model runs eagerly.
            print(f"voxcpm: torch.compile failed ({type(e).__name__}: {str(e).splitlines()[0] if str(e) else ''}) - running without it", file=sys.stderr, flush=True)
            import torch._dynamo
            torch._dynamo.reset()
            self.model = None
            speech_worker.free_memory(torch, device)
            self.model = VoxCPM(path, zipenhancer_model_path=None, enable_denoiser=False, optimize=False, device=device)
        self.tts = self.model.tts_model
        self.sr = self.tts.sample_rate
        self.prompt = None  # (key, prompt cache) of the voice the last line spoke with

    def prepare(self, job: dict) -> None:
        if job["reference"] is None:
            for field in REFERENCE:
                if job.get(field) is not None:
                    raise ValueError(f"{field} needs a reference WAV")
            return
        key = (job["reference"],) + tuple(job.get(k) for k in REFERENCE)
        if self.prompt is not None and self.prompt[0] == key:
            return
        self.prompt = None  # until the prompt is built
        import librosa

        cache = speech_worker.reference_cache()
        excerpt = speech_worker.excerpt(
            job["reference"], job.get("reference_start"), job.get("reference_length"), cache,
            lambda path: (lambda wav_sr: (wav_sr[0][None, :], wav_sr[1]))(librosa.load(path, sr=None, mono=True)),
            lambda path, wav, sr: self.soundfile.write(path, wav[0], sr, subtype="FLOAT"),
        )
        path = excerpt or job["reference"]
        try:
            text = job.get("reference_text")
            prompt = self.tts.build_prompt_cache(
                prompt_text=clean(text) if text else None,
                prompt_wav_path=path if text else None,
                reference_wav_path=path,
            )
        finally:
            if excerpt is not None and os.path.exists(excerpt):
                os.remove(excerpt)
        self.prompt = (key, prompt)

    def generate(self, job: dict):
        seed = int(job["seed"])
        random.seed(seed)
        self.numpy.random.seed(seed % 2**32)
        self.torch.manual_seed(seed)

        text = clean(job["text"])
        if job.get("normalize"):
            if getattr(self.model, "text_normalizer", None) is None:
                from voxcpm.utils.text_normalize import TextNormalizer
                self.model.text_normalizer = TextNormalizer()
            text = self.model.text_normalizer.normalize(text)
        description = job.get("description")
        if description:
            text = "(" + clean(description) + ")" + text

        def value(name, default):
            v = job.get(name)
            return default if v is None else v

        result = self.tts._generate_with_prompt_cache(
            target_text=text,
            prompt_cache=self.prompt[1] if job["reference"] is not None else None,
            min_len=int(value("min_len", 2)),
            max_len=int(value("max_len", 4096)),
            inference_timesteps=int(value("inference_timesteps", 10)),
            cfg_value=float(value("cfg_value", 2.0)),
            retry_badcase=bool(value("retry_badcase", True)),
            retry_badcase_max_times=int(value("retry_badcase_max_times", 3)),
            retry_badcase_ratio_threshold=float(value("retry_badcase_ratio_threshold", 6.0)),
            streaming=False,
        )
        try:
            wav, _, _ = next(result)
        finally:
            result.close()
        audio = wav.squeeze(0).float().cpu().numpy()
        if job.get("trim_silence"):
            audio = trim_silence(self.numpy, audio, self.sr)
        return audio

    def save(self, audio, path: str) -> None:
        peak = float(abs(audio).max()) if audio.size else 0.0
        if peak > 1.0:
            audio = audio / peak
        self.soundfile.write(path, audio, self.sr, subtype="PCM_16")


clean = speech_worker.clean
trim_silence = speech_worker.trim_silence


if __name__ == "__main__":
    sys.exit(main())
