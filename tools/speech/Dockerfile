# Chatterbox Multilingual for `phpolygon speech:sync` (runner "docker").
# Built by `phpolygon speech:setup --docker [--gpu]`; GPU = CUDA wheels, run with --gpus all.
FROM python:3.11-slim

# OpenMP runtime: torch's CPU wheels for arm64 do not bundle it.
RUN apt-get update && apt-get install -y --no-install-recommends libgomp1 && rm -rf /var/lib/apt/lists/*

ARG TORCH_INDEX=https://download.pytorch.org/whl/cpu
RUN pip install --no-cache-dir torch==2.6.0 torchaudio==2.6.0 --index-url ${TORCH_INDEX}

COPY requirements.txt /opt/speech/requirements.txt
RUN pip install --no-cache-dir -r /opt/speech/requirements.txt

COPY chatterbox_worker.py /opt/speech/chatterbox_worker.py

# Model weights live in a named volume, so a build never downloads them twice.
# The worker runs as the host user: every cache must be writable for any uid.
ENV HF_HOME=/models HOME=/models/home
RUN mkdir -p /models/home && chmod -R 777 /models
VOLUME /models

ENTRYPOINT ["python", "/opt/speech/chatterbox_worker.py"]
