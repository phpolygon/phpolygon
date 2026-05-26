<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Scene;

use PHPUnit\Framework\TestCase;
use PHPolygon\Scene\Transpiler\GameEntryGenerator;

class GameEntryGeneratorTest extends TestCase
{
    public function testFramesCameraToSceneBounds(): void
    {
        $php = (new GameEntryGenerator())->generate([
            'name' => 'Platformer',
            'bounds' => ['min' => [-4, -2.9, -100], 'max' => [4, 8.5, 0.34]],
        ]);

        // Scene reference resolves to App\Scene\<ClassName>.
        $this->assertStringContainsString('use App\\Scene\\Platformer;', $php);
        $this->assertStringContainsString('(new Platformer())->build($builder)', $php);

        // Camera framed on bounds: cx=0, camY=maxY+5=13.5, camZ=maxZ+12=12.34.
        $this->assertStringContainsString('new Vec3(0.0, 13.5, 12.34)', $php);

        // Always emits a sky (colour buffer is otherwise uninitialised) ...
        $this->assertStringContainsString('new SetSky(', $php);
        // ... and always imports what it uses (the class of bug we hit by hand).
        $this->assertStringContainsString('use PHPolygon\\Rendering\\Color;', $php);
        $this->assertStringContainsString('use PHPolygon\\Rendering\\Command\\SetSky;', $php);
        $this->assertStringContainsString('is3D:   true', $php);
    }

    public function testDefaultCameraWhenNoBounds(): void
    {
        $php = (new GameEntryGenerator())->generate(['name' => 'demo_scene']);

        // nameToClassName: demo_scene -> DemoScene.
        $this->assertStringContainsString('use App\\Scene\\DemoScene;', $php);
        $this->assertStringContainsString('new Vec3(0.0, 8.0, 14.0)', $php);
    }

    public function testGeneratedSourceIsSyntacticallyValid(): void
    {
        $php = (new GameEntryGenerator())->generate([
            'name' => 'Lvl',
            'bounds' => ['min' => [0, 0, -10], 'max' => [5, 3, 2]],
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'phpolygon-game-') . '.php';
        file_put_contents($tmp, $php);
        try {
            $output = [];
            $code = 0;
            exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $code);
            $this->assertSame(0, $code, 'generated game.php must be valid PHP: ' . implode("\n", $output));
        } finally {
            unlink($tmp);
        }
    }
}
