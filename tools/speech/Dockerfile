# A speech worker for `phpolygon speech:sync` (runner "docker"): Chatterbox by default,
# VoxCPM2 with --build-arg WORKER=voxcpm_worker.py --build-arg REQUIREMENTS=requirements-voxcpm.txt.
# Built by `phpolygon speech:setup [--synth=…] --docker [--gpu]`; GPU = CUDA wheels, run with --gpus all.
FROM python:3.11-slim

# OpenMP runtime: torch's CPU wheels for arm64 do not bundle it. SYSTEM_PACKAGES adds what a
# model needs besides (VoxCPM2: gcc and libc headers for triton, the kernel compiler of torch.compile).
ARG SYSTEM_PACKAGES=""
RUN apt-get update && apt-get install -y --no-install-recommends libgomp1 ${SYSTEM_PACKAGES} && rm -rf /var/lib/apt/lists/*

ARG TORCH_INDEX=https://download.pytorch.org/whl/cpu
ARG TORCH_VERSION=2.6.0
RUN pip install --no-cache-dir torch==${TORCH_VERSION} torchaudio==${TORCH_VERSION} --index-url ${TORCH_INDEX}

ARG REQUIREMENTS=requirements.txt
COPY ${REQUIREMENTS} /opt/speech/requirements.txt
RUN pip install --no-cache-dir -r /opt/speech/requirements.txt

ARG WORKER=chatterbox_worker.py
COPY speech_worker.py /opt/speech/speech_worker.py
COPY ${WORKER} /opt/speech/worker.py

# Model weights live in a named volume, so a build never downloads them twice.
# The worker runs as the host user: every cache must be writable for any uid.
ENV HF_HOME=/models HOME=/models/home
RUN mkdir -p /models/home && chmod -R 777 /models
VOLUME /models

ENTRYPOINT ["python", "/opt/speech/worker.py"]
