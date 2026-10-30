<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Rendering\Color;

/**
 * Text fixed on a surface in world space: a sign, a plate, a label on a device.
 *
 * The entity also carries a {@see MeshRenderer} with the quad and a
 * {@see Transform3D} whose scale is the surface size ({@see \PHPolygon\Prefab\TextPlate}
 * builds that). {@see \PHPolygon\System\SurfaceTextSystem} is the only writer of
 * the renderer's material: it holds the text of the current language as an
 * opaque texture, so depth, perspective and light are the scene's own and a
 * language change needs no rebuild of the world.
 *
 * Colours are stored in the texture unchanged and the shader reads them as
 * linear albedo: hand in `Color::srgb('#...')` for a hex colour, like any albedo.
 * The text is centred and fitted into the surface: it wraps, then shrinks, and is
 * never cut off.
 */
#[Serializable]
#[Category('Rendering')]
class SurfaceText extends AbstractComponent
{
    /** Literal text. Ignored when {@see $textKey} is set. */
    #[Property]
    public string $text = '';

    /** Translation key: wins over {@see $text} and follows the language. */
    #[Property]
    public string $textKey = '';

    /** Surface size in metres. */
    #[Property]
    public float $width = 1.0;

    #[Property]
    public float $height = 0.25;

    #[Property(editorHint: 'color')]
    public ?Color $color = null;

    #[Property(editorHint: 'color')]
    public ?Color $background = null;

    /** Font id of the {@see \PHPolygon\Rendering\Text\FontChain}. */
    #[Property]
    public string $fontId = 'semibold';

    /** Empty margin on every side, metres. */
    #[Property]
    public float $padding = 0.02;

    #[Property]
    public int $maxLines = 2;

    /** Texture resolution. 256 is about 8 px per stroke of a 10 cm letter. */
    #[Property]
    public float $pixelsPerMetre = 256.0;

    /** Baseline distance as a multiple of the font size. */
    #[Property]
    public float $lineHeight = 1.2;

    /** Largest font size in metres, 0 = whatever fits. Keeps a family of signs even. */
    #[Property]
    public float $maxFontSize = 0.0;

    /** Smallest font size the sign is meant to have. Reported, not enforced: nothing is cut. */
    #[Property]
    public float $minFontSize = 0.05;

    /**
     * Ignore the scene's light (LED, neon, displays). Takes a game's proc mode on
     * {@see \PHPolygon\Rendering\Text\SurfaceTextBaker::UNLIT_MATERIAL_PREFIX}:
     * the engine ships none, unmapped the surface is lit like any other.
     */
    #[Property]
    public bool $unlit = false;

    /** Material whose surface (roughness, patterns, metal) the plate takes over. '' = plain. */
    #[Property(editorHint: 'asset:material')]
    public string $baseMaterial = '';

    /*
     * Inline defaults, because the serializer builds this class without its
     * constructor and skips keys a scene file leaves out. The two colours cannot
     * have one (no objects in property defaults): read them through
     * inkColor() / plateColor().
     */

    /** Set by the system: the pass this surface was last resolved in. */
    #[Hidden]
    public int $resolvedStamp = 0;

    /** Set by the system: the texture shown now, '' while the surface is plain. */
    #[Hidden]
    public string $textureId = '';

    public function __construct(
        string $text = '',
        string $textKey = '',
        float $width = 1.0,
        float $height = 0.25,
        ?Color $color = null,
        ?Color $background = null,
        string $fontId = 'semibold',
        float $padding = 0.02,
        int $maxLines = 2,
        float $pixelsPerMetre = 256.0,
        float $lineHeight = 1.2,
        float $maxFontSize = 0.0,
        float $minFontSize = 0.05,
        bool $unlit = false,
        string $baseMaterial = '',
    ) {
        $this->text = $text;
        $this->textKey = $textKey;
        $this->width = $width;
        $this->height = $height;
        $this->color = $color;
        $this->background = $background;
        $this->fontId = $fontId;
        $this->padding = $padding;
        $this->maxLines = $maxLines;
        $this->pixelsPerMetre = $pixelsPerMetre;
        $this->lineHeight = $lineHeight;
        $this->maxFontSize = $maxFontSize;
        $this->minFontSize = $minFontSize;
        $this->unlit = $unlit;
        $this->baseMaterial = $baseMaterial;
    }

    public function inkColor(): Color
    {
        return $this->color ?? Color::white();
    }

    public function plateColor(): Color
    {
        return $this->background ?? new Color(0.02, 0.02, 0.02);
    }

    /** Change the literal text at run time; the system picks it up next tick. */
    public function setText(string $text): void
    {
        $this->text = $text;
        $this->textKey = '';
        $this->resolvedStamp = 0;
    }

    /** Change the translation key at run time; the system picks it up next tick. */
    public function setTextKey(string $textKey): void
    {
        $this->textKey = $textKey;
        $this->resolvedStamp = 0;
    }

    /** After editing any other field: resolve this surface again. */
    public function touch(): void
    {
        $this->resolvedStamp = 0;
    }
}
