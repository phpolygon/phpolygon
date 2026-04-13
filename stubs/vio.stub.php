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

// ----------------------------------------------------------------
// Constants
// ----------------------------------------------------------------

const VIO_SHADER_GLSL_RAW = 0;
const VIO_CULL_BACK = 1;
const VIO_CULL_FRONT = 2;
const VIO_CULL_NONE = 0;
const VIO_BLEND_NONE = 0;
const VIO_BLEND_ALPHA = 1;
const VIO_DEPTH_LEQUAL = 1;
const VIO_DEPTH_LESS = 0;

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
 * @param float[] $matrices Flat array of 4x4 model matrices (16 floats per instance)
 */
function vio_draw_instanced(VioContext $ctx, VioMesh $mesh, array $matrices, int $instanceCount): void {}

// ----------------------------------------------------------------
// Textures
// ----------------------------------------------------------------

/**
 * @param array<string, mixed> $desc
 * @return VioTexture|false
 */
function vio_texture(VioContext $ctx, array $desc): VioTexture|false {}

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
