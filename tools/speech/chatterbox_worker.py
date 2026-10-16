"""Chatterbox Multilingual worker for `phpolygon speech:sync`.

Reads jobs ({id, text, language, reference, out, seed, exaggeration, cfg_weight}),
loads the model once and writes one WAV per job. A job whose reference is null
speaks with the model's built-in voice. Prints one JSON line per finished job
to stdout ({"id", "ok"} or {"id", "error"}); everything else goes to stderr.

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
import json
import os
import sys
import tempfile
import time


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

    if args.require:
        from importlib import metadata
        try:
            installed = metadata.version("chatterbox-tts")
        except metadata.PackageNotFoundError:
            installed = None
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
    builtin = copy.deepcopy(model.conds)  # the built-in voice (conds.pt)

    reference = None
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
            if job["reference"] is None:
                if builtin is None:
                    raise RuntimeError("this checkpoint has no built-in voice (conds.pt); give the voice a reference WAV")
                if reference is not False:
                    model.conds = copy.deepcopy(builtin)
                    reference = False
            elif job["reference"] != reference:
                reference = None  # until prepare_conditionals succeeds
                model.prepare_conditionals(job["reference"], exaggeration=job["exaggeration"])
                reference = job["reference"]
            torch.manual_seed(job["seed"])
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
