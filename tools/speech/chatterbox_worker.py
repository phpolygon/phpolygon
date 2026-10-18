"""Chatterbox Multilingual worker for `phpolygon speech:sync`.

Reads jobs ({id, text, language, reference, out, seed, exaggeration, cfg_weight}),
loads the model once and writes one WAV per job. A job whose reference is null
speaks with the model's built-in voice. Prints one JSON line per finished job
to stdout ({"id", "ok"} or {"id", "error"}); everything else goes to stderr.

Optional job fields (absent or null = the model's default, see SpeechParams):
  temperature, top_p, min_p, repetition_penalty   token sampling
  max_new_tokens                                  token limit (model: 1000)
  cfm_steps                                       flow-matching steps of the decoder (model: 10)
  watermark                                       false = no Perth watermark (model: always on)
  reference_start, reference_length               excerpt of the reference WAV in seconds
  prompt_seconds, voice_seconds                   reference windows of T3 (6 s) and S3Gen (10 s)
generate() re-implements ChatterboxMultilingualTTS.generate of chatterbox-tts
0.1.7 (EXACT_VERSION) to reach these; without them it is bit-identical to it.
On another version a job that sets one of them fails with a clear message, the
others run through model.generate().

Protocol, arguments (--jobs, --device, --threads, --inline, --require) and the
out-of-memory exit code 75: speech_worker.py, shared by every speech worker.
"""
import copy
import dataclasses
import os
import sys

import speech_worker

# The version generate() below re-implements; other versions only run model.generate().
EXACT_VERSION = "0.1.7"

# Job fields that need the re-implementation (or the reference handling) of this worker.
EXTENDED = ("temperature", "top_p", "min_p", "repetition_penalty", "max_new_tokens", "cfm_steps", "watermark")
REFERENCE = ("reference_start", "reference_length", "prompt_seconds", "voice_seconds")


def main() -> int:
    args = speech_worker.parser().parse_args()
    if not speech_worker.check_version(args, "chatterbox-tts"):
        return 3
    return speech_worker.serve(args, "chatterbox", Chatterbox)


class Chatterbox:
    """The backend of speech_worker.serve: conditioning per voice, generation per line."""

    def __init__(self, torch, device: str):
        import torchaudio
        from chatterbox.mtl_tts import ChatterboxMultilingualTTS

        self.torch = torch
        self.torchaudio = torchaudio
        self.installed = speech_worker.installed_version("chatterbox-tts")
        self.exact = self.installed == EXACT_VERSION
        self.model = load_model(ChatterboxMultilingualTTS, torch, device)
        self.builtin = snapshot(torch, self.model.conds)  # the built-in voice (conds.pt)
        self.reference = None  # what model.conds holds: False = the built-in voice, else a reference_key()

    def prepare(self, job: dict) -> None:
        extended = [k for k in EXTENDED + REFERENCE if job.get(k) is not None]
        if extended and not self.exact:
            raise RuntimeError(
                f"{', '.join(extended)} need chatterbox-tts {EXACT_VERSION}, found {self.installed or 'none'}"
                " (the worker re-implements its generate) - run: vendor/bin/phpolygon speech:setup"
            )
        if job["reference"] is None:
            if self.builtin is None:
                raise RuntimeError("this checkpoint has no built-in voice (conds.pt); give the voice a reference WAV")
            if self.reference is not False:
                self.model.conds = snapshot(self.torch, self.builtin)
                self.reference = False
        else:
            key = reference_key(job)
            if key != self.reference:
                self.reference = None  # until the conditioning succeeds
                condition(self.model, self.torchaudio, job, speech_worker.reference_cache())
                self.reference = key

    def generate(self, job: dict):
        self.torch.manual_seed(job["seed"])
        drop_alignment_hooks(self.model)
        if self.exact:
            return generate(self.model, self.torch, job)
        return self.model.generate(
            job["text"],
            language_id=job["language"],
            exaggeration=job["exaggeration"],
            cfg_weight=job["cfg_weight"],
        )

    def save(self, wav, path: str) -> None:
        self.torchaudio.save(path, wav.cpu(), self.model.sr)


def reference_key(job: dict):
    """
    What the conditioning of a cloned voice depends on: the WAV, its excerpt and
    the windows. Exaggeration is not part of it: generate() sets it on the cached
    conditionals per job, as ChatterboxMultilingualTTS.generate does.
    """
    return (job["reference"],) + tuple(job.get(k) for k in REFERENCE)


def condition(model, torchaudio, job: dict, cache: str) -> None:
    """
    prepare_conditionals on the reference, cut to reference_start/_length first
    (written losslessly as float WAV), with the T3 / S3Gen windows of
    prompt_seconds / voice_seconds set on the instance for the call.
    """
    from chatterbox.models.s3gen import S3GEN_SR
    from chatterbox.models.s3tokenizer import S3_SR

    path = job["reference"]
    windows = {}
    if job.get("prompt_seconds") is not None:
        windows["ENC_COND_LEN"] = max(1, int(round(float(job["prompt_seconds"]) * S3_SR)))
    if job.get("voice_seconds") is not None:
        windows["DEC_COND_LEN"] = max(1, int(round(float(job["voice_seconds"]) * S3GEN_SR)))
    excerpt = speech_worker.excerpt(path, job.get("reference_start"), job.get("reference_length"), cache, torchaudio.load, torchaudio.save)
    try:
        if excerpt is not None:
            path = excerpt
        for name, value in windows.items():
            setattr(model, name, value)
        model.prepare_conditionals(path, exaggeration=job["exaggeration"])
    finally:
        for name in windows:
            if name in vars(model):
                delattr(model, name)  # back to the class attribute
        if excerpt is not None and os.path.exists(excerpt):
            os.remove(excerpt)


def drop_alignment_hooks(model):
    """
    T3.inference builds a new AlignmentStreamAnalyzer for every line (it resets its
    `compiled` flag each call), and each one registers a forward hook on attention
    layers that is never removed. The hooks pile up: after n lines every token runs
    n hooks, each copying attention weights to the CPU – a worker gets slower with
    every line (measured: a third of the throughput after ~1000 lines). They only
    read, so dropping the stale ones before the next line changes no output.
    """
    tfmr = getattr(getattr(model, "t3", None), "tfmr", None)
    for layer in getattr(tfmr, "layers", []) or []:
        attn = getattr(layer, "self_attn", None)
        hooks = getattr(attn, "_forward_hooks", None)
        if hooks:
            hooks.clear()


def generate(model, torch, job: dict):
    """
    ChatterboxMultilingualTTS.generate of chatterbox-tts 0.1.7, step by step, with
    every knob the original fixes or hides: max_new_tokens (1000 there),
    n_cfm_timesteps of S3Gen (its own default there) and the watermark (always on
    there). Unset fields take the defaults of the original, so the result is
    bit-identical to model.generate() with the same seed. Needs prepared conditionals.
    """
    import torch.nn.functional as F
    from chatterbox.mtl_tts import SUPPORTED_LANGUAGES, punc_norm
    from chatterbox.models.s3tokenizer import drop_invalid_tokens
    from chatterbox.models.t3.modules.cond_enc import T3Cond

    def value(name, default):
        v = job.get(name)
        return default if v is None else v

    text = job["text"]
    language_id = job["language"]
    exaggeration = job["exaggeration"]
    cfg_weight = job["cfg_weight"]
    temperature = float(value("temperature", 0.8))
    repetition_penalty = float(value("repetition_penalty", 2.0))
    min_p = float(value("min_p", 0.05))
    top_p = float(value("top_p", 1.0))
    max_new_tokens = int(value("max_new_tokens", 1000))
    cfm_steps = job.get("cfm_steps")
    watermark = value("watermark", True)

    if language_id and language_id.lower() not in SUPPORTED_LANGUAGES:
        supported = ", ".join(SUPPORTED_LANGUAGES.keys())
        raise ValueError(f"Unsupported language_id '{language_id}'. Supported languages: {supported}")
    if model.conds is None:
        raise RuntimeError("no conditionals: prepare a reference or load the built-in voice first")

    if float(exaggeration) != float(model.conds.t3.emotion_adv[0, 0, 0].item()):
        _cond = model.conds.t3
        model.conds.t3 = T3Cond(
            speaker_emb=_cond.speaker_emb,
            cond_prompt_speech_tokens=_cond.cond_prompt_speech_tokens,
            emotion_adv=exaggeration * torch.ones(1, 1, 1),
        ).to(device=model.device)

    text = punc_norm(text)
    text_tokens = model.tokenizer.text_to_tokens(text, language_id=language_id.lower() if language_id else None).to(model.device)
    text_tokens = torch.cat([text_tokens, text_tokens], dim=0)  # two sequences for CFG
    sot = model.t3.hp.start_text_token
    eot = model.t3.hp.stop_text_token
    text_tokens = F.pad(text_tokens, (1, 0), value=sot)
    text_tokens = F.pad(text_tokens, (0, 1), value=eot)

    with torch.inference_mode():
        speech_tokens = model.t3.inference(
            t3_cond=model.conds.t3,
            text_tokens=text_tokens,
            max_new_tokens=max_new_tokens,
            temperature=temperature,
            cfg_weight=cfg_weight,
            repetition_penalty=repetition_penalty,
            min_p=min_p,
            top_p=top_p,
        )
        speech_tokens = speech_tokens[0]  # the conditional batch
        speech_tokens = drop_invalid_tokens(speech_tokens)
        speech_tokens = speech_tokens.to(model.device)
        decoder = {} if cfm_steps is None else {"n_cfm_timesteps": int(cfm_steps)}
        wav, _ = model.s3gen.inference(speech_tokens=speech_tokens, ref_dict=model.conds.gen, **decoder)
        wav = wav.squeeze(0).detach().cpu().numpy()
        if watermark is not False:
            wav = model.watermarker.apply_watermark(wav, sample_rate=model.sr)
    return torch.from_numpy(wav).unsqueeze(0)


def snapshot(torch, value):
    """
    A copy of the model's conditionals that later jobs cannot change. copy.deepcopy
    refuses tensors that are not graph leaves, which is what the built-in voice holds
    after the move to MPS; detached clones copy on every device.
    """
    if torch.is_tensor(value):
        return value.detach().clone()
    if dataclasses.is_dataclass(value) and not isinstance(value, type):
        copied = copy.copy(value)
        for field in dataclasses.fields(value):
            setattr(copied, field.name, snapshot(torch, getattr(value, field.name)))
        return copied
    if isinstance(value, dict):
        return {key: snapshot(torch, item) for key, item in value.items()}
    if isinstance(value, (list, tuple)):
        return type(value)(snapshot(torch, item) for item in value)
    return copy.deepcopy(value)


def load_model(cls, torch, device: str):
    """
    from_pretrained maps its checkpoints to the CPU for "cpu"/"mps" itself, but any
    torch.load without map_location (in chatterbox or a dependency) still restores
    CUDA-saved tensors on CUDA and fails without a visible GPU ("Attempting to
    deserialize object on CUDA device 0 but torch.cuda.device_count() is 0").
    Off CUDA every load lands on the CPU first; the model moves to its device after.
    """
    if device == "cuda":
        return cls.from_pretrained(device=device)
    original = torch.load

    def load_on_cpu(*args, **kwargs):
        if len(args) < 2 and kwargs.get("map_location") is None:
            kwargs["map_location"] = torch.device("cpu")
        return original(*args, **kwargs)

    torch.load = load_on_cpu
    try:
        return cls.from_pretrained(device=device)
    finally:
        torch.load = original


if __name__ == "__main__":
    sys.exit(main())
