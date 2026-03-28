<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\Command\SetSkybox;
use Vk\Buffer;
use Vk\CommandPool;
use Vk\DescriptorPool;
use Vk\DescriptorSet;
use Vk\DescriptorSetLayout;
use Vk\Device;
use Vk\DeviceMemory;
use Vk\Fence;
use Vk\Framebuffer;
use Vk\Image;
use Vk\ImageView;
use Vk\Instance;
use Vk\PhysicalDevice;
use Vk\Pipeline;
use Vk\PipelineLayout;
use Vk\Queue;
use Vk\RenderPass;
use Vk\Semaphore;
use Vk\ShaderModule;
use Vk\Surface;
use Vk\Swapchain;

/**
 * Vulkan 1.0 3D renderer. Translates a RenderCommandList into Vulkan draw calls.
 * Requires a GLFW window created with GLFW_CLIENT_API = GLFW_NO_API.
 */
class VulkanRenderer3D implements Renderer3DInterface
{
    private int $width;
    private int $height;

    private Instance $instance;
    private PhysicalDevice $gpu;
    private Surface $surface;
    private Device $device;
    private Queue $queue;
    private int $graphicsFamily;
    private Swapchain $swapchain;
    private int $surfaceFormat;
    private RenderPass $renderPass;
    private Pipeline $pipeline;
    private PipelineLayout $pipelineLayout;
    private DescriptorSetLayout $descriptorSetLayout;
    private DescriptorPool $descriptorPool;
    private DescriptorSet $descriptorSet;
    private CommandPool $commandPool;
    private \Vk\CommandBuffer $commandBuffer;
    private Fence $inFlightFence;
    private Semaphore $imageAvailableSem;
    private Semaphore $renderFinishedSem;

    /** @var ImageView[] */
    private array $swapImageViews = [];
    /** @var Framebuffer[] */
    private array $framebuffers = [];
    /** @var array<array<mixed>> GPU memory type descriptors */
    private array $memTypes = [];

    private Buffer $frameUbo;
    private DeviceMemory $frameUboMem;
    private Buffer $lightingUbo;
    private DeviceMemory $lightingUboMem;

    private int $currentImageIndex = 0;
    private float $clearR = 0.0;
    private float $clearG = 0.0;
    private float $clearB = 0.0;

    /** @var float[] */
    private array $viewMatrix = [];
    /** @var float[] */
    private array $projMatrix = [];
    /** @var float[] [r, g, b, intensity] */
    private array $ambient = [1.0, 1.0, 1.0, 0.1];
    /** @var float[] [dx, dy, dz, intensity, r, g, b] */
    private array $dirLight = [0.0, -1.0, 0.0, 0.0, 1.0, 1.0, 1.0];
    /** @var float[] [r, g, b] */
    private array $albedo = [0.8, 0.8, 0.8];
    /** @var float[] [r, g, b, near, far] */
    private array $fog = [0.5, 0.5, 0.5, 50.0, 200.0];
    /** @var float[] [x, y, z] */
    private array $cameraPos = [0.0, 0.0, 0.0];
    /** @var array<int, array{pos: float[], color: float[], intensity: float, radius: float}> */
    private array $pointLights = [];

    // Mesh GPU buffer cache — holds Buffer + DeviceMemory together to prevent GC
    /** @var array<string, array{vb: Buffer, vbMem: DeviceMemory, ib: Buffer, ibMem: DeviceMemory, count: int}> */
    private array $meshCache = [];

    private const VERT_SPV        = __DIR__ . '/../../resources/shaders/compiled/mesh3d_vk.vert.spv';
    private const FRAG_SPV        = __DIR__ . '/../../resources/shaders/compiled/mesh3d_vk.frag.spv';
    private const FRAME_UBO_SIZE   = 128;   // mat4 view + mat4 projection
    private const LIGHTING_UBO_SIZE = 384;  // 7×16 + 16 + 8×32

    // Vk constants mirrored here — Vk\Vk is final+abstract which PHPStan rejects
    private const VK_CMD_ONE_TIME_SUBMIT       = 1;
    private const VK_PIPELINE_BIND_GRAPHICS    = 0;
    private const VK_SHADER_STAGE_VERTEX       = 1;
    private const VK_SHADER_STAGE_FRAGMENT     = 16;
    private const VK_INDEX_TYPE_UINT32         = 1;
    private const VK_IMAGE_USAGE_COLOR         = 16;
    private const VK_IMAGE_USAGE_DEPTH         = 32;
    private const VK_SHARING_EXCLUSIVE         = 0;
    private const VK_SAMPLE_COUNT_1            = 1;
    private const VK_LOAD_OP_CLEAR             = 1;
    private const VK_LOAD_OP_DONT_CARE         = 2;
    private const VK_STORE_OP_STORE            = 0;
    private const VK_STORE_OP_DONT_CARE        = 1;
    private const VK_LAYOUT_UNDEFINED          = 0;
    private const VK_LAYOUT_PRESENT_SRC        = 1000001002;
    private const VK_LAYOUT_COLOR_ATTACHMENT   = 2;
    private const VK_LAYOUT_DEPTH_ATTACHMENT   = 3;
    private const VK_ASPECT_COLOR              = 1;
    private const VK_ASPECT_DEPTH              = 2;
    private const VK_FORMAT_D32_SFLOAT         = 126;
    private const VK_FORMAT_R32G32B32_SFLOAT   = 106;
    private const VK_FORMAT_R32G32_SFLOAT      = 103;
    private const VK_BUFFER_USAGE_VERTEX       = 128;
    private const VK_BUFFER_USAGE_INDEX        = 64;
    private const VK_BUFFER_USAGE_UNIFORM      = 16;
    private const VK_DESCRIPTOR_UNIFORM_BUFFER = 6;
    private const VK_VERTEX_INPUT_RATE_VERTEX  = 0;
    private const VK_CULL_MODE_BACK            = 2;
    private const VK_FRONT_FACE_CCW            = 0;
    private const VK_CMD_POOL_RESET_CMD_BUFFER = 2;
    private const VK_PRESENT_MODE_FIFO         = 2;

    public function __construct(int $width, int $height, \GLFWwindow $windowHandle)
    {
        $this->width  = $width;
        $this->height = $height;
        $this->initVulkan($windowHandle);
    }

    public function beginFrame(): void
    {
        $this->pointLights = [];

        $this->inFlightFence->wait(1_000_000_000);
        $this->inFlightFence->reset();

        $this->currentImageIndex = $this->swapchain->acquireNextImage(
            $this->imageAvailableSem,
            null,
            1_000_000_000,
        );

        $this->commandBuffer->reset(0);
        $this->commandBuffer->begin(self::VK_CMD_ONE_TIME_SUBMIT);
    }

    public function endFrame(): void
    {
        $this->commandBuffer->end();

        $this->queue->submit(
            [$this->commandBuffer],
            $this->inFlightFence,
            [$this->imageAvailableSem],
            [$this->renderFinishedSem],
        );

        $this->queue->present(
            [$this->swapchain],
            [$this->currentImageIndex],
            [$this->renderFinishedSem],
        );
    }

    public function clear(Color $color): void
    {
        $this->clearR = $color->r;
        $this->clearG = $color->g;
        $this->clearB = $color->b;
    }

    public function setViewport(int $x, int $y, int $width, int $height): void
    {
        $this->width  = $width;
        $this->height = $height;
    }

    public function getWidth(): int { return $this->width; }
    public function getHeight(): int { return $this->height; }

    public function render(RenderCommandList $commandList): void
    {
        $identity = Mat4::identity()->toArray();
        $this->viewMatrix = $identity;
        $this->projMatrix = $identity;
        $this->ambient    = [1.0, 1.0, 1.0, 0.1];
        $this->dirLight   = [0.0, -1.0, 0.0, 0.0, 1.0, 1.0, 1.0];
        $this->albedo     = [0.8, 0.8, 0.8];
        $this->fog        = [0.5, 0.5, 0.5, 50.0, 200.0];
        $this->cameraPos  = [0.0, 0.0, 0.0];

        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof SetCamera) {
                $this->viewMatrix = $command->viewMatrix->toArray();
                $this->projMatrix = $command->projectionMatrix->toArray();
                $camPos           = $command->viewMatrix->inverse()->getTranslation();
                $this->cameraPos  = [$camPos->x, $camPos->y, $camPos->z];

            } elseif ($command instanceof SetAmbientLight) {
                $this->ambient = [
                    $command->color->r, $command->color->g, $command->color->b, $command->intensity,
                ];

            } elseif ($command instanceof SetDirectionalLight) {
                $this->dirLight = [
                    $command->direction->x, $command->direction->y, $command->direction->z,
                    $command->intensity,
                    $command->color->r, $command->color->g, $command->color->b,
                ];

            } elseif ($command instanceof AddPointLight && count($this->pointLights) < 8) {
                $this->pointLights[] = [
                    'pos'       => [$command->position->x, $command->position->y, $command->position->z],
                    'color'     => [$command->color->r, $command->color->g, $command->color->b],
                    'intensity' => $command->intensity,
                    'radius'    => $command->radius,
                ];

            } elseif ($command instanceof SetFog) {
                $this->fog = [
                    $command->color->r, $command->color->g, $command->color->b,
                    $command->near, $command->far,
                ];

            } elseif ($command instanceof SetSkybox) {
                // Skybox requires a separate pipeline — Phase 8+ TODO
            }
        }

        $this->uploadFrameUbo();
        $this->uploadLightingUbo();

        $this->commandBuffer->beginRenderPass(
            $this->renderPass,
            $this->framebuffers[$this->currentImageIndex],
            0, 0, $this->width, $this->height,
            [[$this->clearR, $this->clearG, $this->clearB, 1.0], [1.0, 0]],
        );
        $this->commandBuffer->setViewport(0.0, 0.0, (float) $this->width, (float) $this->height, 0.0, 1.0);
        $this->commandBuffer->setScissor(0, 0, $this->width, $this->height);
        $this->commandBuffer->bindPipeline(self::VK_PIPELINE_BIND_GRAPHICS, $this->pipeline);
        $this->commandBuffer->bindDescriptorSets(
            self::VK_PIPELINE_BIND_GRAPHICS, $this->pipelineLayout, 0, [$this->descriptorSet],
        );

        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof DrawMesh) {
                $this->resolveMaterial($command->materialId);
                $this->uploadLightingUbo();
                $this->drawMeshCommand($command->meshId, $command->modelMatrix);
            } elseif ($command instanceof DrawMeshInstanced) {
                $this->resolveMaterial($command->materialId);
                $this->uploadLightingUbo();
                foreach ($command->matrices as $matrix) {
                    $this->drawMeshCommand($command->meshId, $matrix);
                }
            }
        }

        $this->commandBuffer->endRenderPass();
    }

    private function resolveMaterial(string $materialId): void
    {
        $material = MaterialRegistry::get($materialId);
        if ($material !== null) {
            $this->albedo = [$material->albedo->r, $material->albedo->g, $material->albedo->b];
        } else {
            $this->albedo = [0.8, 0.8, 0.8];
        }
    }

    private function drawMeshCommand(string $meshId, Mat4 $modelMatrix): void
    {
        $meshData = MeshRegistry::get($meshId);
        if ($meshData === null) {
            return;
        }

        if (!isset($this->meshCache[$meshId])) {
            $this->uploadMesh($meshId, $meshData);
        }

        $modelBytes = pack('f16', ...$modelMatrix->toArray());
        $this->commandBuffer->pushConstants($this->pipelineLayout, self::VK_SHADER_STAGE_VERTEX, 0, $modelBytes);
        $this->commandBuffer->bindVertexBuffers(0, [$this->meshCache[$meshId]['vb']], [0]);
        $this->commandBuffer->bindIndexBuffer($this->meshCache[$meshId]['ib'], 0, self::VK_INDEX_TYPE_UINT32);
        $this->commandBuffer->drawIndexed($this->meshCache[$meshId]['count'], 1, 0, 0, 0);
    }

    private function uploadMesh(string $meshId, MeshData $meshData): void
    {
        $vertexCount = $meshData->vertexCount();
        $vertexData  = '';
        for ($i = 0; $i < $vertexCount; $i++) {
            $vertexData .= pack(
                'f8',
                $meshData->vertices[$i * 3], $meshData->vertices[$i * 3 + 1], $meshData->vertices[$i * 3 + 2],
                $meshData->normals[$i * 3],  $meshData->normals[$i * 3 + 1],  $meshData->normals[$i * 3 + 2],
                $meshData->uvs[$i * 2],      $meshData->uvs[$i * 2 + 1],
            );
        }

        $vb    = new Buffer($this->device, strlen($vertexData), self::VK_BUFFER_USAGE_VERTEX, self::VK_SHARING_EXCLUSIVE);
        $vbReq = $vb->getMemoryRequirements();
        $vbSize = $vbReq['size'];
        if (!is_int($vbSize)) {
            throw new \RuntimeException('Invalid vertex buffer memory requirements');
        }
        $vbMem = new DeviceMemory($this->device, $vbSize, $this->findMemory($vbReq, true));
        $vb->bindMemory($vbMem, 0);
        $vbMem->map(0, null);
        $vbMem->write($vertexData, 0);

        $indexData = '';
        foreach ($meshData->indices as $idx) {
            $indexData .= pack('V', $idx);
        }
        $ib    = new Buffer($this->device, strlen($indexData), self::VK_BUFFER_USAGE_INDEX, self::VK_SHARING_EXCLUSIVE);
        $ibReq = $ib->getMemoryRequirements();
        $ibSize = $ibReq['size'];
        if (!is_int($ibSize)) {
            throw new \RuntimeException('Invalid index buffer memory requirements');
        }
        $ibMem = new DeviceMemory($this->device, $ibSize, $this->findMemory($ibReq, true));
        $ib->bindMemory($ibMem, 0);
        $ibMem->map(0, null);
        $ibMem->write($indexData, 0);

        $this->meshCache[$meshId] = [
            'vb'    => $vb,
            'vbMem' => $vbMem,
            'ib'    => $ib,
            'ibMem' => $ibMem,
            'count' => count($meshData->indices),
        ];
    }

    private function uploadFrameUbo(): void
    {
        $data = pack('f16', ...$this->viewMatrix) . pack('f16', ...$this->projMatrix);
        $this->frameUboMem->write($data, 0);
    }

    private function uploadLightingUbo(): void
    {
        $data  = pack('f4', $this->ambient[0], $this->ambient[1], $this->ambient[2], $this->ambient[3]);
        $data .= pack('f4', $this->dirLight[0], $this->dirLight[1], $this->dirLight[2], $this->dirLight[3]);
        $data .= pack('f4', $this->dirLight[4], $this->dirLight[5], $this->dirLight[6], 0.0);
        $data .= pack('f4', $this->albedo[0], $this->albedo[1], $this->albedo[2], 0.0);
        $data .= pack('f4', $this->fog[0], $this->fog[1], $this->fog[2], $this->fog[3]);
        $data .= pack('f4', $this->cameraPos[0], $this->cameraPos[1], $this->cameraPos[2], $this->fog[4]);
        $plCount = count($this->pointLights);
        $data .= pack('l1f3', $plCount, 0.0, 0.0, 0.0);
        for ($i = 0; $i < 8; $i++) {
            if ($i < $plCount) {
                $pl = $this->pointLights[$i];
                $data .= pack('f4', $pl['pos'][0], $pl['pos'][1], $pl['pos'][2], $pl['intensity']);
                $data .= pack('f4', $pl['color'][0], $pl['color'][1], $pl['color'][2], $pl['radius']);
            } else {
                $data .= pack('f8', 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0);
            }
        }
        $this->lightingUboMem->write($data, 0);
    }

    private function initVulkan(\GLFWwindow $windowHandle): void
    {
        $this->ensureMacOSVulkanEnv();

        $this->instance = new Instance('PHPolygon', 1, 'PHPolygon', 1, null, false, [
            'VK_KHR_surface',
            'VK_EXT_metal_surface',
            'VK_KHR_portability_enumeration',
        ]);

        $this->surface = new Surface($this->instance, $windowHandle);

        $rawDevices = $this->instance->getPhysicalDevices();
        $firstDevice = $rawDevices[0] ?? null;
        if (!$firstDevice instanceof PhysicalDevice) {
            throw new \RuntimeException('No Vulkan physical devices found');
        }
        $this->gpu = $firstDevice;

        // Cache memory types for later use (avoids repeated mixed-type array access)
        $rawMemProps = $this->gpu->getMemoryProperties();
        $rawTypes    = $rawMemProps['types'] ?? [];
        if (is_array($rawTypes)) {
            foreach ($rawTypes as $t) {
                $this->memTypes[] = is_array($t) ? $t : [];
            }
        }

        $this->graphicsFamily = $this->selectQueueFamily();

        $this->device = new Device(
            $this->gpu,
            [['familyIndex' => $this->graphicsFamily, 'count' => 1]],
            ['VK_KHR_swapchain'],
            null,
        );
        $this->queue = $this->device->getQueue($this->graphicsFamily, 0);

        $this->createSwapchain();
        $this->createRenderPass();
        $this->createPipeline();
        $this->createUBOs();
        $this->createDescriptors();
        $this->createCommandObjects();
        $this->createSyncObjects();
    }

    private function selectQueueFamily(): int
    {
        $queueFamilies = $this->gpu->getQueueFamilies();
        if (!is_array($queueFamilies)) {
            throw new \RuntimeException('getQueueFamilies() did not return an array');
        }
        foreach ($queueFamilies as $qf) {
            if (!is_array($qf) || empty($qf['graphics'])) {
                continue;
            }
            $idx = $qf['index'];
            if (!is_int($idx)) {
                continue;
            }
            if ($this->gpu->getSurfaceSupport($idx, $this->surface)) {
                return $idx;
            }
        }
        throw new \RuntimeException('No Vulkan graphics+present queue family found');
    }

    private function createSwapchain(): void
    {
        $caps         = $this->surface->getCapabilities($this->gpu);
        $rawFormats   = $this->surface->getFormats($this->gpu);
        $presentModes = $this->surface->getPresentModes($this->gpu);

        $firstFormat = is_array($rawFormats) ? ($rawFormats[0] ?? []) : [];
        $format      = is_array($firstFormat) ? ($firstFormat['format'] ?? 44) : 44;
        $colorSpace  = is_array($firstFormat) ? ($firstFormat['colorSpace'] ?? 0) : 0;

        $this->surfaceFormat = is_int($format) ? $format : (int) $format;
        $colorSpaceInt       = is_int($colorSpace) ? $colorSpace : (int) $colorSpace;

        $hasFifo     = is_array($presentModes) && in_array(self::VK_PRESENT_MODE_FIFO, $presentModes, true);
        $presentMode = $hasFifo ? self::VK_PRESENT_MODE_FIFO : self::VK_PRESENT_MODE_FIFO;

        $minCount   = is_array($caps) ? ($caps['minImageCount'] ?? 2) : 2;
        $maxCount   = is_array($caps) ? ($caps['maxImageCount'] ?? 3) : 3;
        $transform  = is_array($caps) ? ($caps['currentTransform'] ?? 1) : 1;
        $imageCount = max(
            is_int($minCount) ? $minCount : (int) $minCount,
            min(3, $maxCount ? (is_int($maxCount) ? $maxCount : (int) $maxCount) : 3),
        );

        $this->swapchain = new Swapchain($this->device, $this->surface, [
            'minImageCount'    => $imageCount,
            'imageFormat'      => $this->surfaceFormat,
            'imageColorSpace'  => $colorSpaceInt,
            'imageExtent'      => ['width' => $this->width, 'height' => $this->height],
            'imageArrayLayers' => 1,
            'imageUsage'       => self::VK_IMAGE_USAGE_COLOR,
            'imageSharingMode' => self::VK_SHARING_EXCLUSIVE,
            'preTransform'     => is_int($transform) ? $transform : (int) $transform,
            'compositeAlpha'   => 1,
            'presentMode'      => $presentMode,
            'clipped'          => true,
        ]);

        $rawImages = $this->swapchain->getImages();
        if (!is_array($rawImages)) {
            throw new \RuntimeException('getImages() did not return an array');
        }
        foreach ($rawImages as $img) {
            if (!$img instanceof Image) {
                throw new \RuntimeException('Swapchain image is not a Vk\\Image');
            }
            $this->swapImageViews[] = new ImageView($this->device, $img, $this->surfaceFormat, self::VK_ASPECT_COLOR, 1);
        }
    }

    private function createRenderPass(): void
    {
        $this->renderPass = new RenderPass(
            $this->device,
            [
                [
                    'format'         => $this->surfaceFormat,
                    'samples'        => self::VK_SAMPLE_COUNT_1,
                    'loadOp'         => self::VK_LOAD_OP_CLEAR,
                    'storeOp'        => self::VK_STORE_OP_STORE,
                    'stencilLoadOp'  => self::VK_LOAD_OP_DONT_CARE,
                    'stencilStoreOp' => self::VK_STORE_OP_DONT_CARE,
                    'initialLayout'  => self::VK_LAYOUT_UNDEFINED,
                    'finalLayout'    => self::VK_LAYOUT_PRESENT_SRC,
                ],
                [
                    'format'         => self::VK_FORMAT_D32_SFLOAT,
                    'samples'        => self::VK_SAMPLE_COUNT_1,
                    'loadOp'         => self::VK_LOAD_OP_CLEAR,
                    'storeOp'        => self::VK_STORE_OP_DONT_CARE,
                    'stencilLoadOp'  => self::VK_LOAD_OP_DONT_CARE,
                    'stencilStoreOp' => self::VK_STORE_OP_DONT_CARE,
                    'initialLayout'  => self::VK_LAYOUT_UNDEFINED,
                    'finalLayout'    => self::VK_LAYOUT_DEPTH_ATTACHMENT,
                ],
            ],
            [
                [
                    'pipelineBindPoint' => self::VK_PIPELINE_BIND_GRAPHICS,
                    'colorAttachments'  => [['attachment' => 0, 'layout' => self::VK_LAYOUT_COLOR_ATTACHMENT]],
                    'depthAttachment'   => ['attachment' => 1, 'layout' => self::VK_LAYOUT_DEPTH_ATTACHMENT],
                ],
            ],
            [],
        );

        $depthImage = new Image(
            $this->device,
            $this->width,
            $this->height,
            self::VK_FORMAT_D32_SFLOAT,
            self::VK_IMAGE_USAGE_DEPTH,
            0,
            self::VK_SAMPLE_COUNT_1,
        );
        $depthReq  = $depthImage->getMemoryRequirements();
        $depthSize = $depthReq['size'];
        if (!is_int($depthSize)) {
            throw new \RuntimeException('Invalid depth image memory requirements');
        }
        $depthMem = new DeviceMemory($this->device, $depthSize, $this->findMemory($depthReq, false));
        $depthImage->bindMemory($depthMem, 0);
        $depthView = new ImageView($this->device, $depthImage, self::VK_FORMAT_D32_SFLOAT, self::VK_ASPECT_DEPTH, 1);

        foreach ($this->swapImageViews as $colorView) {
            $this->framebuffers[] = new Framebuffer(
                $this->device, $this->renderPass,
                [$colorView, $depthView],
                $this->width, $this->height, 1,
            );
        }
    }

    private function createPipeline(): void
    {
        $vertModule = ShaderModule::createFromFile($this->device, self::VERT_SPV);
        $fragModule = ShaderModule::createFromFile($this->device, self::FRAG_SPV);

        $this->descriptorSetLayout = new DescriptorSetLayout($this->device, [
            ['binding' => 0, 'descriptorType' => self::VK_DESCRIPTOR_UNIFORM_BUFFER, 'stageFlags' => self::VK_SHADER_STAGE_VERTEX],
            ['binding' => 1, 'descriptorType' => self::VK_DESCRIPTOR_UNIFORM_BUFFER, 'stageFlags' => self::VK_SHADER_STAGE_FRAGMENT],
        ]);

        $this->pipelineLayout = new PipelineLayout(
            $this->device,
            [$this->descriptorSetLayout],
            [['stageFlags' => self::VK_SHADER_STAGE_VERTEX, 'offset' => 0, 'size' => 64]],
        );

        $this->pipeline = Pipeline::createGraphics($this->device, [
            'renderPass'       => $this->renderPass,
            'layout'           => $this->pipelineLayout,
            'vertexShader'     => $vertModule,
            'fragmentShader'   => $fragModule,
            'vertexBindings'   => [
                ['binding' => 0, 'stride' => 32, 'inputRate' => self::VK_VERTEX_INPUT_RATE_VERTEX],
            ],
            'vertexAttributes' => [
                ['location' => 0, 'binding' => 0, 'format' => self::VK_FORMAT_R32G32B32_SFLOAT, 'offset' => 0],
                ['location' => 1, 'binding' => 0, 'format' => self::VK_FORMAT_R32G32B32_SFLOAT, 'offset' => 12],
                ['location' => 2, 'binding' => 0, 'format' => self::VK_FORMAT_R32G32_SFLOAT,    'offset' => 24],
            ],
            'cullMode'         => self::VK_CULL_MODE_BACK,
            'frontFace'        => self::VK_FRONT_FACE_CCW,
        ]);
    }

    private function createUBOs(): void
    {
        $this->frameUbo = new Buffer(
            $this->device, self::FRAME_UBO_SIZE, self::VK_BUFFER_USAGE_UNIFORM, self::VK_SHARING_EXCLUSIVE,
        );
        $req = $this->frameUbo->getMemoryRequirements();
        $reqSize = $req['size'];
        if (!is_int($reqSize)) {
            throw new \RuntimeException('Invalid frame UBO memory size');
        }
        $this->frameUboMem = new DeviceMemory($this->device, $reqSize, $this->findMemory($req, true));
        $this->frameUbo->bindMemory($this->frameUboMem, 0);
        $this->frameUboMem->map(0, null);

        $this->lightingUbo = new Buffer(
            $this->device, self::LIGHTING_UBO_SIZE, self::VK_BUFFER_USAGE_UNIFORM, self::VK_SHARING_EXCLUSIVE,
        );
        $req2 = $this->lightingUbo->getMemoryRequirements();
        $req2Size = $req2['size'];
        if (!is_int($req2Size)) {
            throw new \RuntimeException('Invalid lighting UBO memory size');
        }
        $this->lightingUboMem = new DeviceMemory($this->device, $req2Size, $this->findMemory($req2, true));
        $this->lightingUbo->bindMemory($this->lightingUboMem, 0);
        $this->lightingUboMem->map(0, null);
    }

    private function createDescriptors(): void
    {
        $this->descriptorPool = new DescriptorPool(
            $this->device, 1,
            [['type' => self::VK_DESCRIPTOR_UNIFORM_BUFFER, 'count' => 2]],
        );

        $rawSets = $this->descriptorPool->allocateSets([$this->descriptorSetLayout]);
        $firstSet = $rawSets[0] ?? null;
        if (!$firstSet instanceof DescriptorSet) {
            throw new \RuntimeException('Failed to allocate descriptor set');
        }
        $this->descriptorSet = $firstSet;
        $this->descriptorSet->writeBuffer(0, $this->frameUbo, 0, self::FRAME_UBO_SIZE, self::VK_DESCRIPTOR_UNIFORM_BUFFER);
        $this->descriptorSet->writeBuffer(1, $this->lightingUbo, 0, self::LIGHTING_UBO_SIZE, self::VK_DESCRIPTOR_UNIFORM_BUFFER);
    }

    private function createCommandObjects(): void
    {
        $this->commandPool = new CommandPool($this->device, $this->graphicsFamily, self::VK_CMD_POOL_RESET_CMD_BUFFER);
        $rawCmds = $this->commandPool->allocateBuffers(1, true);
        $firstCmd = $rawCmds[0] ?? null;
        if (!$firstCmd instanceof \Vk\CommandBuffer) {
            throw new \RuntimeException('Failed to allocate command buffer');
        }
        $this->commandBuffer = $firstCmd;
    }

    private function createSyncObjects(): void
    {
        $this->imageAvailableSem = new Semaphore($this->device, false, 0);
        $this->renderFinishedSem = new Semaphore($this->device, false, 0);
        $this->inFlightFence     = new Fence($this->device, true);
    }

    /** @param array<mixed> $memReqs */
    private function findMemory(array $memReqs, bool $hostVisible): int
    {
        $bitsRaw = $memReqs['memoryTypeBits'] ?? 0;
        $bits    = is_int($bitsRaw) ? $bitsRaw : (int) $bitsRaw;

        foreach ($this->memTypes as $i => $t) {
            if (!($bits & (1 << $i))) {
                continue;
            }
            if ($hostVisible) {
                if (!empty($t['hostVisible']) && !empty($t['hostCoherent'])) {
                    return $i;
                }
            } else {
                if (!empty($t['deviceLocal'])) {
                    return $i;
                }
            }
        }
        throw new \RuntimeException('No suitable Vulkan memory type found');
    }

    private function ensureMacOSVulkanEnv(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return;
        }
        foreach (['/opt/homebrew/lib', '/usr/local/lib'] as $libDir) {
            if (file_exists("{$libDir}/libvulkan.dylib")) {
                $icd = dirname($libDir) . '/etc/vulkan/icd.d/MoltenVK_icd.json';
                if (!getenv('DYLD_LIBRARY_PATH')) {
                    putenv("DYLD_LIBRARY_PATH={$libDir}");
                }
                if (!getenv('VK_ICD_FILENAMES') && file_exists($icd)) {
                    putenv("VK_ICD_FILENAMES={$icd}");
                }
                return;
            }
        }
    }
}
