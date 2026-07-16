# PHPolygon VRT with the real php-vio backend, GPU-free.
#
# vio is the engine's primary backend, so the VRT suite should exercise it — not
# just the GD software rasteriser (.docker/vrt.Dockerfile) or the standalone
# php-glfw GL matrix (.docker/gl.Dockerfile). This image builds php-vio with its
# Vulkan + OpenGL backends and runs headlessly under Xvfb on Mesa's software
# implementations: lavapipe (Vulkan) and llvmpipe (OpenGL). No GPU required.
#
# On Linux vio_create('auto') selects Vulkan first → lavapipe, whose 3D pipeline
# is fully wired, so VioRenderer3D::renderToImage() renders real geometry that
# vio_read_pixels() reads back — the CI-friendly native-backend 3D pixel VRT.
#
# Build:  docker build -f .docker/vrt-vio.Dockerfile -t phpolygon-vrt-vio .
# Run:    docker run --rm -v "$(pwd)":/app -w /app phpolygon-vrt-vio \
#           .docker/vrt-vio-run.sh

# ubuntu:24.04 matches php-vio's own build.yml environment (glslang / spirv-cross
# / vulkan package layout), which avoids the glslang link mismatch seen on Debian.
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

# PHP 8.5 (engine requires >=8.5) from the ondrej/php PPA (the sury feed for Ubuntu).
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates software-properties-common \
    && add-apt-repository -y ppa:ondrej/php \
    && apt-get update && apt-get install -y --no-install-recommends \
        php8.5-cli php8.5-dev php8.5-gd php8.5-mbstring php8.5-xml \
        php8.5-zip php8.5-tokenizer \
    # php-vio build toolchain + its libraries (mirrors php-vio's own build.yml).
        build-essential cmake git pkg-config autoconf unzip \
        libglfw3-dev glslang-dev libvulkan-dev \
        libavcodec-dev libavformat-dev libavutil-dev libswscale-dev \
        spirv-cross libspirv-cross-c-shared-dev libharfbuzz-dev \
    # X11 dev headers GLFW's native access needs (glfw3native.h → Xrandr etc.).
        xorg-dev libxkbcommon-dev \
    # Headless software rendering: Vulkan (lavapipe) + OpenGL (llvmpipe) + Xvfb.
        mesa-vulkan-drivers libgl1-mesa-dri libglx-mesa0 mesa-utils \
        vulkan-tools xvfb xauth \
    && rm -rf /var/lib/apt/lists/*

# Build + install php-vio (Vulkan + OpenGL backends; Metal/D3D are macOS/Windows).
RUN git clone --depth 1 https://github.com/phpolygon/php-vio /tmp/php-vio \
    && cd /tmp/php-vio \
    && phpize \
    && ./configure --enable-vio \
        --with-glfw --with-glslang --with-spirv-cross --with-vulkan \
        --with-ffmpeg --with-harfbuzz \
    && make -j"$(nproc)" \
    && make install \
    && echo "extension=vio.so" > /etc/php/8.5/cli/conf.d/20-vio.ini \
    && rm -rf /tmp/php-vio

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Force software rendering; the Vulkan loader auto-discovers lavapipe's ICD.
ENV LIBGL_ALWAYS_SOFTWARE=1
ENV GALLIUM_DRIVER=llvmpipe

WORKDIR /app
