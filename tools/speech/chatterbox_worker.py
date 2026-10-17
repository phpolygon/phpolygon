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

  --jobs FILE   a JSONL file with every job (the whole run at once)
  --jobs -      jobs arrive as JSON lines on stdin and are answered in order; EOF
                ends the worker (default). The engine runs its workers this way
                and hands each one its next job when it reports the last.
  --device      auto (cuda, else mps, else cpu), cuda, mps or cpu
  --threads N   torch CPU threads when the worker runs on the CPU
  --inline      remote host without shared paths: a job's reference is the SHA-1 of
                the WAV, sent once as base64 in "reference_wav" and cached in the temp
                directory; the result carries the generated WAV as base64 in "wav"
  --require V   exit with code 3 unless chatterbox-tts V is installed
"""
import argparse
import base64
import copy
import dataclasses
import json
import os
import sys
import tempfile
import time

# The version generate() below re-implements; other versions only run model.generate().
EXACT_VERSION = "0.1.7"

# Job fields that need the re-implementation (or the reference handling) of this worker.
EXTENDED = ("temperature", "top_p", "min_p", "repetition_penalty", "max_new_tokens", "cfm_steps", "watermark")
REFERENCE = ("reference_start", "reference_length", "prompt_seconds", "voice_seconds")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--jobs", default="-")
    parser.add_argument("--device", default="auto", choices=["auto", "cuda", "mps", "cpu"])
    parser.add_argument("--threads", type=int, default=0)
    parser.add_argument("--inline", action="store_true")
    parser.add_argument("--require", default="")
    args = parser.parse_args()

    # Libraries print to stdout; keep it for the protocol.
    protocol = sys.stdout
    sys.stdout = sys.stderr

    def emit(result: dict) -> None:
        protocol.write(json.dumps(result) + "\n")
        protocol.flush()

    from importlib import metadata
    try:
        installed = metadata.version("chatterbox-tts")
    except metadata.PackageNotFoundError:
        installed = None
    if args.require:
        if installed != args.require:
            print(
                f"chatterbox-tts {args.require} is required, found {installed or 'none'} in {sys.executable}"
                " - run: vendor/bin/phpolygon speech:setup (--remote=ssh://user@host for a worker host)",
                file=sys.stderr, flush=True,
            )
            return 3

    cache = os.path.join(tempfile.gettempdir(), "phpolygon-speech-refs")
    if args.inline:
        os.makedirs(cache, exist_ok=True)

    def inline_reference(job: dict):
        """The cached path of an inline reference, written when the WAV came along."""
        digest = job["reference"]
        if digest is None:
            return None
        if not all(c in "0123456789abcdef" for c in digest):
            raise ValueError(f"bad reference hash {digest!r}")
        path = os.path.join(cache, digest + ".wav")
        if job.get("reference_wav"):
            fd, part = tempfile.mkstemp(dir=cache, suffix=".part")
            with os.fdopen(fd, "wb") as f:
                f.write(base64.b64decode(job["reference_wav"]))
            os.replace(part, path)
        if not os.path.exists(path):
            raise FileNotFoundError(f"reference {digest} was never sent to this worker")
        return path

    if args.jobs == "-":
        def jobs():
            # Bytes, not text: the stdin encoding of a Windows pipe is the ANSI code page.
            while True:
                line = sys.stdin.buffer.readline()
                if not line:
                    return
                if line.strip():
                    yield json.loads(line.decode("utf-8"))
        count = "jobs from stdin"
    else:
        with open(args.jobs, encoding="utf-8") as f:
            listed = [json.loads(line) for line in f if line.strip()]

        def jobs():
            return iter(listed)
        count = f"{len(listed)} line(s)"

    import torch
    import torchaudio
    from chatterbox.mtl_tts import ChatterboxMultilingualTTS

    device = args.device
    if device == "auto":
        if torch.cuda.is_available() and torch.cuda.device_count() > 0:
            device = "cuda"
        elif torch.backends.mps.is_available():
            device = "mps"
        else:
            device = "cpu"
    if device == "cpu" and args.threads > 0:
        torch.set_num_threads(args.threads)
    threads = f", {torch.get_num_threads()} thread(s)" if device == "cpu" else ""
    print(f"chatterbox: {count} on {device}{threads}", file=sys.stderr, flush=True)
    model = load_model(ChatterboxMultilingualTTS, torch, device)
    builtin = snapshot(torch, model.conds)  # the built-in voice (conds.pt)

    exact = installed == EXACT_VERSION
    reference = None  # what model.conds holds: False = the built-in voice, else a reference_key()
    for job in jobs():
        start = time.time()
        scratch = None
        try:
            out = job["out"]
            if args.inline:
                job["reference"] = inline_reference(job)
                fd, out = tempfile.mkstemp(dir=cache, suffix=".wav")
                os.close(fd)
                scratch = out
            extended = [k for k in EXTENDED + REFERENCE if job.get(k) is not None]
            if extended and not exact:
                raise RuntimeError(
                    f"{', '.join(extended)} need chatterbox-tts {EXACT_VERSION}, found {installed or 'none'}"
                    " (the worker re-implements its generate) - run: vendor/bin/phpolygon speech:setup"
                )
            if job["reference"] is None:
                if builtin is None:
                    raise RuntimeError("this checkpoint has no built-in voice (conds.pt); give the voice a reference WAV")
                if reference is not False:
                    model.conds = snapshot(torch, builtin)
                    reference = False
            else:
                key = reference_key(job)
                if key != reference:
                    reference = None  # until the conditioning succeeds
                    condition(model, torchaudio, job, cache)
                    reference = key
            torch.manual_seed(job["seed"])
            drop_alignment_hooks(model)
            if exact:
                wav = generate(model, torch, job)
            else:
                wav = model.generate(
                    job["text"],
                    language_id=job["language"],
                    exaggeration=job["exaggeration"],
                    cfg_weight=job["cfg_weight"],
                )
            torchaudio.save(out, wav.cpu(), model.sr)
            result = {"id": job["id"], "ok": True, "seconds": round(time.time() - start, 1)}
            if args.inline:
                with open(out, "rb") as f:
                    result["wav"] = base64.b64encode(f.read()).decode("ascii")
            emit(result)
        except Exception as e:  # one bad line must not stop the run
            emit({"id": job["id"], "error": f"{type(e).__name__}: {e}"})
        finally:
            if scratch is not None and os.path.exists(scratch):
                os.remove(scratch)
    return 0


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
    excerpt = None
    start = job.get("reference_start")
    length = job.get("reference_length")
    windows = {}
    if job.get("prompt_seconds") is not None:
        windows["ENC_COND_LEN"] = max(1, int(round(float(job["prompt_seconds"]) * S3_SR)))
    if job.get("voice_seconds") is not None:
        windows["DEC_COND_LEN"] = max(1, int(round(float(job["voice_seconds"]) * S3GEN_SR)))
    try:
        if start is not None or length is not None:
            wav, sr = torchaudio.load(path)
            total = wav.shape[-1]
            first = int(round(float(start or 0) * sr))
            last = total if length is None else min(total, first + int(round(float(length) * sr)))
            if last - first < sr // 10:
                raise ValueError(
                    f"reference excerpt from {start or 0} s ({length if length is not None else 'to the end'})"
                    f" is empty: the WAV is {total / sr:.1f} s long"
                )
            os.makedirs(cache, exist_ok=True)
            fd, excerpt = tempfile.mkstemp(dir=cache, suffix=".wav")
            os.close(fd)
            torchaudio.save(excerpt, wav[:, first:last], sr)
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
