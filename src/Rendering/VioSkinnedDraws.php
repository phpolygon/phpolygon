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
    /** @var list<array{cmd: DrawSkinnedMesh, material: Material, inst: int, viewInst: int, viewCount: int}> */
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
                => "    mat4 model = u_model;\n    int skinBase = 0;\n    if (u_use_instancing == 1) {\n        int rec = u_skin_inst + gl_InstanceIndex * 2;\n        model = skinData[rec];\n        skinBase = int(skinData[rec + 1][0].x + 0.5);\n    }\n",
            "    vec3 pos = animatePosition(vatPosition(a_position), model, u_time, grassHash);\n"
                => "    mat4 skinM = {$blend};\n    vec3 skinPos = (skinM * vec4(a_position, 1.0)).xyz;\n    vec3 skinNrm = normalize(mat3(skinM) * a_normal);\n    vec3 pos = animatePosition(vatPosition(skinPos), model, u_time, grassHash);\n",
            "    vec3 localNormal = vatNormal(a_normal);\n" =>"    vec3 localNormal = skinNrm;\n",
            "    vec3 prevPos = animatePosition(a_position, prevModel, u_time_prev, grassHash);\n"
                => "    vec3 prevPos = animatePosition(skinPos, prevModel, u_time_prev, grassHash);\n",
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
            $inst = intdiv(strlen($bytes), 64);
            $sphere = $planes === null ? null : SkinnedMeshRegistry::sphere($cmd->meshId);
            $records = [];
            $visible = [];
            foreach ($cmd->models as $i => $model) {
                $m = $model->toArray();
                $records[$i] = pack('g16', ...$m)
                    . pack('g16', (float) ($base + $cmd->paletteOf($i) * $joints), 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0);
                if ($sphere !== null && $planes !== null) {
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
            $viewInst = $inst;
            if (count($visible) < count($records) && $visible !== []) {
                $viewInst = intdiv(strlen($bytes), 64);
                foreach ($visible as $i) {
                    $bytes .= $records[$i];
                }
            }
            $this->skinDraws[] = [
                'cmd' => $cmd, 'material' => $material, 'inst' => $inst,
                'viewInst' => $viewInst, 'viewCount' => count($visible),
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
        $inputs = $this->sceneUniformInputs;
        if ($this->skinDraws === [] || $this->skinBuffer === null || $inputs === null || !$this->ensureSkinPrograms()) {
            return;
        }
        if ($this->motionThisFrame && !$this->ensureSkinMotionShader()) {
            return;
        }
        $groups = [];
        foreach ($this->skinDraws as $d) {
            if ($d['material']->alpha < 1.0) {
                continue;
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
        foreach ($groups as $variant => $list) {
            $this->sceneVariant = (string) $variant;
            if (!$this->bindPipeline('opaque', cull: $cull, skinned: true)) {
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
            vio_bind_storage_buffer($this->ctx, $this->skinBuffer, 0, VIO_COMPUTE_READ);
            foreach ($list as $d) {
                $mesh = $d['viewCount'] > 0 ? $this->uploadSkinMesh($d['cmd']->meshId) : null;
                if ($mesh === null) {
                    continue;
                }
                $this->applyMaterialUniforms($d['material'], $d['cmd']->materialId);
                $this->bindMaterialTextures($d['material']);
                $this->applyGbufferWrite(1);
                vio_set_uniform($this->ctx, 'u_skin_inst', $d['viewInst']);
                if ($this->skinRebindPerDraw()) {
                    vio_bind_storage_buffer($this->ctx, $this->skinBuffer, 0, VIO_COMPUTE_READ);
                }
                vio_draw_instanced_from_buffer($this->ctx, $mesh, $d['viewCount']);
            }
            vio_set_uniform($this->ctx, 'u_use_instancing', 0);
        }
        $this->sceneVariant = $saved;
        // Back to the pass's regular program, like the storage-instance draw.
        $this->bindPipeline('opaque', cull: $cull);
    }

    /** Skinned casters into the shadow map the caller is rendering (view / projection of that map). */
    private function drawSkinnedShadows(Mat4 $view, Mat4 $projection): void
    {
        if ($this->skinDraws === [] || $this->skinBuffer === null || !$this->ensureSkinPrograms()) {
            return;
        }
        $key = 'shadow:shadow_skin';
        if (!isset($this->pipelineCache[$key])) {
            $pipeline = $this->createPipeline([
                'shader' => $this->shaderCache['shadow_skin'],
                'depth_test' => true,
                'cull_mode' => VIO_CULL_NONE,
                'blend' => VIO_BLEND_NONE,
                'depth_bias' => 1.0,
                'slope_scaled_depth_bias' => 1.0,
            ]);
            if ($pipeline === false) {
                return;
            }
            $this->pipelineCache[$key] = $pipeline;
        }
        vio_bind_pipeline($this->ctx, $this->pipelineCache[$key]);
        vio_set_uniform($this->ctx, 'u_view', $view->toArray());
        vio_set_uniform($this->ctx, 'u_projection', $projection->toArray());
        vio_set_uniform($this->ctx, 'u_use_instancing', 1);
        vio_set_uniform($this->ctx, 'u_foliage_wind', 0);
        vio_set_uniform($this->ctx, 'u_shadow_dapple', 0.0);
        vio_bind_storage_buffer($this->ctx, $this->skinBuffer, 0, VIO_COMPUTE_READ);
        foreach ($this->skinDraws as $d) {
            if (!$d['cmd']->castShadows) {
                continue;
            }
            $mesh = $this->uploadSkinMesh($d['cmd']->meshId);
            if ($mesh === null) {
                continue;
            }
            vio_set_uniform($this->ctx, 'u_skin_inst', $d['inst']);
            if ($this->skinRebindPerDraw()) {
                vio_bind_storage_buffer($this->ctx, $this->skinBuffer, 0, VIO_COMPUTE_READ);
            }
            vio_draw_instanced_from_buffer($this->ctx, $mesh, count($d['cmd']->models));
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
