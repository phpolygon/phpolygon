<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Geometry\SkinnedMeshData;
use PHPolygon\Geometry\SkinnedMeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Command\DrawSkinnedMesh;

/**
 * GPU skinning for {@see VioRenderer3D}: DrawSkinnedMesh commands drawn in the opaque
 * pass and in the cascade / spot / point shadow passes.
 *
 * One storage buffer (binding 0 of the vertex stage, VIO_FEATURE_VERTEX_STORAGE, no
 * texture unit) holds everything the vertex stage needs, in mat4 units: the bone palettes,
 * then per instance two mat4 records (the model matrix; a record whose column 0 x is the
 * instance's palette base). D3D11 keeps one vertex storage SRV at a time, so palettes and
 * instance matrices share this buffer and a skinned draw cannot also use the matrix
 * buffer of storage instancing: each draw selects its slice through the `u_skin_inst` uniform.
 *
 * Vertex layout: location 0 position, 1 normal, 2 uv, 7 joints (vec4, float indices),
 * 8 weights. Programs `default_skin[_mrt[_mv]]` and `shadow_skin` are text-derived from
 * mesh3d.vert / shadow.vert like the storage-instance programs; the fragment stage is
 * mesh3d.frag, so skinned meshes are lit, shadowed, SSAO-occluded (MRT frames) and
 * variant-switched (cut-out) like any opaque mesh. Kept in a trait so the renderer's own
 * code only gains hooks (see CLAUDE.md "Skinned draws" for the list).
 */
trait VioSkinnedDraws
{
    /** @var list<array{cmd: DrawSkinnedMesh, material: Material, inst: int, viewInst: int, viewCount: int, depth: float}> */
    private array $skinDraws = [];
    private ?\VioBuffer $skinBuffer = null;
    private int $skinBufferBytes = 0;
    private ?bool $skinningReady = null;
    private ?bool $skinRebind = null;
    /** @var array<int, array{0: \VioBuffer, 1: int}> ring of storage buffers and their byte capacity */
    private array $skinRing = [];
    private int $skinRingIndex = -1;
    private int $skinCulled = 0;

    /** Instances the last frame's camera cull dropped (the shadow passes still drew them). */
    public function skinInstancesCulled(): int
    {
        return $this->skinCulled;
    }
    /** @var array<string, \VioMesh> */
    private array $skinMeshCache = [];
    /** @var array<string, int> */
    private array $skinMeshVersions = [];

    /** Whether this backend can draw skinned meshes on the GPU (else the CPU path stays). */
    public function skinningAvailable(): bool
    {
        return function_exists('vio_draw_instanced_from_buffer')
            && defined('VIO_FEATURE_VERTEX_STORAGE')
            && vio_supports_feature($this->ctx, VIO_FEATURE_VERTEX_STORAGE);
    }

    /**
     * mesh3d.vert reading the model matrix and bone palette from the storage buffer
     * and skinning position and normal. Throws when the anchors no longer match.
     */
    public static function skinVertexSource(string $vertexSource): string
    {
        $src = str_replace("\r\n", "\n", $vertexSource);
        $blend = 'skinData[skinBase + int(a_joints.x + 0.5)] * a_weights.x'
            . "\n        + skinData[skinBase + int(a_joints.y + 0.5)] * a_weights.y"
            . "\n        + skinData[skinBase + int(a_joints.z + 0.5)] * a_weights.z"
            . "\n        + skinData[skinBase + int(a_joints.w + 0.5)] * a_weights.w";
        $edits = [
            "#version 410 core\n" => "#version 450\n",
            "layout(location = 3) in vec4 a_instance_col0;\nlayout(location = 4) in vec4 a_instance_col1;\nlayout(location = 5) in vec4 a_instance_col2;\nlayout(location = 6) in vec4 a_instance_col3;\n"
                => "layout(location = 7) in vec4 a_joints;\nlayout(location = 8) in vec4 a_weights;\n"
                . "// Bone palettes and per-instance records (model, palette base) from the storage buffer at slot 0.\n"
                . "layout(std430, binding = 0) readonly buffer SkinStorage { mat4 skinData[]; };\nuniform int u_skin_inst;\n",
            "    mat4 model = u_model;\n    if (u_use_instancing == 1) {\n        model = mat4(a_instance_col0, a_instance_col1, a_instance_col2, a_instance_col3);\n    }\n"
                => "    mat4 model = u_model;\n    int skinBase = 0;\n    int skinBasePrev = -1;\n    if (u_use_instancing == 1) {\n        int rec = u_skin_inst + gl_InstanceIndex * 2;\n        model = skinData[rec];\n        skinBase = int(skinData[rec + 1][0].x + 0.5);\n        float pb = skinData[rec + 1][0].y;\n        skinBasePrev = pb < 0.0 ? -1 : int(pb + 0.5);\n    }\n",
            "    vec3 pos = animatePosition(vatPosition(a_position), model, u_time, grassHash);\n"
                => "    mat4 skinM = {$blend};\n    vec3 skinPos = (skinM * vec4(a_position, 1.0)).xyz;\n    vec3 skinNrm = normalize(mat3(skinM) * a_normal);\n    vec3 pos = animatePosition(vatPosition(skinPos), model, u_time, grassHash);\n",
            "    vec3 localNormal = vatNormal(a_normal);\n" =>"    vec3 localNormal = skinNrm;\n",
            "    vec3 prevPos = animatePosition(a_position, prevModel, u_time_prev, grassHash);\n"
                => "    // Previous-frame pose for the motion vectors (DrawSkinnedMesh::\$prevPalettes); the current one without.\n"
                . "    vec3 skinPosPrev = skinPos;\n    if (skinBasePrev >= 0) {\n"
                . "        mat4 skinMp = " . str_replace('skinBase +', 'skinBasePrev +', $blend) . ";\n"
                . "        skinPosPrev = (skinMp * vec4(a_position, 1.0)).xyz;\n    }\n"
                . "    vec3 prevPos = animatePosition(skinPosPrev, prevModel, u_time_prev, grassHash);\n",
        ];
        foreach ($edits as $old => $new) {
            if (substr_count($src, $old) !== 1) {
                throw new \RuntimeException('mesh3d.vert.glsl changed; skinVertexSource() anchors need updating');
            }
            $src = str_replace($old, $new, $src);
        }

        return $src;
    }

    /** shadow.vert with the same skinning. */
    public static function skinShadowVertexSource(string $vertexSource): string
    {
        $src = str_replace("\r\n", "\n", $vertexSource);
        $blend = 'skinData[skinBase + int(a_joints.x + 0.5)] * a_weights.x'
            . "\n        + skinData[skinBase + int(a_joints.y + 0.5)] * a_weights.y"
            . "\n        + skinData[skinBase + int(a_joints.z + 0.5)] * a_weights.z"
            . "\n        + skinData[skinBase + int(a_joints.w + 0.5)] * a_weights.w";
        $edits = [
            "#version 410 core\n" => "#version 450\n",
            "layout(location = 3) in vec4 a_instance_col0;\nlayout(location = 4) in vec4 a_instance_col1;\nlayout(location = 5) in vec4 a_instance_col2;\nlayout(location = 6) in vec4 a_instance_col3;\n"
                => "layout(location = 7) in vec4 a_joints;\nlayout(location = 8) in vec4 a_weights;\n"
                . "layout(std430, binding = 0) readonly buffer SkinStorage { mat4 skinData[]; };\nuniform int u_skin_inst;\n",
            "    mat4 model = u_model;\n    if (u_use_instancing == 1) {\n        model = mat4(a_instance_col0, a_instance_col1, a_instance_col2, a_instance_col3);\n    }\n"
                => "    mat4 model = u_model;\n    int skinBase = 0;\n    if (u_use_instancing == 1) {\n        int rec = u_skin_inst + gl_InstanceIndex * 2;\n        model = skinData[rec];\n        skinBase = int(skinData[rec + 1][0].x + 0.5);\n    }\n",
            "    vec3 pos = a_position;\n"
                => "    mat4 skinM = {$blend};\n    vec3 pos = (skinM * vec4(a_position, 1.0)).xyz;\n",
        ];
        foreach ($edits as $old => $new) {
            if (substr_count($src, $old) !== 1) {
                throw new \RuntimeException('shadow.vert.glsl changed; skinShadowVertexSource() anchors need updating');
            }
            $src = str_replace($old, $new, $src);
        }

        return $src;
    }

    /**
     * Collect this frame's DrawSkinnedMesh commands and upload their palettes and
     * instance records into the storage buffer. Called before the shadow passes.
     *
     * @param list<object> $commands
     */
    private function prepareSkinFrame(array $commands): void
    {
        $this->skinDraws = [];
        $this->skinCulled = 0;
        $found = [];
        foreach ($commands as $cmd) {
            if ($cmd instanceof DrawSkinnedMesh) {
                $found[] = $cmd;
            }
        }
        if ($found === [] || !$this->skinningAvailable()) {
            return;
        }
        $bytes = '';
        $planes = $this->cameraProjectionMatrix !== null && $this->currentViewMatrix !== null && getenv('PHPOLYGON_NO_CULL') !== '1'
            ? Frustum::planes($this->cameraProjectionMatrix, $this->currentViewMatrix)
            : null;
        $cam = null;
        if ($this->currentViewMatrix !== null) {
            $eye = $this->currentViewMatrix->inverse()->getTranslation();
            $cam = [$eye->x, $eye->y, $eye->z];
        }
        foreach ($found as $cmd) {
            $skin = SkinnedMeshRegistry::get($cmd->meshId);
            $material = MaterialRegistry::get($cmd->materialId);
            if ($skin === null || $material === null) {
                continue;
            }
            $joints = count($skin->jointNames);
            $paletteBytes = [];
            foreach ($cmd->palettes as $p) {
                $packed = is_string($p) ? $p : SkinnedMeshData::packPalette($p);
                if (strlen($packed) !== $joints * 64) {
                    continue 2;
                }
                $paletteBytes[] = $packed;
            }
            $base = intdiv(strlen($bytes), 64);
            $bytes .= implode('', $paletteBytes);
            // Last frame's palettes (same indexing), for the motion vectors of a temporal frame.
            $prevBase = -1;
            if ($cmd->prevPalettes !== [] && count($cmd->prevPalettes) === count($cmd->palettes)) {
                $prevBytes = [];
                foreach ($cmd->prevPalettes as $p) {
                    $packed = is_string($p) ? $p : SkinnedMeshData::packPalette($p);
                    if (strlen($packed) === $joints * 64) {
                        $prevBytes[] = $packed;
                    }
                }
                if (count($prevBytes) === count($cmd->prevPalettes)) {
                    $prevBase = intdiv(strlen($bytes), 64);
                    $bytes .= implode('', $prevBytes);
                }
            }
            $inst = intdiv(strlen($bytes), 64);
            $sphere = $planes === null ? null : SkinnedMeshRegistry::sphere($cmd->meshId);
            $records = [];
            $visible = [];
            $depth = [];
            foreach ($cmd->models as $i => $model) {
                $m = $model->toArray();
                $records[$i] = pack('g16', ...$m)
                    . pack('g16', (float) ($base + $cmd->paletteOf($i) * $joints), $prevBase < 0 ? -1.0 : (float) ($prevBase + $cmd->paletteOf($i) * $joints), 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0);
                $transparent = $material->alpha < 1.0;
                if ($transparent && $cam !== null) {
                    $dx = $m[12] - $cam[0];
                    $dy = $m[13] - $cam[1];
                    $dz = $m[14] - $cam[2];
                    $depth[$i] = $dx * $dx + $dy * $dy + $dz * $dz;
                }
                if ($sphere !== null) {
                    // World sphere of the instance: the local sphere moved by the model, scaled by its largest axis.
                    $cx = $m[0] * $sphere[0] + $m[4] * $sphere[1] + $m[8] * $sphere[2] + $m[12];
                    $cy = $m[1] * $sphere[0] + $m[5] * $sphere[1] + $m[9] * $sphere[2] + $m[13];
                    $cz = $m[2] * $sphere[0] + $m[6] * $sphere[1] + $m[10] * $sphere[2] + $m[14];
                    $scale = sqrt(max($m[0] * $m[0] + $m[1] * $m[1] + $m[2] * $m[2], $m[4] * $m[4] + $m[5] * $m[5] + $m[6] * $m[6], $m[8] * $m[8] + $m[9] * $m[9] + $m[10] * $m[10]));
                    if (Frustum::sphereOutside($planes, $cx, $cy, $cz, $sphere[3] * $scale * 1.02)) {
                        continue;
                    }
                }
                $visible[] = $i;
            }
            $this->skinCulled += count($records) - count($visible);
            $bytes .= implode('', $records);
            // Shadow passes draw every instance (an off-screen caster still shades the view); the camera's
            // passes draw the visible subset, which is a second copy of those records when some were culled.
            // A transparent material draws its instances far to near, so the camera's copy is always made.
            $viewInst = $inst;
            if ($visible !== [] && (count($visible) < count($records) || ($material->alpha < 1.0 && $depth !== []))) {
                if ($material->alpha < 1.0 && $depth !== []) {
                    usort($visible, static fn (int $a, int $b): int => ($depth[$b] ?? 0.0) <=> ($depth[$a] ?? 0.0));
                }
                $viewInst = intdiv(strlen($bytes), 64);
                foreach ($visible as $i) {
                    $bytes .= $records[$i];
                }
            }
            $this->skinDraws[] = [
                'cmd' => $cmd, 'material' => $material, 'inst' => $inst,
                'viewInst' => $viewInst, 'viewCount' => count($visible),
                'depth' => $depth === [] ? 0.0 : array_sum($depth) / count($depth),
            ];
        }
        if ($this->skinDraws === []) {
            return;
        }
        $this->skinBuffer = $this->skinBufferFor($bytes);
        if ($this->skinBuffer === null) {
            $this->skinDraws = [];
        }
    }

    /**
     * The frame's storage buffer: a ring of three buffers grown on demand and filled with
     * vio_update_buffer (measured on D3D11 and D3D12 hardware, 2026-11-12), so a frame does not allocate
     * and the GPU may still read the buffer of the frame before.
     */
    private function skinBufferFor(string $bytes): ?\VioBuffer
    {
        $need = strlen($bytes);
        $slot = $this->skinRingIndex = ($this->skinRingIndex + 1) % 3;
        $current = $this->skinRing[$slot] ?? null;
        if ($current === null || $current[1] < $need) {
            $capacity = max(65536, (int) ($need * 1.5));
            $buffer = vio_storage_buffer($this->ctx, ['size' => $capacity, 'stride' => 4]);
            if ($buffer === false) {
                // No sized storage buffer: fall back to a fresh one holding exactly this frame's data.
                $buffer = vio_storage_buffer($this->ctx, ['data' => $bytes]);

                return $buffer === false ? null : $buffer;
            }
            $current = $this->skinRing[$slot] = [$buffer, $capacity];
        }
        vio_update_buffer($current[0], $bytes, 0);
        $this->skinBufferBytes = $need;

        return $current[0];
    }

    private function ensureSkinPrograms(): bool
    {
        if ($this->skinningReady !== null) {
            return $this->skinningReady;
        }
        $this->skinningReady = false;
        if (!$this->skinningAvailable()) {
            return false;
        }
        try {
            $vert = $this->meshVertexSource(false, true);
            $frag = $this->meshFragmentSource();
            $this->compileShader('default_skin', $vert, $frag);
            if ($this->mrtSupported()) {
                $this->compileShader('default_skin_mrt', $vert, self::withMrtDefine($frag));
            }
            $shadowVert = self::skinShadowVertexSource($this->loadShader('shadow.vert.glsl'));
            if (ProcModeShaderRegistry::instanceParamsEnabled()) {
                $shadowVert = self::withDefine($shadowVert, 'PHPOLYGON_INSTANCE_PARAMS');
            }
            $this->compileShader('shadow_skin', $shadowVert, $this->loadShader('shadow.frag.glsl'));
            $this->skinningReady = true;
        } catch (\RuntimeException $e) {
            fwrite(STDERR, "[VioRenderer3D] GPU skinning unavailable: {$e->getMessage()}\n");
        }

        return $this->skinningReady === true;
    }

    /** The PHPOLYGON_MOTION twin of the skinned MRT program, compiled on first temporal use. */
    private function ensureSkinMotionShader(): bool
    {
        if (isset($this->shaderCache['default_skin_mrt_mv'])) {
            return true;
        }
        try {
            $vert = self::withDefine($this->meshVertexSource(false, true), 'PHPOLYGON_MOTION');
            $frag = $this->meshFragmentSource();
            $this->compileShader('default_skin_mrt_mv', $vert, self::withMrtDefine(self::withDefine($frag, 'PHPOLYGON_MOTION')));
        } catch (\RuntimeException $e) {
            fwrite(STDERR, "[VioRenderer3D] skinned motion shader unavailable: {$e->getMessage()}\n");

            return false;
        }

        return true;
    }

    /** The skinned programs of a shader variant (cut-out and snippet variants), compiled at first use. */
    private function ensureSkinVariant(string $variant): bool
    {
        if ($variant === ProcModeShaderRegistry::BASE_VARIANT) {
            return true;
        }
        $id = 'default_skin@' . $variant;
        if ($this->motionThisFrame ? isset($this->shaderCache['default_skin_mrt_mv@' . $variant]) : isset($this->shaderCache[$id])) {
            return true;
        }
        try {
            $vert = $this->meshVertexSource(false, true);
            $frag = $this->meshFragmentSource($variant);
            $this->compileShader($id, $vert, $frag);
            if ($this->mrtSupported()) {
                $this->compileShader('default_skin_mrt@' . $variant, $vert, self::withMrtDefine($frag));
            }
            if ($this->motionThisFrame) {
                $this->compileShader(
                    'default_skin_mrt_mv@' . $variant,
                    self::withDefine($vert, 'PHPOLYGON_MOTION'),
                    self::withMrtDefine(self::withDefine($frag, 'PHPOLYGON_MOTION')),
                );
            }
        } catch (\RuntimeException $e) {
            fwrite(STDERR, "[VioRenderer3D] skinned programs of variant '{$variant}' unavailable: {$e->getMessage()}\n");

            return false;
        }

        return true;
    }

    private function uploadSkinMesh(string $meshId): ?\VioMesh
    {
        $version = SkinnedMeshRegistry::version($meshId);
        if (isset($this->skinMeshCache[$meshId]) && ($this->skinMeshVersions[$meshId] ?? -1) === $version) {
            return $this->skinMeshCache[$meshId];
        }
        $skin = SkinnedMeshRegistry::get($meshId);
        if ($skin === null) {
            return null;
        }
        $m = $skin->mesh;
        $n = $m->vertexCount();
        $data = [];
        for ($i = 0; $i < $n; $i++) {
            array_push(
                $data,
                (float) $m->vertices[$i * 3], (float) $m->vertices[$i * 3 + 1], (float) $m->vertices[$i * 3 + 2],
                (float) ($m->normals[$i * 3] ?? 0.0), (float) ($m->normals[$i * 3 + 1] ?? 1.0), (float) ($m->normals[$i * 3 + 2] ?? 0.0),
                (float) ($m->uvs[$i * 2] ?? 0.0), (float) ($m->uvs[$i * 2 + 1] ?? 0.0),
                (float) $skin->joints[$i * 4], (float) $skin->joints[$i * 4 + 1], (float) $skin->joints[$i * 4 + 2], (float) $skin->joints[$i * 4 + 3],
                (float) $skin->weights[$i * 4], (float) $skin->weights[$i * 4 + 1], (float) $skin->weights[$i * 4 + 2], (float) $skin->weights[$i * 4 + 3],
            );
        }
        $mesh = vio_mesh($this->ctx, [
            'vertices' => $data,
            'indices' => $m->indices,
            'layout' => [
                ['location' => 0, 'components' => 3],
                ['location' => 1, 'components' => 3],
                ['location' => 2, 'components' => 2],
                ['location' => 7, 'components' => 4],
                ['location' => 8, 'components' => 4],
            ],
        ]);
        if ($mesh === false) {
            return null;
        }
        $this->skinMeshCache[$meshId] = $mesh;
        $this->skinMeshVersions[$meshId] = $version;

        return $mesh;
    }

    /** The shader variant a skinned material draws with (the selectVariant() rules without profiles). */
    private function skinVariantOf(Material $material, string $materialId): string
    {
        $variant = $material->grass
            ? ProcModeShaderRegistry::BASE_VARIANT
            : ProcModeShaderRegistry::variantOf(ProcModeRegistry::resolve($materialId));
        if ($variant === ProcModeShaderRegistry::BASE_VARIANT) {
            $variant = $this->frameBaseVariant;
        }
        if (self::needsCutout($material)) {
            $variant .= self::CUTOUT_SUFFIX;
        }

        return $variant;
    }

    /** Opaque skinned draws, after the regular opaque draws of the frame. */
    private function drawSkinnedOpaque(): void
    {
        $this->drawSkinned('opaque');
    }

    /** Skinned draws of the depth prepass (the eligible opaque materials), after the regular prepass draws. */
    private function drawSkinnedPrepass(): void
    {
        $this->drawSkinned('prepass');
    }

    /** Transparent skinned draws, far to near (instances sorted in the buffer, commands here), after the regular transparent draws. */
    private function drawSkinnedTransparent(): void
    {
        $this->drawSkinned('transparent');
    }

    /**
     * Skinned draws into a planar mirror's image (called by the mirror pass with its mirrored view bound): the
     * commands of the mirror's layers that touch its frustum, opaque, forward shading.
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $planes mirrored frustum planes
     * @param array{0: array{ambientColor: \PHPolygon\Rendering\Color, ambientIntensity: float, dirLights: list<\PHPolygon\Rendering\Command\SetDirectionalLight>, pointLights: list<\PHPolygon\Rendering\Command\AddPointLight>, spotLights: list<\PHPolygon\Rendering\Command\AddSpotLight>, rectLights: list<\PHPolygon\Rendering\Command\AddRectLight>, fogColor: \PHPolygon\Rendering\Color, fogNear: float, fogFar: float, waveEnabled: bool, waveAmplitude: float, waveFrequency: float, wavePhase: float, ftMode: \PHPolygon\Rendering\Quality\FieldtracingMode, ftIntensity: float, ftAoRadius: float}, 1: bool, 2: list<\PHPolygon\Rendering\Command\SetDirectionalLight>} $inputs frame state, shadow map flag, sun lights
     */
    private function drawSkinnedInMirror(array $planes, int $layerMask, array $inputs): void
    {
        $this->drawSkinned('opaque', $inputs, ['planes' => $planes, 'mask' => $layerMask]);
    }

    /**
     * Whether any instance of the command can touch the frustum.
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $planes Frustum::planes()
     */
    private function skinCommandInFrustum(DrawSkinnedMesh $cmd, array $planes): bool
    {
        $sphere = SkinnedMeshRegistry::sphere($cmd->meshId);
        if ($sphere === null) {
            return true;
        }
        foreach ($cmd->models as $model) {
            $m = $model->toArray();
            $cx = $m[0] * $sphere[0] + $m[4] * $sphere[1] + $m[8] * $sphere[2] + $m[12];
            $cy = $m[1] * $sphere[0] + $m[5] * $sphere[1] + $m[9] * $sphere[2] + $m[13];
            $cz = $m[2] * $sphere[0] + $m[6] * $sphere[1] + $m[10] * $sphere[2] + $m[14];
            $scale = sqrt(max($m[0] * $m[0] + $m[1] * $m[1] + $m[2] * $m[2], $m[4] * $m[4] + $m[5] * $m[5] + $m[6] * $m[6], $m[8] * $m[8] + $m[9] * $m[9] + $m[10] * $m[10]));
            if (!Frustum::sphereOutside($planes, $cx, $cy, $cz, $sphere[3] * $scale * 1.02)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param 'opaque'|'prepass'|'transparent' $pass
     * @param ?array{0: array{ambientColor: \PHPolygon\Rendering\Color, ambientIntensity: float, dirLights: list<\PHPolygon\Rendering\Command\SetDirectionalLight>, pointLights: list<\PHPolygon\Rendering\Command\AddPointLight>, spotLights: list<\PHPolygon\Rendering\Command\AddSpotLight>, rectLights: list<\PHPolygon\Rendering\Command\AddRectLight>, fogColor: \PHPolygon\Rendering\Color, fogNear: float, fogFar: float, waveEnabled: bool, waveAmplitude: float, waveFrequency: float, wavePhase: float, ftMode: \PHPolygon\Rendering\Quality\FieldtracingMode, ftIntensity: float, ftAoRadius: float}, 1: bool, 2: list<\PHPolygon\Rendering\Command\SetDirectionalLight>} $inputs
     * @param ?array{planes: list<array{0: float, 1: float, 2: float, 3: float}>, mask: int} $mirror
     */
    private function drawSkinned(string $pass, ?array $inputs = null, ?array $mirror = null): void
    {
        $inputs ??= $this->sceneUniformInputs;
        if ($this->skinDraws === [] || $this->skinBuffer === null || $inputs === null || !$this->ensureSkinPrograms()) {
            return;
        }
        if ($this->motionThisFrame && !$this->ensureSkinMotionShader()) {
            return;
        }
        $transparent = $pass === 'transparent';
        $draws = $this->skinDraws;
        if ($transparent) {
            usort($draws, static fn (array $a, array $b): int => $b['depth'] <=> $a['depth']);
        }
        $groups = [];
        foreach ($draws as $d) {
            if (($d['material']->alpha < 1.0) !== $transparent || ($pass === 'prepass' && !self::prepassEligible($d['material']))) {
                continue;
            }
            if ($mirror === null) {
                // A body hidden from the main camera stays out of its passes (it still casts and shows in mirrors).
                if ($d['cmd']->shadowOnly) {
                    continue;
                }
            } else {
                if (($d['cmd']->layers & $mirror['mask']) === 0 || !$this->skinCommandInFrustum($d['cmd'], $mirror['planes'])) {
                    continue;
                }
                // The mirror sees every instance of a command that touches its frustum, not the main camera's visible subset.
                $d['viewInst'] = $d['inst'];
                $d['viewCount'] = count($d['cmd']->models);
            }
            $variant = $this->skinVariantOf($d['material'], $d['cmd']->materialId);
            if (!$this->ensureSkinVariant($variant)) {
                $variant = ProcModeShaderRegistry::BASE_VARIANT;
            }
            $groups[$variant][] = $d;
        }
        $cull = $this->currentSceneCull;
        $saved = $this->sceneVariant;
        [$frameState, $hasShadowMap, $dirLights] = $inputs;
        // A transparent mesh draws its back faces, then its front faces (blending writes no depth).
        $culls = $transparent ? [VIO_CULL_FRONT, VIO_CULL_BACK] : [$cull];
        foreach ($groups as $variant => $list) {
            foreach ($culls as $faceCull) {
            $this->sceneVariant = (string) $variant;
            if (!$this->bindPipeline($pass, cull: $faceCull, skinned: true)) {
                continue;
            }
            $this->uploadFrameUniforms($frameState);
            $this->uploadShadowUniforms($hasShadowMap, $dirLights);
            $this->uploadSsaoUniforms();
            $this->uploadSdfAoUniforms();
            $this->uploadSnippetInputs();
            $this->uploadMotionUniforms();
            vio_set_uniform($this->ctx, 'u_use_instancing', 1);
            if ($this->motionThisFrame) {
                vio_set_uniform($this->ctx, 'u_has_prev', 0);
            }
            $this->bindSkinBuffer();
            foreach ($list as $d) {
                $mesh = $d['viewCount'] > 0 ? $this->uploadSkinMesh($d['cmd']->meshId) : null;
                if ($mesh === null) {
                    continue;
                }
                $this->applyMaterialUniforms($d['material'], $d['cmd']->materialId);
                $this->bindMaterialTextures($d['material']);
                $this->applyGbufferWrite($pass === 'prepass' ? 0 : 1);
                vio_set_uniform($this->ctx, 'u_skin_inst', $d['viewInst']);
                if ($this->skinRebindPerDraw()) {
                    $this->bindSkinBuffer();
                }
                vio_draw_instanced_from_buffer($this->ctx, $mesh, $d['viewCount']);
            }
            vio_set_uniform($this->ctx, 'u_use_instancing', 0);
            }
        }
        $this->sceneVariant = $saved;
        // Back to the pass's regular program, like the storage-instance draw.
        $this->bindPipeline($pass, cull: $cull);
    }

    /** Skinned casters into the shadow map the caller is rendering (view / projection of that map). */
    private function drawSkinnedShadows(Mat4 $view, Mat4 $projection): void
    {
        if ($this->skinDraws === [] || $this->skinBuffer === null || !$this->ensureSkinPrograms()) {
            return;
        }
        // Cut-out casters (alphaCutoff on an albedo texture: hair cards) take the shadow program that
        // tests the texture's alpha; the others the plain one. Two passes over the draws, one pipeline each.
        $solid = [];
        $cutout = [];
        foreach ($this->skinDraws as $d) {
            if (!$d['cmd']->castShadows) {
                continue;
            }
            $material = $d['material'];
            if ($material->alphaCutoff > 0.0 && $material->albedoTexture !== null && $this->ensureSkinCutoutShadow()) {
                $texture = $this->resolveTexture($material->albedoTexture);
                if ($texture !== null) {
                    $cutout[] = [$d, $texture];
                    continue;
                }
            }
            $solid[] = [$d, null];
        }
        foreach ([['shadow_skin', $solid], ['shadow_skin_cutout', $cutout]] as [$program, $draws]) {
            if ($draws === [] || !$this->bindSkinShadowPipeline($program, $view, $projection)) {
                continue;
            }
            foreach ($draws as [$d, $texture]) {
                $mesh = $this->uploadSkinMesh($d['cmd']->meshId);
                if ($mesh === null) {
                    continue;
                }
                if ($texture !== null) {
                    vio_set_uniform($this->ctx, 'u_albedo_texture', 0);
                    vio_bind_texture($this->ctx, $texture, 0);
                    vio_set_uniform($this->ctx, 'u_alpha_cutoff', $d['material']->alphaCutoff);
                }
                vio_set_uniform($this->ctx, 'u_skin_inst', $d['inst']);
                if ($this->skinRebindPerDraw()) {
                    $this->bindSkinBuffer();
                }
                vio_draw_instanced_from_buffer($this->ctx, $mesh, count($d['cmd']->models));
            }
        }
    }

    /** Binds a skinned shadow program with the light's view and the skin buffer; false when its pipeline cannot be made. */
    private function bindSkinShadowPipeline(string $program, Mat4 $view, Mat4 $projection): bool
    {
        $key = 'shadow:' . $program;
        if (!isset($this->pipelineCache[$key])) {
            $pipeline = $this->createPipeline([
                'shader' => $this->shaderCache[$program],
                'depth_test' => true,
                'cull_mode' => VIO_CULL_NONE,
                'blend' => VIO_BLEND_NONE,
                'depth_bias' => 1.0,
                'slope_scaled_depth_bias' => 1.0,
            ]);
            if ($pipeline === false) {
                return false;
            }
            $this->pipelineCache[$key] = $pipeline;
        }
        vio_bind_pipeline($this->ctx, $this->pipelineCache[$key]);
        vio_set_uniform($this->ctx, 'u_view', $view->toArray());
        vio_set_uniform($this->ctx, 'u_projection', $projection->toArray());
        vio_set_uniform($this->ctx, 'u_use_instancing', 1);
        vio_set_uniform($this->ctx, 'u_foliage_wind', 0);
        vio_set_uniform($this->ctx, 'u_shadow_dapple', 0.0);
        $this->bindSkinBuffer();

        return true;
    }

    /** The cut-out twin of the skinned shadow program, compiled when the first cut-out caster needs it. */
    private function ensureSkinCutoutShadow(): bool
    {
        if (isset($this->shaderCache['shadow_skin_cutout'])) {
            return true;
        }
        try {
            $vert = self::withDefine(self::skinShadowVertexSource($this->loadShader('shadow.vert.glsl')), 'PHPOLYGON_SHADOW_CUTOUT');
            if (ProcModeShaderRegistry::instanceParamsEnabled()) {
                $vert = self::withDefine($vert, 'PHPOLYGON_INSTANCE_PARAMS');
            }
            $this->compileShader('shadow_skin_cutout', $vert, self::withDefine($this->loadShader('shadow.frag.glsl'), 'PHPOLYGON_SHADOW_CUTOUT'));
        } catch (\RuntimeException $e) {
            fwrite(STDERR, "[VioRenderer3D] cut-out skinned shadows unavailable: {$e->getMessage()}\n");

            return false;
        }

        return true;
    }

    private function bindSkinBuffer(): void
    {
        if ($this->skinBuffer !== null) {
            vio_bind_storage_buffer($this->ctx, $this->skinBuffer, 0, VIO_COMPUTE_READ);
        }
    }

    /**
     * D3D11 only picks up a changed constant (u_skin_inst) between two instanced-from-buffer
     * draws when the storage buffer is bound again (measured 2026-11-12: without it the second
     * draw of a pass vanishes). The rebind makes the driver-side SRV again, so it is limited to D3D11.
     */
    private function skinRebindPerDraw(): bool
    {
        return $this->skinRebind ??= vio_backend_name($this->ctx) === 'd3d11';
    }
}
