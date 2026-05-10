<?php

declare(strict_types=1);

namespace PHPolygon\UI;

use PHPolygon\Engine;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ColorGradingPreset;
use PHPolygon\Rendering\Quality\MeshLodTier;
use PHPolygon\Rendering\Quality\QualityMode;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ScreenSpaceReflections;
use PHPolygon\Rendering\Quality\ShaderQuality;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\Quality\TextureQuality;

/**
 * Drop-in widget that draws a "Graphics" options panel through a UIContext.
 *
 * Games typically build it once and call draw() inside their settings screen.
 * The panel reads and writes through the engine's GraphicsSettingsManager so
 * any change is immediately persisted and applied to the active renderer.
 *
 * The Manual sliders are visually disabled (greyed) when QualityMode::Adaptive
 * is selected - the AdaptiveQualityController owns those values in that mode.
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

        $this->ui->label($enabled ? 'Manual Settings' : 'Manual Settings (locked - Adaptive mode active)');

        // Render scale
        $rs = $this->ui->slider('graphics.renderScale', 'Render Scale', $s->renderScale, 0.5, 2.0);
        if ($enabled && abs($rs - $s->renderScale) > 0.01) {
            $manager->update(static fn(GraphicsSettings $g): GraphicsSettings => $g->with(renderScale: $rs));
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
        $aaLabels = array_map(static fn(AntiAliasing $a): string => $a->label(), $aas);
        $idx = array_search($s->antiAliasing, $aas, true);
        if (!is_int($idx)) {
            $idx = 1;
        }
        $this->ui->label('Anti-Aliasing');
        $newIdx = $this->ui->dropdown('graphics.aa', $aaLabels, $idx, 0.0, 0);
        if ($enabled && $newIdx !== $idx) {
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
    }
}
