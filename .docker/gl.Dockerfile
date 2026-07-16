# OpenGL version-matrix image.
#
# Runs the standalone (php-glfw) OpenGL 3D backend headlessly via Mesa's
# llvmpipe software rasteriser under Xvfb. The reported GL version is forced
# per rung at RUN time with MESA_GL_VERSION_OVERRIDE (see .docker/gl-matrix.sh),
# so a single image validates every ladder step from GL 3.0 to 4.6:
# context creation, GLSL #version injection, shader compilation and rendering.
#
# Mesa's llvmpipe reports ~4.5 natively; the override can pin it down to 3.0.
# Proven mechanic (glxinfo): override 3.0/3.1/3.3/4.1/4.6 -> exact version.
#
# Build:  docker build -f .docker/gl.Dockerfile -t phpolygon-gl .
# Run:    docker run --rm -v "$(pwd)":/app -w /app phpolygon-gl .docker/gl-matrix.sh

FROM debian:bookworm-slim

# PHP 8.5 (engine requires >=8.5) from the sury repository.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl gnupg lsb-release \
    && curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/sury.gpg] https://packages.sury.org/php $(lsb_release -sc) main" \
        > /etc/apt/sources.list.d/sury-php.list \
    && apt-get update && apt-get install -y --no-install-recommends \
        php8.5-cli php8.5-dev php8.5-gd php8.5-mbstring php8.5-xml \
        php8.5-zip php8.5-tokenizer \
    # Build toolchain for the php-glfw C extension (+ its bundled GLFW via cmake).
    # php-glfw's config.m4 shells out to `sudo make install` for the bundled
    # GLFW; provide sudo even though we build as root.
        build-essential cmake git pkg-config autoconf unzip sudo \
    # GL + X11 development headers GLFW links against.
        libgl1-mesa-dev libglu1-mesa-dev xorg-dev libxkbcommon-dev \
        libfreetype6-dev \
    # Mesa software rendering + virtual display runtime.
        libgl1-mesa-dri libglx-mesa0 mesa-utils xvfb xauth \
    && rm -rf /var/lib/apt/lists/*

# Build + install php-glfw against PHP 8.5.
RUN git clone --depth 1 --recursive https://github.com/mario-deluna/php-glfw /tmp/php-glfw \
    && cd /tmp/php-glfw \
    && phpize \
    && ./configure --with-php-config=/usr/bin/php-config8.5 \
    && make -j"$(nproc)" \
    && make install \
    && echo "extension=glfw.so" > /etc/php/8.5/cli/conf.d/20-glfw.ini \
    && rm -rf /tmp/php-glfw

# Composer.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Software rendering by default; the matrix runner overrides the GL version.
ENV LIBGL_ALWAYS_SOFTWARE=1
ENV GALLIUM_DRIVER=llvmpipe

WORKDIR /app
