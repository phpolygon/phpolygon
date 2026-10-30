<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\VioRenderer2D;
use PHPolygon\Rendering\VioTextureManager;

/**
 * An unloaded texture must really go, also with a 2D renderer attached, which
 * holds every texture for its sprites: but only after the frames in flight, which
 * may still read it. Runs on php-vio's null backend, no GPU involved.
 */
#[RequiresPhpExtension('vio')]
final class VioTextureManagerUnloadTest extends TestCase
{
    private const int FRAMES_IN_FLIGHT = 8;

    private ?\VioContext $ctx = null;

    private VioTextureManager $textures;

    private VioRenderer2D $renderer;

    protected function setUp(): void
    {
        $ctx = @vio_create('null', ['width' => 16, 'height' => 16, 'headless' => true]);
        if ($ctx === false) {
            $this->markTestSkipped('no null backend');
        }
        $this->ctx = $ctx;
        $this->textures = new VioTextureManager($ctx);
        $this->renderer = new VioRenderer2D($ctx);
        $this->textures->setRenderer($this->renderer);
    }

    protected function tearDown(): void
    {
        unset($this->textures, $this->renderer);
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    /** @return \WeakReference<\VioTexture> */
    private function load(string $id): \WeakReference
    {
        $this->textures->loadRgba($id, 4, 4, str_repeat("\xFF", 4 * 4 * 4));
        $texture = $this->textures->vioTexture($id);
        self::assertNotNull($texture);

        return \WeakReference::create($texture);
    }

    private function frames(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->renderer->beginFrame();
            $this->renderer->endFrame();
        }
    }

    public function testUnloadedTextureLivesThroughTheFramesInFlightThenGoes(): void
    {
        $texture = $this->load('text');

        $this->textures->unload('text');
        self::assertFalse($this->textures->has('text'));
        $this->frames(self::FRAMES_IN_FLIGHT - 1);
        self::assertNotNull($texture->get(), 'a frame in flight may still read it');

        $this->frames(2);
        self::assertNull($texture->get(), 'the 2D renderer must not keep it alive');
    }

    public function testClearReleasesEveryTexture(): void
    {
        $a = $this->load('a');
        $b = $this->load('b');

        $this->textures->clear();
        $this->frames(self::FRAMES_IN_FLIGHT + 1);

        self::assertNull($a->get());
        self::assertNull($b->get());
    }

    public function testOtherTexturesStay(): void
    {
        $kept = $this->load('kept');
        $this->load('gone');

        $this->textures->unload('gone');
        $this->frames(self::FRAMES_IN_FLIGHT + 1);

        self::assertNotNull($kept->get());
        self::assertTrue($this->textures->has('kept'));
    }
}
