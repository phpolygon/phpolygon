"""Chatterbox Multilingual worker for `phpolygon speech:sync`.

Reads a JSONL job file ({id, text, language, reference, out, seed, exaggeration,
cfg_weight}), loads the model once and writes one WAV per job. Prints one JSON
line per finished job to stdout ({"id", "ok"} or {"id", "error"}); everything
else goes to stderr.
"""
import argparse
import json
import sys
import time


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--jobs", required=True)
    parser.add_argument("--device", default="auto", choices=["auto", "cuda", "mps", "cpu"])
    args = parser.parse_args()

    # Libraries print to stdout; keep it for the protocol.
    protocol = sys.stdout
    sys.stdout = sys.stderr

    def emit(result: dict) -> None:
        protocol.write(json.dumps(result) + "\n")
        protocol.flush()

    with open(args.jobs, encoding="utf-8") as f:
        jobs = [json.loads(line) for line in f if line.strip()]

    import torch
    import torchaudio
    from chatterbox.mtl_tts import ChatterboxMultilingualTTS

    device = args.device
    if device == "auto":
        device = "cuda" if torch.cuda.is_available() else "mps" if torch.backends.mps.is_available() else "cpu"
    print(f"chatterbox: {len(jobs)} line(s) on {device}", file=sys.stderr, flush=True)
    model = ChatterboxMultilingualTTS.from_pretrained(device=device)

    reference = None
    for job in jobs:
        start = time.time()
        try:
            if job["reference"] != reference:
                model.prepare_conditionals(job["reference"], exaggeration=job["exaggeration"])
                reference = job["reference"]
            torch.manual_seed(job["seed"])
            wav = model.generate(
                job["text"],
                language_id=job["language"],
                exaggeration=job["exaggeration"],
                cfg_weight=job["cfg_weight"],
            )
            torchaudio.save(job["out"], wav.cpu(), model.sr)
            emit({"id": job["id"], "ok": True, "seconds": round(time.time() - start, 1)})
        except Exception as e:  # one bad line must not stop the run
            emit({"id": job["id"], "error": f"{type(e).__name__}: {e}"})
    return 0


if __name__ == "__main__":
    sys.exit(main())
