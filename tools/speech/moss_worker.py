"""MOSS-TTS v1.5 worker for `phpolygon speech:sync`.

Reads jobs ({id, text, language, reference, out, seed, ...}), loads MOSS-TTS v1.5
(OpenMOSS, Apache-2.0; 8B MossTTSDelay on a Qwen3 backbone) and its audio tokenizer
(MOSS-Audio-Tokenizer, 1.6B) once and writes one 24 kHz mono WAV (16-bit PCM) per job.
The job's language (ISO 639-1) becomes the model's language tag ("Czech", ...).

  reference                      cloning: the reference WAV as speaker prompt
  reference + reference_text     cloning with continuation: the reference also opens the
                                 answer, its exact transcript is put before the text
                                 (closest timbre and style)
  no reference                   a random voice per line (the engine always sends one)

Optional job fields (absent or null = the model's default, see MossParams):
  audio_temperature, audio_top_p, audio_top_k, audio_repetition_penalty
                                    sampling of the audio tokens (model: 1.7, 0.8, 25, 1.0)
  text_temperature, text_top_p, text_top_k
                                    sampling of the text channel (model: 1.5, 1.0, 50)
  max_new_tokens                    frames per line at most (12.5 per second; 4096)
  duration_tokens                   expected length in frames (duration control; not
                                    with reference_text)
  reference_text, reference_start, reference_length
                                    transcript and excerpt (seconds) of the reference
  trim_silence                      cut leading/trailing silence of the output (off)

The checkpoints come from Hugging Face at pinned revisions with their own model code
(trust_remote_code), run by transformers 5.0.0 (TRANSFORMERS_VERSION; --require checks it
together with the engine's version of this worker, EXACT_VERSION).

  --dtype auto|bfloat16|float16|float32
                auto = bfloat16 on CUDA from compute capability 8.0 (Ampere) and on
                MPS, float16 below it (Turing, Volta: no native bfloat16), float32 on CPU
  --quant auto|none|nf4|int8
                weights of the backbone (bitsandbytes, CUDA only): auto = nf4 on a CUDA
                card with less than 20 GB (16 bit needs ~17 GB), else none. The output
                heads and audio embeddings stay in --dtype.
  --codec-device auto|model|cpu
                where the audio tokenizer (float32, ~7 GB) runs: auto = beside the model
                while the device has room for it, else on the CPU

Protocol, arguments (--jobs, --device, --threads, --inline, --require) and the
out-of-memory exit code 75: speech_worker.py, shared by every speech worker.
"""
import os
import random
import sys

# The model's generate() draws a tqdm bar per line; the protocol needs a quiet stderr.
os.environ.setdefault("TQDM_DISABLE", "1")

import speech_worker  # noqa: E402

EXACT_VERSION = "1.5.0"
TRANSFORMERS_VERSION = "5.0.0"
MODEL_ID = "OpenMOSS-Team/MOSS-TTS-v1.5"
CODEC_ID = "OpenMOSS-Team/MOSS-Audio-Tokenizer"
# The checkpoint revisions the engine pins (Hugging Face commits), so a voice stays the same voice.
MODEL_REVISION = "cdd3b911b1585e3f2dbc7775ef10f9926f58850a"
CODEC_REVISION = "3cd226ba2947efa357ef453bcad111b6eafba782"

# The 31 language tags of the model card (MOSS-TTS v1.5), by ISO 639 code.
LANGUAGES = {
    "zh": "Chinese", "yue": "Cantonese", "en": "English", "ar": "Arabic", "cs": "Czech",
    "da": "Danish", "nl": "Dutch", "fi": "Finnish", "fr": "French", "de": "German",
    "el": "Greek", "he": "Hebrew", "hi": "Hindi", "hu": "Hungarian", "it": "Italian",
    "ja": "Japanese", "ko": "Korean", "mk": "Macedonian", "ms": "Malay", "fa": "Persian (Farsi)",
    "pl": "Polish", "pt": "Portuguese", "ro": "Romanian", "ru": "Russian", "es": "Spanish",
    "sw": "Swahili", "sv": "Swedish", "tl": "Tagalog", "th": "Thai", "tr": "Turkish",
    "vi": "Vietnamese",
}

REFERENCE = ("reference_start", "reference_length", "reference_text")

# 16-bit weights of the 8B backbone: what a CUDA card needs for --quant none.
FULL_PRECISION_VRAM = 20 * 1024**3


def main() -> int:
    p = speech_worker.parser()
    p.add_argument("--dtype", default="auto", choices=["auto", "bfloat16", "float16", "float32"])
    p.add_argument("--quant", default="auto", choices=["auto", "none", "nf4", "int8"])
    p.add_argument("--codec-device", default="auto", choices=["auto", "model", "cpu"])
    args = p.parse_args()
    if not check_versions(args):
        return 3
    return speech_worker.serve(args, "moss", lambda torch, device: Moss(torch, device, args.dtype, args.quant, args.codec_device))


def check_versions(args) -> bool:
    """--require names the engine's version of this worker; transformers must be the pinned one."""
    if not args.require:
        return True
    installed = speech_worker.installed_version("transformers")
    if args.require == EXACT_VERSION and installed == TRANSFORMERS_VERSION:
        return True
    print(
        f"moss {args.require} needs transformers {TRANSFORMERS_VERSION} (this worker: moss {EXACT_VERSION},"
        f" found transformers {installed or 'none'} in {sys.executable})"
        " - run: vendor/bin/phpolygon speech:setup --synth=moss (--remote=ssh://user@host for a worker host)",
        file=sys.stderr, flush=True,
    )
    return False


def pick_dtype(torch, device: str, wanted: str) -> str:
    if wanted != "auto":
        return wanted
    if device == "cuda":
        major, _ = torch.cuda.get_device_capability(0)
        return "bfloat16" if major >= 8 else "float16"
    if device == "mps":
        return "bfloat16"
    return "float32"


def pick_quant(torch, device: str, wanted: str) -> str:
    if device != "cuda":
        if wanted not in ("auto", "none"):
            raise ValueError(f"--quant {wanted} needs CUDA (bitsandbytes), not {device}")
        return "none"
    if wanted != "auto":
        return wanted
    return "nf4" if torch.cuda.get_device_properties(0).total_memory < FULL_PRECISION_VRAM else "none"


class Moss:
    """The backend of speech_worker.serve."""

    def __init__(self, torch, device: str, dtype: str, quant: str, codec_device: str):
        import numpy
        import soundfile
        from huggingface_hub import snapshot_download
        from transformers import AutoModel, AutoProcessor

        self.torch = torch
        self.numpy = numpy
        self.soundfile = soundfile
        if device == "cuda":
            # The model card's setting: cuDNN's SDPA backend is broken for this model.
            torch.backends.cuda.enable_cudnn_sdp(False)
        chosen = pick_dtype(torch, device, dtype)
        self.quant = pick_quant(torch, device, quant)
        torch_dtype = getattr(torch, chosen)

        model_path = snapshot_download(repo_id=MODEL_ID, revision=os.environ.get("PHPOLYGON_MOSS_REVISION") or MODEL_REVISION)
        codec_path = snapshot_download(repo_id=CODEC_ID, revision=os.environ.get("PHPOLYGON_MOSS_CODEC_REVISION") or CODEC_REVISION)

        # The processor first: transformers copies the checkpoint's code into one module
        # directory, and loading the model there walks every relative import it finds
        # (processing_moss_tts imports the text normaliser, which only the processor brings).
        self.processor = AutoProcessor.from_pretrained(model_path, trust_remote_code=True, codec_path=codec_path)

        kwargs = {"trust_remote_code": True, "dtype": torch_dtype, "attn_implementation": "eager" if device == "cpu" else "sdpa"}
        if self.quant != "none":
            from transformers import BitsAndBytesConfig

            # Only the backbone: the 33 output heads and the audio embeddings stay in 16 bit.
            keep = ["lm_heads", "emb_ext"]
            kwargs["quantization_config"] = (
                BitsAndBytesConfig(load_in_4bit=True, bnb_4bit_quant_type="nf4", bnb_4bit_compute_dtype=torch_dtype,
                                   bnb_4bit_use_double_quant=True, llm_int8_skip_modules=keep)
                if self.quant == "nf4"
                else BitsAndBytesConfig(load_in_8bit=True, llm_int8_skip_modules=keep)
            )
            kwargs["device_map"] = {"": 0}
        self.model = AutoModel.from_pretrained(model_path, **kwargs)
        if self.quant == "none":
            self.model = self.model.to(device)
        self.model.eval()
        self.device = device

        codec = self.processor.audio_tokenizer.to(torch.float32).eval()
        self.codec_device = self.pick_codec_device(codec, codec_device)
        self.processor.audio_tokenizer = codec.to(self.codec_device)
        self.sr = int(self.processor.model_config.sampling_rate)
        print(
            f"moss: {MODEL_ID}@{MODEL_REVISION[:8]} on {device} in {chosen}"
            + (f", {self.quant} weights" if self.quant != "none" else "")
            + f"; audio tokenizer on {self.codec_device}",
            file=sys.stderr, flush=True,
        )
        self.prompt = None  # (key, reference codes) of the voice the last line spoke with

    def pick_codec_device(self, codec, wanted: str) -> str:
        if wanted == "cpu" or self.device == "cpu":
            return "cpu"
        if wanted == "model" or self.device != "cuda":
            return self.device
        size = sum(p.numel() * p.element_size() for p in codec.parameters())
        free, _ = self.torch.cuda.mem_get_info()
        # Room for the tokenizer and for the activations of the backbone beside it.
        return "cuda" if free > size + 1.5 * 1024**3 else "cpu"

    def prepare(self, job: dict) -> None:
        if job["reference"] is None:
            for field in REFERENCE:
                if job.get(field) is not None:
                    raise ValueError(f"{field} needs a reference WAV")
            return
        key = (job["reference"], job.get("reference_start"), job.get("reference_length"))
        if self.prompt is not None and self.prompt[0] == key:
            return
        self.prompt = None  # until the reference is encoded
        wav, sr = self.read(job["reference"])
        start, length = job.get("reference_start"), job.get("reference_length")
        if start is not None or length is not None:
            total = wav.shape[-1]
            first = int(round(float(start or 0) * sr))
            last = total if length is None else min(total, first + int(round(float(length) * sr)))
            if last - first < sr // 10:
                raise ValueError(
                    f"reference excerpt from {start or 0} s ({length if length is not None else 'to the end'})"
                    f" is empty: the WAV is {total / sr:.1f} s long"
                )
            wav = wav[..., first:last]
        with self.torch.no_grad():
            codes = self.processor.encode_audios_from_wav([wav], sr)[0]
        self.prompt = (key, codes)

    def read(self, path: str):
        """A WAV as a float32 tensor [1, n] (mono) and its rate; no ffmpeg needed."""
        data, sr = self.soundfile.read(path, dtype="float32", always_2d=True)
        return self.torch.from_numpy(data.mean(axis=1)).unsqueeze(0), int(sr)

    def generate(self, job: dict):
        torch = self.torch
        seed = int(job["seed"])
        random.seed(seed)
        self.numpy.random.seed(seed % 2**32)
        torch.manual_seed(seed)

        def value(name, default):
            v = job.get(name)
            return default if v is None else v

        text = speech_worker.clean(job["text"])
        language = LANGUAGES.get(job.get("language") or "")
        reference = [self.prompt[1]] if job["reference"] is not None else None
        reference_text = job.get("reference_text")
        message = {"language": language, "reference": reference}
        if reference_text:
            if job.get("duration_tokens") is not None:
                raise ValueError("duration_tokens cannot be combined with reference_text")
            conversation = [
                self.processor.build_user_message(text=speech_worker.clean(reference_text) + " " + text, **message),
                self.processor.build_assistant_message(audio_codes_list=reference),
            ]
            mode = "continuation"
        else:
            if job.get("duration_tokens") is not None:
                message["tokens"] = int(job["duration_tokens"])
            conversation = [self.processor.build_user_message(text=text, **message)]
            mode = "generation"

        batch = self.processor([conversation], mode=mode)
        device = next(self.model.parameters()).device
        with torch.no_grad():
            outputs = self.model.generate(
                input_ids=batch["input_ids"].to(device),
                attention_mask=batch["attention_mask"].to(device),
                max_new_tokens=int(value("max_new_tokens", 4096)),
                text_temperature=float(value("text_temperature", 1.5)),
                text_top_p=float(value("text_top_p", 1.0)),
                text_top_k=int(value("text_top_k", 50)),
                audio_temperature=float(value("audio_temperature", 1.7)),
                audio_top_p=float(value("audio_top_p", 0.8)),
                audio_top_k=int(value("audio_top_k", 25)),
                audio_repetition_penalty=float(value("audio_repetition_penalty", 1.0)),
            )
            messages = self.processor.decode(outputs)
        if not messages or messages[0] is None or not messages[0].audio_codes_list:
            raise RuntimeError("the model returned no audio for this line")
        audio = messages[0].audio_codes_list[0].detach().float().cpu().numpy().reshape(-1)
        if job.get("trim_silence"):
            audio = speech_worker.trim_silence(self.numpy, audio, self.sr)
        return audio

    def save(self, audio, path: str) -> None:
        peak = float(abs(audio).max()) if audio.size else 0.0
        if peak > 1.0:
            audio = audio / peak
        self.soundfile.write(path, audio, self.sr, subtype="PCM_16")


if __name__ == "__main__":
    sys.exit(main())
