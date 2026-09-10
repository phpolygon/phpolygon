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
const VIO_BLEND_PREMULTIPLIED = 3;
const VIO_BLEND_MULTIPLY = 4;
const VIO_BLEND_SCREEN = 5;
const VIO_BLEND_MIN = 6;
const VIO_BLEND_MAX = 7;
const VIO_COLOR_R = 1;
const VIO_COLOR_G = 2;
const VIO_COLOR_B = 4;
const VIO_COLOR_A = 8;
const VIO_COLOR_RGB = 7;
const VIO_COLOR_RGBA = 15;

// Stencil state for vio_pipeline(['stencil' => [...]]) (php-vio >= 2.12)
const VIO_CMP_NEVER = 0;
const VIO_CMP_LESS = 1;
const VIO_CMP_EQUAL = 2;
const VIO_CMP_LEQUAL = 3;
const VIO_CMP_GREATER = 4;
const VIO_CMP_NOTEQUAL = 5;
const VIO_CMP_GEQUAL = 6;
const VIO_CMP_ALWAYS = 7;
const VIO_STENCIL_KEEP = 0;
const VIO_STENCIL_ZERO = 1;
const VIO_STENCIL_REPLACE = 2;
const VIO_STENCIL_INCR = 3;
const VIO_STENCIL_DECR = 4;
const VIO_STENCIL_INVERT = 5;
const VIO_STENCIL_INCR_WRAP = 6;
const VIO_STENCIL_DECR_WRAP = 7;
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

// VIO_FEATURE_* — mirrors php-vio's vio_feature enum (include/vio_types.h).
const VIO_FEATURE_COMPUTE = 0;
const VIO_FEATURE_RAYTRACING = 1;
const VIO_FEATURE_TESSELLATION = 2;
const VIO_FEATURE_GEOMETRY = 3;
const VIO_FEATURE_MULTIVIEW = 4;
const VIO_FEATURE_3D_PIPELINE = 5;
const VIO_FEATURE_READ_PIXELS = 6;
const VIO_FEATURE_INSTANCED_DRAW = 7;
const VIO_FEATURE_RENDER_TARGET = 8;
const VIO_FEATURE_RENDER_TARGET_HDR = 9;
const VIO_FEATURE_RENDER_TARGET_DEPTH = 10;
const VIO_FEATURE_RENDER_TARGET_MSAA = 11;
const VIO_FEATURE_CUBEMAP = 12;
const VIO_FEATURE_DEPTH_BIAS = 13;
const VIO_FEATURE_SCISSOR = 14;
const VIO_FEATURE_TEXTURE_SWIZZLE = 15;
const VIO_FEATURE_NATIVE_2D_BATCH = 16;
const VIO_FEATURE_DEBUG_OUTPUT = 17;
const VIO_FEATURE_DSA = 18;
const VIO_FEATURE_BUFFER_STORAGE = 19;
const VIO_FEATURE_TEXTURE_STORAGE = 20;
const VIO_FEATURE_SEPARATE_SHADERS = 21;
const VIO_FEATURE_TEXTURE_3D = 22;
const VIO_FEATURE_RENDER_TARGET_CUBE = 23;
const VIO_FEATURE_MIPMAP_GEN = 24;
const VIO_FEATURE_MRT = 25;
const VIO_FEATURE_STORAGE_IMAGE = 26;
const VIO_FEATURE_VERTEX_STORAGE = 30;
// php-vio >= 2.12 (GAP-PHASE5)
const VIO_FEATURE_STENCIL = 31;
const VIO_FEATURE_GPU_TIMESTAMP = 32;
const VIO_FEATURE_FRAME_LATENCY = 33;
const VIO_FEATURE_HDR_OUTPUT = 34;
const VIO_FEATURE_INDIRECT_DRAW = 35;
const VIO_FEATURE_TEXTURE_ARRAY = 36;
const VIO_FEATURE_TEXTURE_COMPRESSION_BC = 37;
const VIO_FEATURE_SHADING_RATE = 38;
// vio_set_shading_rate() rates (php-vio >= 2.19)
const VIO_SHADING_RATE_1X1 = 0;
const VIO_SHADING_RATE_1X2 = 1;
const VIO_SHADING_RATE_2X1 = 2;
const VIO_SHADING_RATE_2X2 = 3;
const VIO_SHADING_RATE_4X4 = 4;

// Render-target colour attachment formats (vio_render_target 'attachments', vio_pipeline 'attachments')
const VIO_FORMAT_RGBA8      = 0;
const VIO_FORMAT_RGBA16F    = 1;
const VIO_FORMAT_RGBA32F    = 2;
const VIO_FORMAT_R11G11B10F = 3;
const VIO_FORMAT_RG16F      = 4;
const VIO_FORMAT_R16F       = 5;
const VIO_FORMAT_R32F       = 6;
const VIO_FORMAT_R8         = 7;
const VIO_FORMAT_RGB10A2    = 8; // HDR10 backbuffer / render target (php-vio >= 2.15)
// Block-compressed texture data for vio_texture(['format' => …]) / KTX2 (php-vio >= 2.18); not render-target formats
const VIO_FORMAT_BC1        = 9;
const VIO_FORMAT_BC3        = 10;
const VIO_FORMAT_BC4        = 11;
const VIO_FORMAT_BC5        = 12;
const VIO_FORMAT_BC7        = 13;

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
function vio_texture_update(VioContext $ctx, VioTexture $tex, string $data, int $x = 0, int $y = 0, int $width = 0, int $height = 0): bool {}

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

function vio_bind_render_target(VioContext $ctx, VioRenderTarget $target, int $face = -1, int $level = 0): void {}
function vio_render_target_cubemap(VioRenderTarget $target): VioCubemap|false {}
function vio_read_render_target(VioRenderTarget $target, int $face = -1, int $attachment = 0): string|false {}
function vio_generate_mipmaps(VioContext $ctx, VioRenderTarget|VioTexture|VioCubemap $object): bool {}

function vio_unbind_render_target(VioContext $ctx): void {}

/**
 * Get the depth or color texture from a render target for sampling ($attachment = MRT index).
 */
function vio_render_target_texture(VioRenderTarget $target, int $attachment = 0): VioTexture {}

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
 * Atlas rasterized at $size * $scale physical px; metrics reported in logical
 * $size units (devicePixelRatio). $scale clamped to >= 1.0.
 *
 * @return VioFont|false
 */
function vio_font(VioContext $ctx, string $path, float $size = 24.0, float $scale = 1.0): VioFont|false {}

function vio_font_has_glyph(VioFont $font, int $codepoint): bool {}

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
function vio_font_load_async(VioContext $ctx, string $path, float $size = 24.0, float $scale = 1.0): mixed {}

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

/** Bind a storage image (VioTexture created with 'storage' => true) — GLSL image2D/image3D at binding $slot. */
function vio_compute_bind_image(VioContext $context, VioComputePipeline $pipeline, VioTexture $texture, int $slot, int $access): void {}

function vio_compute_set_uniforms(VioContext $context, VioComputePipeline $pipeline, string $data): void {}

/** @param array<string,mixed>|null $options ['async' => bool] — record into the open frame instead of blocking */
function vio_compute_dispatch(VioContext $context, VioComputePipeline $pipeline, int $gx, int $gy, int $gz, ?array $options = null): void {}
function vio_compute_wait(VioContext $context): void {}

function vio_storage_buffer_read(VioContext $context, VioBuffer $buffer): string|false {}

/**
 * Bind a compute-written storage buffer to the GRAPHICS pipeline so the vertex
 * stage reads per-instance data via gl_InstanceIndex — no GPU->CPU readback.
 * Requires VIO_FEATURE_VERTEX_STORAGE; no-op otherwise. Call between
 * vio_bind_pipeline and vio_draw_instanced_from_buffer.
 */
function vio_bind_storage_buffer(VioContext $context, VioBuffer $buffer, int $binding, int $access): void {}

/**
 * Instanced draw whose per-instance data comes from a storage buffer bound via
 * vio_bind_storage_buffer, not a CPU buffer. Eliminates the readback that
 * vio_draw_instanced + vio_storage_buffer_read would need. Requires
 * VIO_FEATURE_VERTEX_STORAGE; no-op otherwise.
 */
function vio_draw_instanced_from_buffer(VioContext $context, VioMesh $mesh, int $instanceCount): void {}

/**
 * Batch form of vio_set_uniform — apply a map of ['u_name' => value, ...] in one
 * native call.
 *
 * @param array<string, int|float|array<float>> $uniforms
 */
function vio_set_uniforms(VioContext $context, array $uniforms): void {}

/** php-vio >= 2.13: index width of a mesh (2 = uint16, 4 = uint32, 0 = unindexed). */
function vio_mesh_index_bytes(VioMesh $mesh): int {}

/**
 * php-vio >= 2.13 (VIO_FEATURE_GPU_TIMESTAMP): GPU time in ms of the most
 * recently completed frame, -1.0 when none is available yet / unsupported.
 */
function vio_gpu_frame_time(VioContext $context): float {}

/**
 * php-vio >= 2.14: on-disk shader cache statistics for vio_create(['shader_cache' => dir]).
 *
 * @return array{dir: string, hits: int, misses: int, stores: int}
 */
function vio_shader_cache_stats(VioContext $context): array {}

/**
 * php-vio >= 2.14: swapchain facts (buffer count, frame latency, waitable object,
 * HDR output, backbuffer format, shader model).
 *
 * @return array{buffer_count: int, frame_latency: int, waitable: bool, hdr_output: bool, format: int, shader_model: int}
 */
function vio_swapchain_info(VioContext $context): array {}

/**
 * php-vio >= 2.17 (VIO_FEATURE_INDIRECT_DRAW): draw $mesh with arguments read
 * from a storage buffer created with vio_storage_buffer(['indirect' => true]) —
 * 5 uint32 {indexCount, instanceCount, firstIndex, baseVertex, firstInstance}
 * per indexed draw (4 for unindexed meshes); $maxDraws consecutive records from
 * byte $offset. Per-instance data comes from vio_bind_storage_buffer().
 */
function vio_draw_indirect(VioContext $context, VioMesh $mesh, VioBuffer $args, int $maxDraws = 1, int $offset = 0): void {}

/**
 * php-vio >= 2.18: texture from a KTX2 container in memory (2D / 2D array; R8, RGBA8,
 * BC1 / BC3 / BC4 / BC5 / BC7; no supercompression), stored mip chain uploaded as-is.
 *
 * @param array{mip_offset?: int, filter?: int, wrap?: int, anisotropy?: int, mipmaps?: bool}|null $options
 */
function vio_texture_ktx2(VioContext $context, string $bytes, ?array $options = null): VioTexture|false {}

/**
 * php-vio >= 2.19 (VIO_FEATURE_SHADING_RATE): variable rate shading for every following
 * draw, sticky until changed; false when the backend has no VRS or the rate is not offered.
 */
function vio_set_shading_rate(VioContext $context, int $rate): bool {}
