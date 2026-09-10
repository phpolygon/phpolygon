<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Runtime\PerfProfiler;
use RuntimeException;
use VioContext;
use VioTexture;

class VioTextureManager extends TextureManager
{
    /** @var array<string, Texture> */
    private array $vioManagedTextures = [];

    /** @var array<string, VioTexture> */
    private array $vioTextureObjects = [];

    private string $vioBasePath;
    private int $nextId = 1;
    private ?VioRenderer2D $renderer = null;

    /** Anisotropic filtering level (1..16) applied to textures loaded from now on; follows GraphicsSettings. */
    private int $anisotropy = 4;

    /**
     * Mip levels dropped from KTX2 textures loaded from now on (TextureQuality:
     * Full 0, Half 1, Quarter 2). A pre-built chain lets the tier cut upload
     * size and VRAM at the source instead of only biasing the sampler.
     */
    private int $ktx2MipOffset = 0;

    /** Whether this context can take KTX2 containers (php-vio >= 2.18); probed once. */
    private ?bool $ktx2Available = null;

    public function __construct(
        private readonly VioContext $ctx,
        string $basePath = '',
    ) {
        parent::__construct($basePath);
        $this->vioBasePath = rtrim($basePath, '/');
    }

    public function setRenderer(VioRenderer2D $renderer): void
    {
        $this->renderer = $renderer;
    }

    public function load(string $id, ?string $path = null): Texture
    {
        if (isset($this->vioManagedTextures[$id])) {
            return $this->vioManagedTextures[$id];
        }

        $filePath = $path ?? ($this->vioBasePath !== '' ? $this->vioBasePath . '/' . $id : $id);

        if (!file_exists($filePath)) {
            throw new RuntimeException("Texture file not found: {$filePath}");
        }

        PerfProfiler::begin('texture.upload');
        try {
            // A .ktx2 sibling wins over the source image: it carries the finished
            // mip chain (no driver-side generation) and usually BC-compressed
            // pixels, so the upload is a fraction of the PNG's and the
            // texture-quality tier can drop whole levels. Falls back to the
            // image when the container or its format is not supported here.
            $ktx2 = $this->ktx2Sibling($filePath);
            if ($ktx2 !== null) {
                $vioTex = $this->loadKtx2($ktx2);
                if ($vioTex !== null) {
                    return $this->register($id, $vioTex, $ktx2);
                }
            }

            // Mip chain + anisotropic filtering: without mips a texture seen at
            // a distance (sign plates, nameplates, decals) aliases into a
            // shimmer as the sampler skips across texels; the driver's mip
            // selection plus anisotropy keeps oblique views crisp instead of
            // smeared. Both keys are honoured from php-vio 2.9 on (mip chains
            // on D3D12 from 2.10) and are silently ignored by older builds,
            // which then behave exactly as before.
            $vioTex = vio_texture($this->ctx, [
                'file' => $filePath,
                'mipmaps' => true,
                'anisotropy' => $this->anisotropy,
            ]);
            if ($vioTex === false) {
                throw new RuntimeException("Failed to load texture via vio: {$filePath}");
            }

            return $this->register($id, $vioTex, $filePath);
        } finally {
            PerfProfiler::end();
        }
    }

    private function register(string $id, VioTexture $vioTex, string $filePath): Texture
    {
        $textureId = $this->nextId++;
        $this->vioTextureObjects[$id] = $vioTex;

        $size = function_exists('vio_texture_size') ? vio_texture_size($vioTex) : [0, 0];
        $texture = new Texture($textureId, $size[0], $size[1], $filePath);
        $this->vioManagedTextures[$id] = $texture;

        if ($this->renderer !== null) {
            $this->renderer->registerVioTexture($textureId, $vioTex);
        }

        return $texture;
    }

    /**
     * The .ktx2 file that stands in for an image path (same directory and stem),
     * or null when there is none. A path that already names a .ktx2 is returned
     * as is.
     */
    public static function ktx2Sibling(string $imagePath): ?string
    {
        if (preg_match('/\.ktx2$/i', $imagePath) === 1) {
            return is_file($imagePath) ? $imagePath : null;
        }
        $candidate = preg_replace('/\.(png|jpe?g|tga|bmp|gif|psd)$/i', '.ktx2', $imagePath, 1, $replaced);
        if ($candidate === null || $replaced !== 1 || !is_file($candidate)) {
            return null;
        }
        return $candidate;
    }

    /** True when the runtime can create textures from KTX2 containers (php-vio >= 2.18). */
    public function ktx2Available(): bool
    {
        return $this->ktx2Available ??= function_exists('vio_texture_ktx2');
    }

    /**
     * Upload a KTX2 container with the current tier's mip offset. Null when the
     * runtime cannot take it (older php-vio, unsupported format or backend), so
     * the caller falls back to the source image; a warning from php-vio is
     * swallowed here because the fallback is the intended answer.
     */
    private function loadKtx2(string $path): ?VioTexture
    {
        if (!$this->ktx2Available()) {
            return null;
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return null;
        }
        $tex = @vio_texture_ktx2($this->ctx, $bytes, [
            'filter' => VIO_FILTER_LINEAR,
            'wrap' => VIO_WRAP_REPEAT,
            'anisotropy' => $this->anisotropy,
            'mip_offset' => $this->ktx2MipOffset,
            'mipmaps' => true,
        ]);
        return $tex === false ? null : $tex;
    }

    public function get(string $id): ?Texture
    {
        return $this->vioManagedTextures[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->vioManagedTextures[$id]);
    }

    public function unload(string $id): void
    {
        unset($this->vioTextureObjects[$id]);
        unset($this->vioManagedTextures[$id]);
    }

    public function clear(): void
    {
        $this->vioTextureObjects = [];
        $this->vioManagedTextures = [];
    }

    public function setBasePath(string $path): void
    {
        $this->vioBasePath = rtrim($path, '/');
        parent::setBasePath($path);
    }

    /**
     * Apply graphics settings to the vio sampler state.
     *
     * Vio handles the sampler internally; the only knob exposed at PHP level
     * is the optional vio_set_default_anisotropy() helper (where it exists).
     * The base TextureManager already stores the values for any future
     * texture-resize work, so we delegate first and then attempt the vio call.
     */
    public function applySettings(GraphicsSettings $settings): void
    {
        parent::applySettings($settings);

        // Textures loaded from here on pick up the new level; already-uploaded
        // ones keep theirs (vio samplers are baked at creation).
        $this->anisotropy = max(1, min(16, $settings->anisotropy));
        // KTX2 chains: the tier's LOD bias becomes whole levels dropped at upload.
        $this->ktx2MipOffset = max(0, (int) round($settings->textureQuality->lodBias()));

        if (function_exists('vio_set_default_anisotropy')) {
            try {
                @vio_set_default_anisotropy($this->ctx, $settings->anisotropy);
            } catch (\Throwable $e) {
                // Older vio builds may not expose this helper - ignore.
            }
        }
    }
}
