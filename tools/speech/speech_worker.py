"""The protocol every speech worker of `phpolygon speech:sync` speaks (shared module).

A worker reads jobs ({id, text, language, reference, out, seed, ...}), loads its
model once and writes one WAV per job. It prints one JSON line per finished job to
stdout ({"id", "ok", "seconds"} or {"id", "error"}); everything else goes to stderr.

  --jobs FILE   a JSONL file with every job (the whole run at once)
  --jobs -      jobs arrive as JSON lines on stdin and are answered in order; EOF
                ends the worker (default). The engine runs its workers this way
                and hands each one its next job when it reports the last.
  --device      auto (cuda, else mps, else cpu), cuda, mps or cpu
  --threads N   torch CPU threads when the worker runs on the CPU
  --inline      remote host without shared paths: a job's reference is the SHA-1 of
                the WAV, sent once as base64 in "reference_wav" and cached in the temp
                directory; the result carries the generated WAV as base64 in "wav"
  --require V   exit with code 3 unless version V of the model package is installed

Exit code 75: out of device memory on a line even after freeing the cache, or more than
RECYCLE_AT of it in use after a line (at least RECYCLE_HEADROOM above what the loaded model holds). The line in flight is not reported; the engine puts
it back into the queue and starts the worker anew (a fresh process frees what leaked).

A model plugs in as a backend ({@see serve}): prepare(job) once per job outside the
out-of-memory retry (conditioning, caches), generate(job) inside it, save(audio, path).
"""
import argparse
import base64
import gc
import json
import os
import re
import sys
import tempfile
import time

# Exit code for "out of device memory" (SpeechWorkerPool::EXIT_OUT_OF_MEMORY): the engine puts
# the line in flight back into the queue and starts this worker anew.
EXIT_OUT_OF_MEMORY = 75

# Share of the device memory in tensors above which the worker recycles itself between lines:
# RECYCLE_AT, or the share the loaded model holds plus RECYCLE_HEADROOM when that is higher
# (a model that alone fills more than RECYCLE_AT of the card would recycle after every line).
RECYCLE_AT = 0.6
RECYCLE_HEADROOM = 0.2


def parser() -> argparse.ArgumentParser:
    """The arguments every worker takes; a worker may add its own before parse_args()."""
    p = argparse.ArgumentParser()
    p.add_argument("--jobs", default="-")
    p.add_argument("--device", default="auto", choices=["auto", "cuda", "mps", "cpu"])
    p.add_argument("--threads", type=int, default=0)
    p.add_argument("--inline", action="store_true")
    p.add_argument("--require", default="")
    return p


def installed_version(package: str):
    from importlib import metadata
    try:
        return metadata.version(package)
    except metadata.PackageNotFoundError:
        return None


def check_version(args, package: str, setup: str = "speech:setup") -> bool:
    """False (after a message) when --require names another version than the installed one."""
    installed = installed_version(package)
    if args.require and installed != args.require:
        print(
            f"{package} {args.require} is required, found {installed or 'none'} in {sys.executable}"
            f" - run: vendor/bin/phpolygon {setup} (--remote=ssh://user@host for a worker host)",
            file=sys.stderr, flush=True,
        )
        return False
    return True


def resolve_device(torch, wanted: str) -> str:
    if wanted != "auto":
        return wanted
    if torch.cuda.is_available() and torch.cuda.device_count() > 0:
        return "cuda"
    if torch.backends.mps.is_available():
        return "mps"
    return "cpu"


def reference_cache() -> str:
    return os.path.join(tempfile.gettempdir(), "phpolygon-speech-refs")


def serve(args, name: str, load) -> int:
    """
    Run the job loop. load(torch, device) returns the backend: an object with
    prepare(job), generate(job) -> audio and save(audio, path). Libraries print to
    stdout; the protocol keeps its own handle and stdout goes to stderr meanwhile.
    """
    protocol = sys.stdout
    sys.stdout = sys.stderr

    def emit(result: dict) -> None:
        protocol.write(json.dumps(result) + "\n")
        protocol.flush()

    cache = reference_cache()
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

    device = resolve_device(torch, args.device)
    if device == "cpu" and args.threads > 0:
        torch.set_num_threads(args.threads)
    threads = f", {torch.get_num_threads()} thread(s)" if device == "cpu" else ""
    print(f"{name}: {count} on {device}{threads}", file=sys.stderr, flush=True)
    backend = load(torch, device)
    loaded = memory_used(torch, device)
    recycle_at = max(RECYCLE_AT, (loaded or 0.0) + RECYCLE_HEADROOM)
    if loaded is not None:
        print(f"{name}: model holds {loaded:.0%} of {device} memory, recycling above {recycle_at:.0%}", file=sys.stderr, flush=True)

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
            backend.prepare(job)
            for attempt in (1, 2):
                try:
                    audio = backend.generate(job)
                    break
                except Exception as e:
                    if not is_out_of_memory(e):
                        raise
                    free_memory(torch, device)
                    if attempt == 2:
                        # Still out of memory in a cleaned-up process: only a new one helps.
                        # Not reported – the engine puts the line back and starts the worker anew.
                        print(f"{name}: out of memory on {device} ({e}) - restarting", file=sys.stderr, flush=True)
                        return EXIT_OUT_OF_MEMORY
            backend.save(audio, out)
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
        free_memory(torch, device)
        # Device memory creeps up over a long run (MPS: 28 GB after ~500 lines). Recycle
        # between lines, before a line fails on it; the engine starts the worker anew.
        used = memory_used(torch, device)
        if used is not None and used > recycle_at:
            print(f"{name}: {used:.0%} of {device} memory in use - restarting", file=sys.stderr, flush=True)
            return EXIT_OUT_OF_MEMORY
    return 0


def is_out_of_memory(e: Exception) -> bool:
    """CUDA's OutOfMemoryError, or the RuntimeError MPS and older torch raise."""
    return type(e).__name__ == "OutOfMemoryError" or "out of memory" in str(e).lower()


def free_memory(torch, device: str) -> None:
    """Drop what the last line left behind and hand cached blocks back to the device."""
    gc.collect()
    if device == "mps":
        torch.mps.empty_cache()
    elif device == "cuda":
        torch.cuda.empty_cache()


def memory_used(torch, device: str):
    """Share of the device memory this process holds in tensors, or None (CPU, unknown)."""
    try:
        if device == "mps":
            return torch.mps.current_allocated_memory() / torch.mps.recommended_max_memory()
        if device == "cuda":
            return torch.cuda.memory_allocated() / torch.cuda.get_device_properties(0).total_memory
    except Exception:
        return None
    return None


def clean(text: str) -> str:
    """A text as one line with single spaces (what the models' own generate() does to it)."""
    return re.sub(r"\s+", " ", text.replace("\n", " ")).strip()


def trim_silence(numpy, audio, sr: int, threshold_db: float = -45.0, keep: float = 0.15):
    """Cut leading and trailing silence (20 ms frames below threshold_db of the peak), keeping `keep` s of it."""
    if audio.size == 0:
        return audio
    frame = max(1, int(sr * 0.02))
    count = len(audio) // frame
    if count == 0:
        return audio
    frames = audio[: count * frame].reshape(count, frame)
    rms = numpy.sqrt((frames.astype("float64") ** 2).mean(axis=1) + 1e-12)
    loud = numpy.nonzero(20 * numpy.log10(rms / (rms.max() + 1e-12)) > threshold_db)[0]
    if loud.size == 0:
        return audio
    pad = int(sr * keep)
    first = max(0, loud[0] * frame - pad)
    last = min(len(audio), (loud[-1] + 1) * frame + pad)
    return audio[first:last]


def excerpt(path: str, start, length, cache: str, read, write):
    """
    The reference cut to reference_start / reference_length seconds, written to a
    temporary WAV in cache (the caller removes it), or None when neither is set.
    read(path) -> (samples [channels, n], sample rate), write(path, samples, sr).
    """
    if start is None and length is None:
        return None
    wav, sr = read(path)
    total = wav.shape[-1]
    first = int(round(float(start or 0) * sr))
    last = total if length is None else min(total, first + int(round(float(length) * sr)))
    if last - first < sr // 10:
        raise ValueError(
            f"reference excerpt from {start or 0} s ({length if length is not None else 'to the end'})"
            f" is empty: the WAV is {total / sr:.1f} s long"
        )
    os.makedirs(cache, exist_ok=True)
    fd, part = tempfile.mkstemp(dir=cache, suffix=".wav")
    os.close(fd)
    write(part, wav[..., first:last], sr)
    return part
