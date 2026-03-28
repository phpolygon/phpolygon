<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Thread\Subsystem;

use PHPUnit\Framework\TestCase;
use PHPolygon\ECS\World;
use PHPolygon\Thread\NullThreadScheduler;
use PHPolygon\Thread\Subsystem\AssetStreamingSubsystem;

class AssetStreamingSubsystemTest extends TestCase
{
    public function testRequestLoadQueuesRequests(): void
    {
        $subsystem = new AssetStreamingSubsystem();
        $subsystem->requestLoad('texture', 'brick', '/path/to/brick.png');
        $subsystem->requestLoad('audio', 'boom', '/path/to/boom.wav');

        $world = new World();
        $input = $subsystem->prepareInput($world, 0.016);

        $this->assertCount(2, $input['requests']);
        $this->assertSame('texture', $input['requests'][0]['type']);
        $this->assertSame('brick', $input['requests'][0]['id']);
        $this->assertSame('audio', $input['requests'][1]['type']);

        // Should be flushed
        $input2 = $subsystem->prepareInput($world, 0.016);
        $this->assertCount(0, $input2['requests']);
    }

    public function testComputeHandlesNonExistentFile(): void
    {
        $result = AssetStreamingSubsystem::compute([
            'requests' => [
                ['type' => 'texture', 'id' => 'missing', 'path' => '/nonexistent/file.png'],
            ],
        ]);

        $this->assertEmpty($result['results']);
    }

    public function testComputeHandlesEmptyRequests(): void
    {
        $result = AssetStreamingSubsystem::compute([
            'requests' => [],
        ]);

        $this->assertEmpty($result['results']);
    }

    public function testComputeLoadsRealPngFile(): void
    {
        // Create a small test PNG in memory
        $tmpFile = tempnam(sys_get_temp_dir(), 'phpolygon_test_');
        $this->assertNotFalse($tmpFile);
        $tmpFile .= '.png';

        $img = imagecreatetruecolor(4, 4);
        $this->assertNotFalse($img);
        $red = imagecolorallocate($img, 255, 0, 0);
        $this->assertNotFalse($red);
        imagefill($img, 0, 0, $red);
        imagepng($img, $tmpFile);
        imagedestroy($img);

        try {
            $result = AssetStreamingSubsystem::compute([
                'requests' => [
                    ['type' => 'texture', 'id' => 'test_red', 'path' => $tmpFile],
                ],
            ]);

            $this->assertCount(1, $result['results']);
            $loaded = $result['results'][0];
            $this->assertSame('test_red', $loaded['id']);
            $this->assertSame('texture', $loaded['type']);
            $this->assertSame(4, $loaded['width']);
            $this->assertSame(4, $loaded['height']);
            $this->assertSame(4, $loaded['channels']);
            // 4x4 pixels * 4 channels (RGBA) = 64 bytes
            $this->assertSame(64, strlen($loaded['data']));
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testComputeLoadsAudioFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'phpolygon_audio_');
        $this->assertNotFalse($tmpFile);
        file_put_contents($tmpFile, str_repeat("\x00", 100));

        try {
            $result = AssetStreamingSubsystem::compute([
                'requests' => [
                    ['type' => 'audio', 'id' => 'test_audio', 'path' => $tmpFile],
                ],
            ]);

            $this->assertCount(1, $result['results']);
            $loaded = $result['results'][0];
            $this->assertSame('test_audio', $loaded['id']);
            $this->assertSame('audio', $loaded['type']);
            $this->assertSame(100, $loaded['size']);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testNullSchedulerRoundTrip(): void
    {
        $world = new World();

        $scheduler = new NullThreadScheduler();
        $scheduler->register('assets', AssetStreamingSubsystem::class);
        $scheduler->boot();

        $scheduler->sendAll($world, 0.016);
        $scheduler->recvAll($world);

        $scheduler->shutdown();
        $this->assertTrue(true);
    }
}
