<?php

declare(strict_types=1);

namespace PHPolygon\UI;

use PHPolygon\Engine;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ColorGradingPreset;
use PHPolygon\Rendering\Quality\GraphicsCapabilities;
use PHPolygon\Rendering\Quality\MeshLodTier;
use PHPolygon\Rendering\Quality\QualityMode;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ScreenSpaceReflections;
use PHPolygon\Rendering\Quality\ShaderQuality;
use PHPolygon\Rendering\Quality\ShadingRate;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\Quality\SurfaceRelief;
use PHPolygon\Rendering\Quality\TextureQuality;
use PHPolygon\Rendering\Quality\UpscaleQuality;
use PHPolygon\Rendering\Quality\Upscaler;

/**
 * Drop-in widget that draws a "Graphics" options panel through a UIContext.
 *
 * Games typically build it once and call draw() inside their settings screen.
 * The panel reads and writes through the engine's GraphicsSettingsManager so
 * any change is immediately persisted and applied to the active renderer.
 *
 * The Manual sliders are visually disabled (greyed) when QualityMode::Adaptive
 * is selected - the AdaptiveQualityController owns those values in that mode.
 * Options the active renderer cannot apply ({@see GraphicsCapabilities}) are
 * drawn non-interactive and marked "(not supported)"; their stored value stays.
 */
final class GraphicsOptionsPanel
{
    private bool $calibrating = false;
    private float $calibrationProgress = 0.0;
    private string $calibrationStage = '';

    public function __construct(
        private readonly Engine $engine,
        private readonly UIContext $ui,
    ) {
        $events = $engine->events;
        $events->listen(\PHPolygon\Event\GraphicsCalibrationStarted::class, function () {
            $this->calibrating = true;
            $this->calibrationProgress = 0.0;
            $this->calibrationStage = '';
        });
        $events->listen(\PHPolygon\Event\GraphicsCalibrationProgress::class, function (\PHPolygon\Event\GraphicsCalibrationProgress $e) {
            $this->calibrationProgress = $e->ratio;
            $this->calibrationStage = $e->stage;
        });
        $events->listen(\PHPolygon\Event\GraphicsCalibrationCompleted::class, function () {
            $this->calibrating = false;
        });
    }

    /**
     * Draw the panel. Returns the cursor Y so callers can chain layout below.
     */
    public function draw(float $x, float $y, float $width): float
    {
        $manager = $this->engine->graphics;
        $settings = $manager->settings();

        $this->ui->begin($x, $y, $width);
        $this->ui->label('Graphics', null);
        $this->ui->separator();

        // Mode dropdown
        $modes = [QualityMode::Manual, QualityMode::Adaptive, QualityMode::Off];
        $modeLabels = array_map(static fn(QualityMode $m): string => $m->label(), $modes);
        $currentModeIdx = array_search($settings->mode, $modes, true);
        if (!is_int($currentModeIdx)) {
            $currentModeIdx = 0;
        }
        $this->ui->label('Mode');
        $newModeIdx = $this->ui->dropdown('graphics.mode', $modeLabels, $currentModeIdx, 0.0, 0);
        if ($newModeIdx !== $currentModeIdx) {
            $manager->setMode($modes[$newModeIdx]);
        }

        // Target FPS
        $fpsValues = [30.0, 60.0, 120.0, 144.0];
        $fpsLabels = ['30 FPS', '60 FPS', '120 FPS', '144 FPS'];
        $currentFpsIdx = array_search($settings->targetFps, $fpsValues, true);
        if (!is_int($currentFpsIdx)) {
            $currentFpsIdx = 1; // 60 default
        }
        $this->ui->label('Target FPS');
        $newFpsIdx = $this->ui->dropdown('graphics.targetFps', $fpsLabels, $currentFpsIdx, 0.0, 0);
        if ($newFpsIdx !== $currentFpsIdx) {
            $manager->setTargetFps($fpsValues[$newFpsIdx]);
        }

        $this->ui->separator();

        // Recalibrate button - disabled while calibrating to prevent re-entry
        if ($this->ui->button('graphics.recalibrate', $this->calibrating ? 'Calibrating...' : 'Recalibrate Now', 0.0, $this->calibrating)) {
            $this->engine->graphics->recalibrate();
        }

        if ($this->calibrating) {
            $this->ui->progressBar(
                $this->calibrationStage !== '' ? $this->calibrationStage : 'Optimising...',
                max(0.0, min(1.0, $this->calibrationProgress)),
            );
        }

        $this->ui->separator();

        $manualEditable = $settings->mode !== QualityMode::Adaptive;

        $this->drawManualSection($settings, $manualEditable);

        $this->ui->end();
        return $this->ui->getCursorY();
    }

    private function drawManualSection(GraphicsSettings $s, bool $enabled): void
    {
        $manager = $this->engine->graphics;
        $caps = $manager->capabilities();

        $this->ui->label($enabled ? 'Manual Settings' : 'Manual Settings (locked - Adaptive mode active)');

        $effective = $s->effectiveUpscaler($caps);

        // Render scale - a temporal upscaler's quality preset decides it unless Custom.
        $presetDecides = $effective->isTemporal() && $s->upscaleQuality !== UpscaleQuality::Custom;
        $rsLabel = $presetDecides ? "Render Scale (set by {$s->upscaleQuality->label()})" : 'Render Scale';
        $rs = $this->gated(!$presetDecides, fn(): float => $this->ui->slider('graphics.renderScale', $rsLabel,
            $presetDecides ? $s->effectiveRenderScale($caps) : $s->renderScale,
            $s->upscaler->isTemporal() ? GraphicsSettings::TEMPORAL_RENDER_SCALE_MIN : GraphicsSettings::RENDER_SCALE_MIN,
            GraphicsSettings::RENDER_SCALE_MAX,
        ));
        if ($enabled && !$presetDecides && abs($rs - $s->renderScale) > 0.01) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(renderScale: $rs));
        }

        // Upscaler - only the ones this renderer runs. A stored choice it does not
        // run shows what runs instead, and a hint says why.
        $upscalers = $caps->upscalers;
        $upscalerLabels = array_map(static fn(Upscaler $u): string => $u->label(), $upscalers);
        $idx = array_search($effective, $upscalers, true);
        if (!is_int($idx)) {
            $idx = 0;
        }
        $canUpscale = count($upscalers) > 1;
        $this->ui->label(self::supportLabel('Upscaler', $canUpscale));
        $newIdx = $this->gated($canUpscale, fn(): int => $this->ui->dropdown('graphics.upscaler', $upscalerLabels, $idx, 0.0, 0));
        if ($enabled && $newIdx !== $idx && isset($upscalers[$newIdx])) {
            $picked = $upscalers[$newIdx];
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(upscaler: $picked));
        }
        $hint = self::upscalerHint($s, $caps);
        if ($hint !== null) {
            $this->ui->label($hint, $this->ui->getStyle()->disabledTextColor);
        }

        // Quality preset of the temporal upscalers (TAAU, FSR 3, DLSS).
        if ($effective->isTemporal()) {
            $qualities = UpscaleQuality::cases();
            $qualityLabels = array_map(static fn(UpscaleQuality $q): string => $q->label(), $qualities);
            $qIdx = array_search($s->upscaleQuality, $qualities, true);
            if (!is_int($qIdx)) {
                $qIdx = 0;
            }
            $this->ui->label('Upscale Quality');
            $newQ = $this->ui->dropdown('graphics.upscaleQuality', $qualityLabels, $qIdx, 0.0, 0);
            if ($enabled && $newQ !== $qIdx && isset($qualities[$newQ])) {
                $pickedQ = $qualities[$newQ];
                $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(upscaleQuality: $pickedQ));
            }
        }

        // Sharpening runs after FSR 1, after every temporal resolve (FSR 3 sharpens
        // inside its own pass) and after TAA.
        if ($effective !== Upscaler::Off || $s->antiAliasing === AntiAliasing::Taa) {
            $sharp = $this->ui->slider('graphics.upscaleSharpness', 'Sharpness', $s->upscaleSharpness, 0.0, 1.0);
            if ($enabled && abs($sharp - $s->upscaleSharpness) > 0.01) {
                $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(upscaleSharpness: $sharp));
            }
        }

        // Shadow quality
        $shadows = [ShadowQuality::Off, ShadowQuality::Low, ShadowQuality::Medium, ShadowQuality::High];
        $shadowLabels = array_map(static fn(ShadowQuality $q): string => $q->label(), $shadows);
        $idx = array_search($s->shadowQuality, $shadows, true);
        if (!is_int($idx)) {
            $idx = 2;
        }
        $this->ui->label('Shadows');
        $newIdx = $this->ui->dropdown('graphics.shadows', $shadowLabels, $idx, 0.0, 0);
        if ($enabled && $newIdx !== $idx) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(shadowQuality: $shadows[$newIdx]));
        }

        // View distance
        $vd = $this->ui->slider('graphics.viewDistance', 'View Distance', $s->viewDistance, 50.0, 400.0);
        if ($enabled && abs($vd - $s->viewDistance) > 1.0) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(viewDistance: $vd));
        }

        // Anti-aliasing
        $aas = [AntiAliasing::Off, AntiAliasing::Fxaa, AntiAliasing::Msaa2x, AntiAliasing::Msaa4x, AntiAliasing::Taa];
        $aaLabels = array_map(
            static fn(AntiAliasing $a): string => self::supportLabel($a->label(), $caps->supportsAntiAliasing($a)),
            $aas,
        );
        $idx = array_search($s->antiAliasing, $aas, true);
        if (!is_int($idx)) {
            $idx = 1;
        }
        $this->ui->label('Anti-Aliasing');
        $newIdx = $this->ui->dropdown('graphics.aa', $aaLabels, $idx, 0.0, 0);
        if ($enabled && $newIdx !== $idx && $caps->supportsAntiAliasing($aas[$newIdx])) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(antiAliasing: $aas[$newIdx]));
        }

        // Anisotropy
        $aniso = [1, 2, 4, 8, 16];
        $anisoLabels = ['Off', '2x', '4x', '8x', '16x'];
        $idx = array_search($s->anisotropy, $aniso, true);
        if (!is_int($idx)) {
            $idx = 2;
        }
        $this->ui->label('Anisotropic Filtering');
        $newIdx = $this->ui->dropdown('graphics.aniso', $anisoLabels, $idx, 0.0, 0);
        if ($enabled && $newIdx !== $idx) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(anisotropy: $aniso[$newIdx]));
        }

        // Texture quality
        $textures = [TextureQuality::Full, TextureQuality::Half, TextureQuality::Quarter];
        $textureLabels = array_map(static fn(TextureQuality $q): string => $q->label(), $textures);
        $idx = array_search($s->textureQuality, $textures, true);
        if (!is_int($idx)) {
            $idx = 0;
        }
        $this->ui->label('Texture Quality');
        $newIdx = $this->ui->dropdown('graphics.textureQuality', $textureLabels, $idx, 0.0, 0);
        if ($enabled && $newIdx !== $idx) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(textureQuality: $textures[$newIdx]));
        }

        // Shading rate (variable rate shading; no effect on backends without it)
        $rates = [ShadingRate::Full, ShadingRate::Half, ShadingRate::Quarter];
        $rateLabels = array_map(static fn(ShadingRate $r): string => $r->label(), $rates);
        $idx = array_search($s->shadingRate, $rates, true);
        if (!is_int($idx)) {
            $idx = 0;
        }
        $this->ui->label(self::supportLabel('Shading Rate', $caps->shadingRate));
        $newIdx = $this->gated($caps->shadingRate, fn(): int => $this->ui->dropdown('graphics.shadingRate', $rateLabels, $idx, 0.0, 0));
        if ($enabled && $caps->shadingRate && $newIdx !== $idx) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(shadingRate: $rates[$newIdx]));
        }

        // Surface relief (cavities + parallax on materials that define them)
        $reliefs = [SurfaceRelief::Off, SurfaceRelief::Cavity, SurfaceRelief::Parallax];
        $reliefLabels = array_map(static fn(SurfaceRelief $r): string => $r->label(), $reliefs);
        $idx = array_search($s->surfaceRelief, $reliefs, true);
        if (!is_int($idx)) {
            $idx = 2;
        }
        $this->ui->label(self::supportLabel('Surface Relief', $caps->surfaceRelief));
        $newIdx = $this->gated($caps->surfaceRelief, fn(): int => $this->ui->dropdown('graphics.surfaceRelief', $reliefLabels, $idx, 0.0, 0));
        if ($enabled && $caps->surfaceRelief && $newIdx !== $idx) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(surfaceRelief: $reliefs[$newIdx]));
        }

        // Shader quality
        $shaders = [ShaderQuality::Full, ShaderQuality::Unlit];
        $shaderLabels = array_map(static fn(ShaderQuality $q): string => $q->label(), $shaders);
        $idx = array_search($s->shaderQuality, $shaders, true);
        if (!is_int($idx)) {
            $idx = 0;
        }
        $this->ui->label('Shader Quality');
        $newIdx = $this->ui->dropdown('graphics.shaderQuality', $shaderLabels, $idx, 0.0, 0);
        if ($enabled && $newIdx !== $idx) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(shaderQuality: $shaders[$newIdx]));
        }

        // Mesh LOD
        $meshLods = [MeshLodTier::High, MeshLodTier::Medium, MeshLodTier::Low];
        $meshLodLabels = array_map(static fn(MeshLodTier $t): string => $t->label(), $meshLods);
        $idx = array_search($s->meshLod, $meshLods, true);
        if (!is_int($idx)) {
            $idx = 0;
        }
        $this->ui->label('Mesh Detail');
        $newIdx = $this->ui->dropdown('graphics.meshLod', $meshLodLabels, $idx, 0.0, 0);
        if ($enabled && $newIdx !== $idx) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(meshLod: $meshLods[$newIdx]));
        }

        // Toggles
        $cloudShadows = $this->ui->checkbox('graphics.cloudShadows', 'Cloud Shadows', $s->cloudShadows);
        if ($enabled && $cloudShadows !== $s->cloudShadows) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(cloudShadows: $cloudShadows));
        }

        $bloom = $this->ui->checkbox('graphics.bloom', 'Bloom', $s->bloom);
        if ($enabled && $bloom !== $s->bloom) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(bloom: $bloom));
        }

        $fog = $this->ui->checkbox('graphics.fog', 'Fog', $s->fog);
        if ($enabled && $fog !== $s->fog) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(fog: $fog));
        }

        // Volumetric fog (godrays). Independent from the linear distance
        // fog above - games can run either, both, or neither.
        $volFog = $this->ui->checkbox('graphics.volumetricFog', 'Volumetric Fog (Godrays)', $s->volumetricFog);
        if ($enabled && $volFog !== $s->volumetricFog) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(volumetricFog: $volFog));
        }

        // Ambient Occlusion tier
        $aos = [ScreenSpaceAO::Off, ScreenSpaceAO::Low, ScreenSpaceAO::Medium, ScreenSpaceAO::High];
        $aoLabels = array_map(static fn(ScreenSpaceAO $a) => $a->label(), $aos);
        $idx = array_search($s->ambientOcclusion, $aos, true);
        if (!is_int($idx)) {
            $idx = 2;
        }
        $this->ui->label('Ambient Occlusion');
        $newIdx = $this->ui->dropdown('graphics.ao', $aoLabels, $idx, 0.0, 0);
        if ($enabled && $newIdx !== $idx) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(ambientOcclusion: $aos[$newIdx]));
        }

        // Color grading preset
        $grades = [
            ColorGradingPreset::Neutral, ColorGradingPreset::Warm, ColorGradingPreset::Cool,
            ColorGradingPreset::Cinematic, ColorGradingPreset::Vibrant, ColorGradingPreset::Muted,
        ];
        $gradeLabels = array_map(static fn(ColorGradingPreset $g) => $g->label(), $grades);
        $idx = array_search($s->colorGrading, $grades, true);
        if (!is_int($idx)) {
            $idx = 0;
        }
        $this->ui->label('Color Grading');
        $newIdx = $this->ui->dropdown('graphics.colorGrading', $gradeLabels, $idx, 0.0, 0);
        if ($enabled && $newIdx !== $idx) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(colorGrading: $grades[$newIdx]));
        }

        // Vignette intensity
        $this->ui->label('Vignette');
        $vig = $this->ui->slider('graphics.vignette', 'Vignette', $s->vignetteIntensity, 0.0, 1.0);
        if ($enabled && abs($vig - $s->vignetteIntensity) > 1e-4) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(vignetteIntensity: $vig));
        }

        // Screen-space reflections quality
        $ssrs = [ScreenSpaceReflections::Off, ScreenSpaceReflections::Low, ScreenSpaceReflections::High];
        $ssrLabels = array_map(static fn(ScreenSpaceReflections $r) => $r->label(), $ssrs);
        $idx = array_search($s->ssr, $ssrs, true);
        if (!is_int($idx)) {
            $idx = 0;
        }
        $this->ui->label('Reflections');
        $newIdx = $this->ui->dropdown('graphics.ssr', $ssrLabels, $idx, 0.0, 0);
        if ($enabled && $newIdx !== $idx) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(ssr: $ssrs[$newIdx]));
        }

        $vsync = $this->ui->checkbox('graphics.vsync', 'V-Sync', $s->vsync);
        if ($vsync !== $s->vsync) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(vsync: $vsync));
        }

        // FPS cap dropdown - 0 means uncapped
        $fpsCaps = [0, 30, 60, 120, 144];
        $fpsCapLabels = ['Unlimited', '30 FPS', '60 FPS', '120 FPS', '144 FPS'];
        $idx = array_search($s->fpsCap, $fpsCaps, true);
        if (!is_int($idx)) {
            $idx = 0;
        }
        $this->ui->label('FPS Cap');
        $newIdx = $this->ui->dropdown('graphics.fpsCap', $fpsCapLabels, $idx, 0.0, 0);
        if ($newIdx !== $idx) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(fpsCap: $fpsCaps[$newIdx]));
        }

        // Presentation: read when the window is created, so they apply on the next start.
        $lowLatency = $this->gated(
            $caps->lowLatency,
            fn(): bool => $this->ui->checkbox('graphics.lowLatency', self::supportLabel('Low Latency', $caps->lowLatency), $s->lowLatency),
        );
        if ($caps->lowLatency && $lowLatency !== $s->lowLatency) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(lowLatency: $lowLatency));
        }
        $hdrOutput = $this->gated(
            $caps->hdrOutput,
            fn(): bool => $this->ui->checkbox('graphics.hdrOutput', self::supportLabel('HDR10 Output', $caps->hdrOutput), $s->hdrOutput),
        );
        if ($caps->hdrOutput && $hdrOutput !== $s->hdrOutput) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(hdrOutput: $hdrOutput));
        }
        if ($caps->hdrOutput && $s->hdrOutput) {
            $paperWhite = $this->ui->slider('graphics.hdrPaperWhite', 'HDR Paper White (nits)', $s->hdrPaperWhite, 80.0, 400.0);
            if (abs($paperWhite - $s->hdrPaperWhite) > 1.0) {
                $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(hdrPaperWhite: $paperWhite));
            }
        }
        if ($caps->lowLatency || $caps->hdrOutput) {
            $this->ui->label('Low Latency and HDR10 apply after a restart');
        }
    }

    /**
     * Draw a widget non-interactive when the renderer cannot apply its option; the
     * previous interaction state is restored afterwards (the panel may itself sit
     * under a modal that turned interaction off).
     *
     * @template T
     * @param callable(): T $draw
     * @return T
     */
    private function gated(bool $supported, callable $draw): mixed
    {
        $wasInteractive = $this->ui->isInteractive();
        if (!$supported) {
            $this->ui->setInteractive(false);
        }
        try {
            return $draw();
        } finally {
            $this->ui->setInteractive($wasInteractive);
        }
    }

    /**
     * Why the stored upscaler does not run on this machine and what runs
     * instead, from the renderer's note ({@see GraphicsCapabilities::upscalerNote()});
     * null when the selection runs. The stored choice is kept either way, so a
     * settings file carried to a capable machine picks it up again.
     */
    public static function upscalerHint(GraphicsSettings $settings, GraphicsCapabilities $caps): ?string
    {
        $selected = $settings->upscaler;
        if ($caps->supportsUpscaler($selected)) {
            return null;
        }
        $instead = $settings->effectiveUpscaler($caps);
        $note = $caps->upscalerNote($selected) ?? 'not supported here';
        return "{$selected->label()}: {$note} \u{2013} using {$instead->label()}";
    }

    private static function supportLabel(string $label, bool $supported): string
    {
        return $supported ? $label : $label . ' (not supported)';
    }
}
