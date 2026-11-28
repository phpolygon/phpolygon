<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use PHPolygon\Audio\Backend\PHPGLFWAudioBackend;
use PHPolygon\Audio\VioAudioBackend;

/** A clip a backend cannot open stays silent; it never ends the game. */
final class UnplayableClipTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGlfwBackendSurvivesAClipTheDecoderRefuses(): void
    {
        if (class_exists('GL\Audio\Engine')) {
            self::markTestSkipped('needs a PHP without php-glfw to stand in a refusing engine');
        }
        eval(<<<'PHP'
            namespace GL\Audio;
            class Sound {}
            class Engine {
                public static int $opened = 0;
                public function __construct(array $options = []) {}
                public function start(): void {}
                public function stop(): void {}
                public function soundFromDisk(string $path): Sound {
                    self::$opened++;
                    throw new \Error("Failed to load sound '{$path}' from disk");
                }
            }
            PHP);

        $backend = new PHPGLFWAudioBackend();
        $backend->load('chime', __FILE__);

        self::assertSame(0, $backend->play('chime'));
        self::assertSame(0, $backend->play('chime'));
        self::assertSame(1, \GL\Audio\Engine::$opened, 'a refused file is not retried on every play');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGlfwBackendSurvivesMissingAndUnknownClips(): void
    {
        if (class_exists('GL\Audio\Engine')) {
            self::markTestSkipped('needs a PHP without php-glfw to stand in an engine');
        }
        eval('namespace GL\Audio; class Sound {} class Engine { public function __construct(array $o = []) {} public function start(): void {} public function stop(): void {} }');

        $backend = new PHPGLFWAudioBackend();
        $backend->load('gone', __DIR__ . '/does-not-exist.opus');

        self::assertSame(0, $backend->play('gone'));
        self::assertSame(0, $backend->play('never-loaded'));
    }

    public function testVioBackendSurvivesMissingAndUnknownClips(): void
    {
        if (!function_exists('vio_audio_load')) {
            self::markTestSkipped('php-vio is not loaded');
        }
        $backend = new VioAudioBackend();
        $backend->load('gone', __DIR__ . '/does-not-exist.opus');

        self::assertSame(0, $backend->play('gone'));
        self::assertSame(0, $backend->play('never-loaded'));
    }
}
