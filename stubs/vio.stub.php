<?php

/**
 * PHPStan stubs for ext-vio (php-vio).
 */

class VioContext {}
class VioShader {}
class VioPipeline {}
class VioMesh {}
class VioBuffer {}
class VioTexture {}
class VioFont {}
class VioSound {}
class VioRenderTarget {}
class VioCubemap {}
class VioComputePipeline {}

// ----------------------------------------------------------------
// Constants
// ----------------------------------------------------------------

const VIO_SHADER_AUTO     = -1;
const VIO_SHADER_SPIRV    = 1;
const VIO_SHADER_GLSL     = 2;
const VIO_SHADER_GLSL_RAW = 0;
const VIO_SHADER_MSL      = 3;
const VIO_CULL_BACK = 1;
const VIO_CULL_FRONT = 2;
const VIO_CULL_NONE = 0;
const VIO_BLEND_NONE = 0;
const VIO_BLEND_ALPHA = 1;
const VIO_BLEND_ADDITIVE = 2;
const VIO_DEPTH_LEQUAL = 1;
const VIO_DEPTH_LESS = 0;
const VIO_FLOAT2 = 2;
const VIO_FLOAT3 = 3;
const VIO_CURSOR_NORMAL = 0;
const VIO_CURSOR_DISABLED = 1;

const VIO_FILTER_NEAREST = 0;
const VIO_FILTER_LINEAR  = 1;
const VIO_WRAP_REPEAT = 0;
const VIO_WRAP_CLAMP  = 1;
const VIO_WRAP_MIRROR = 2;

const VIO_FEATURE_TEXTURE_3D = 22;

// ----------------------------------------------------------------
// Backend info
// ----------------------------------------------------------------

function vio_backend_name(VioContext $ctx): string {}

function vio_supports_feature(VioContext $ctx, int $feature): bool {}

/** @return list<string> */
function vio_backends(): array {}

/**
 * Host thermal state. macOS / iOS read NSProcessInfo.thermalState
 * and return one of "nominal", "fair", "serious", "critical";
 * every other host returns "unknown". The string return type (not a
 * literal union) lets the caller's tryFrom() fallback stay defensive
 * in case future vio builds add new states.
 */
function vio_thermal_state(): string {}

// ----------------------------------------------------------------
// Cursor
// ----------------------------------------------------------------

function vio_set_cursor_mode(VioContext $ctx, int $mode): void {}

// ----------------------------------------------------------------
// Context lifecycle
// ----------------------------------------------------------------

/**
 * @param array<string, mixed> $config
 * @return VioContext|false
 */
function vio_create(string $backend, array $config): VioContext|false {}

function vio_destroy(VioContext $ctx): void {}

function vio_begin(VioContext $ctx): void {}

function vio_end(VioContext $ctx): void {}

function vio_clear(VioContext $ctx, float $r, float $g, float $b, float $a): void {}

function vio_draw_2d(VioContext $ctx): void {}

function vio_draw_3d(VioContext $ctx): void {}

// ----------------------------------------------------------------
// Window
// ----------------------------------------------------------------

/** Returns the native window handle as an integer pointer (HWND on Windows,
 *  NSWindow* on macOS, X11 Window XID on Linux). Used by Vulkan / Metal / D3D
 *  surface creation paths that need the OS-level handle. */
function vio_native_window_handle(VioContext $ctx): int {}

/** @return array{int, int} */
function vio_window_size(VioContext $ctx): array {}

/** @return array{int, int} */
function vio_framebuffer_size(VioContext $ctx): array {}

/** @return array{float, float} */
function vio_content_scale(VioContext $ctx): array {}

function vio_pixel_ratio(VioContext $ctx): float {}

function vio_should_close(VioContext $ctx): bool {}

function vio_close(VioContext $ctx): void {}

function vio_poll_events(VioContext $ctx): void {}

function vio_set_title(VioContext $ctx, string $title): void {}

function vio_set_fullscreen(VioContext $ctx): void {}

function vio_set_borderless(VioContext $ctx): void {}

function vio_set_windowed(VioContext $ctx): void {}

function vio_set_window_size(VioContext $ctx, int $width, int $height): void {}

function vio_viewport(VioContext $ctx, int $x, int $y, int $width, int $height): void {}

// ----------------------------------------------------------------
// Input
// ----------------------------------------------------------------

/** @return array{float, float} */
function vio_mouse_position(VioContext $ctx): array {}

/** @return array{float, float} */
function vio_mouse_scroll(VioContext $ctx): array {}

function vio_mouse_button(VioContext $ctx, int $button): bool {}

function vio_key_pressed(VioContext $ctx, int $key): bool {}

/** @param callable(int, int, int): void $callback */
function vio_on_key(VioContext $ctx, callable $callback): void {}

/** @param callable(int): void $callback */
function vio_on_char(VioContext $ctx, callable $callback): void {}

/** On-screen-keyboard backspaces since the last call (iOS; 0 on desktop). */
function vio_ime_backspaces(VioContext $ctx): int {}

/** Show the on-screen keyboard (iOS; no-op on desktop). */
function vio_keyboard_show(VioContext $ctx): void {}

/** Hide the on-screen keyboard (iOS; no-op on desktop). */
function vio_keyboard_hide(VioContext $ctx): void {}

// ----------------------------------------------------------------
// 3D: Shaders, pipelines, meshes
// ----------------------------------------------------------------

/**
 * @param array<string, mixed> $desc
 * @return VioShader|false
 */
function vio_shader(VioContext $ctx, array $desc): VioShader|false {}

/**
 * @param array<string, mixed> $desc
 * @return VioPipeline|false
 */
function vio_pipeline(VioContext $ctx, array $desc): VioPipeline|false {}

/**
 * @param array<string, mixed> $desc
 * @return VioMesh|false
 */
function vio_mesh(VioContext $ctx, array $desc): VioMesh|false {}

function vio_bind_pipeline(VioContext $ctx, VioPipeline $pipeline): void {}

function vio_set_uniform(VioContext $ctx, string $name, int|float|array $value): void {}

function vio_draw(VioContext $ctx, VioMesh $mesh): void {}

/**
 * Draw a mesh multiple times using GPU instancing.
 * @param float[]|string $matrices Flat array of 4x4 model matrices (16 floats per instance) or packed binary string
 */
function vio_draw_instanced(VioContext $ctx, VioMesh $mesh, array|string $matrices, int $instanceCount): void {}

// ----------------------------------------------------------------
// Textures
// ----------------------------------------------------------------

/**
 * @param array<string, mixed> $desc
 * @return VioTexture|false
 */
function vio_texture(VioContext $ctx, array $desc): VioTexture|false {}

function vio_texture_3d(VioContext $ctx, array $desc): VioTexture|false {}

/** @return array{int, int} */
function vio_texture_size(VioTexture $tex): array {}

/**
 * Bind a texture to a sampler unit for 3D rendering.
 */
function vio_bind_texture(VioContext $ctx, VioTexture $texture, int $unit): void {}

// ----------------------------------------------------------------
// Cubemaps
// ----------------------------------------------------------------

/**
 * Load a cubemap from 6 face images or raw pixel data.
 *
 * File-based: $config = ['faces' => string[6]] (paths in +X,-X,+Y,-Y,+Z,-Z order)
 * Procedural: $config = ['pixels' => int[6][], 'width' => int, 'height' => int] (RGBA bytes per face)
 *
 * @param array<string, mixed> $config
 * @return VioCubemap|false
 */
function vio_cubemap(VioContext $ctx, array $config): VioCubemap|false {}

/**
 * Bind a cubemap to a sampler unit for 3D rendering.
 */
function vio_bind_cubemap(VioContext $ctx, VioCubemap $cubemap, int $unit): void {}

// ----------------------------------------------------------------
// Render targets (offscreen FBO)
// ----------------------------------------------------------------

/**
 * @param array<string, mixed> $config Keys: width, height, depth_only (bool)
 * @return VioRenderTarget|false
 */
function vio_render_target(VioContext $ctx, array $config): VioRenderTarget|false {}

function vio_bind_render_target(VioContext $ctx, VioRenderTarget $target): void {}

function vio_unbind_render_target(VioContext $ctx): void {}

/**
 * Get the depth or color texture from a render target for sampling.
 */
function vio_render_target_texture(VioRenderTarget $target): VioTexture {}

// ----------------------------------------------------------------
// 2D drawing
// ----------------------------------------------------------------

/** @param array<string, mixed> $options */
function vio_rect(VioContext $ctx, float $x, float $y, float $w, float $h, array $options = []): void {}

/** @param array<string, mixed> $options */
function vio_rounded_rect(VioContext $ctx, float $x, float $y, float $w, float $h, float $radius, array $options = []): void {}

/** @param array<string, mixed> $options */
function vio_circle(VioContext $ctx, float $cx, float $cy, float $r, array $options = []): void {}

/** @param array<string, mixed> $options */
function vio_line(VioContext $ctx, float $x1, float $y1, float $x2, float $y2, array $options = []): void {}

/** @param array<string, mixed> $options */
function vio_sprite(VioContext $ctx, VioTexture $tex, array $options = []): void {}

// ----------------------------------------------------------------
// 2D transforms and clipping
// ----------------------------------------------------------------

function vio_push_transform(VioContext $ctx, float $a, float $b, float $c, float $d, float $e, float $f): void {}

function vio_pop_transform(VioContext $ctx): void {}

function vio_push_scissor(VioContext $ctx, float $x, float $y, float $w, float $h): void {}

function vio_pop_scissor(VioContext $ctx): void {}

// ----------------------------------------------------------------
// Fonts and text
// ----------------------------------------------------------------

/**
 * @param array<string, mixed> $options
 * @return VioFont|false
 */
function vio_font(VioContext $ctx, string $path, float $size, array $options = []): VioFont|false {}

/**
 * @param array<string, mixed> $options
 * @return array{width: float, height: float}
 */
function vio_text_measure(VioFont $font, string $text, array $options = []): array {}

/** @param array<string, mixed> $options */
function vio_text(VioContext $ctx, VioFont $font, string $text, float $x, float $y, array $options = []): void {}

// ----------------------------------------------------------------
// Audio
// ----------------------------------------------------------------

/** @return VioSound|false */
function vio_audio_load(string $path): VioSound|false {}

/** @param array<string, mixed> $options Keys: volume (float), loop (bool) */
function vio_audio_play(VioSound $sound, array $options = []): void {}

function vio_audio_stop(VioSound $sound): void {}

function vio_audio_volume(VioSound $sound, float $volume): void {}

function vio_audio_playing(VioSound $sound): bool {}

// ----------------------------------------------------------------
// Framebuffer readback
// ----------------------------------------------------------------

/**
 * Read all pixels from the current framebuffer as RGBA bytes.
 * @return string Raw RGBA pixel data (4 bytes per pixel)
 */
function vio_read_pixels(VioContext $ctx): string {}

// ----------------------------------------------------------------
// Async texture loading
// ----------------------------------------------------------------

/**
 * @param array<string, mixed> $options
 * @return int Thread handle
 */
function vio_texture_load_async(VioContext $ctx, string $path, array $options = []): int {}

/**
 * @return VioTexture|null Returns texture when loaded, null while still loading
 */
function vio_texture_load_poll(VioContext $ctx, int $handle): ?VioTexture {}

// ----------------------------------------------------------------
// Async font loading
// ----------------------------------------------------------------

/**
 * Start loading a TTF/OTF font on a background worker thread. The glyph-atlas
 * rasterization runs off the render thread; the GPU upload is deferred to
 * vio_font_load_poll().
 *
 * @return resource|false Async load handle, or false on failure
 */
function vio_font_load_async(VioContext $ctx, string $path, float $size = 24.0): mixed {}

/**
 * Poll an async font load. Returns null while still loading, false on failure,
 * or a ready-to-use VioFont once the worker has finished (the atlas is uploaded
 * to the GPU inside this call, so it must run on the render thread).
 *
 * @param resource $handle Handle from vio_font_load_async()
 * @return VioFont|null|false
 */
function vio_font_load_poll($handle): VioFont|null|false {}

// ----------------------------------------------------------------
// GPU compute + batch uniforms (php-vio >= 2.1; feature-gated / fallback-guarded
// on the engine side, so older builds without these still work).
// ----------------------------------------------------------------

const VIO_COMPUTE_READ = 0;
const VIO_COMPUTE_WRITE = 1;

/** @param array<string,mixed> $config */
function vio_compute_pipeline(VioContext $context, array $config): VioComputePipeline|false {}

/** @param array<string,mixed> $config */
function vio_storage_buffer(VioContext $context, array $config): VioBuffer|false {}

function vio_compute_bind_buffer(VioContext $context, VioComputePipeline $pipeline, VioBuffer $buffer, int $slot, int $access): void {}

function vio_compute_set_uniforms(VioContext $context, VioComputePipeline $pipeline, string $data): void {}

function vio_compute_dispatch(VioContext $context, VioComputePipeline $pipeline, int $gx, int $gy, int $gz): void {}

function vio_storage_buffer_read(VioContext $context, VioBuffer $buffer): string|false {}

/**
 * Batch form of vio_set_uniform — apply a map of ['u_name' => value, ...] in one
 * native call.
 *
 * @param array<string, int|float|array<float>> $uniforms
 */
function vio_set_uniforms(VioContext $context, array $uniforms): void {}
