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

    public function testGameplayImportEmitsRestartAndAnimationSystem(): void
    {
        $php = (new GameEntryGenerator())->generate([
            'name' => 'Platformer',
            '_scene' => 'App\\Scene\\Platformer',
            'systems' => [
                'PHPolygon\\System\\PlatformerControllerSystem',
                'PHPolygon\\System\\PlatformerAnimationSystem',
                'PHPolygon\\System\\FollowCameraSystem',
            ],
        ]);

        // Restart-on-[R]: rebuilds the scene after win / game over.
        $this->assertStringContainsString('isKeyPressed(82)', $php);
        $this->assertStringContainsString('$engine->world->clear()', $php);
        $this->assertStringContainsString('[R] = Neustart', $php);

        // The procedural character animation system is wired up.
        $this->assertStringContainsString('use PHPolygon\\System\\PlatformerAnimationSystem;', $php);
        $this->assertStringContainsString('new PlatformerAnimationSystem()', $php);

        // Win / lose banners are present.
        $this->assertStringContainsString("'GAME OVER'", $php);
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

    public function testGeneratedGameplaySourceIsSyntacticallyValid(): void
    {
        // The original syntax-test above only exercises the STATIC template.
        // The gameplay heredoc (restart handler, animation system registration,
        // HUD) is ~100 lines of generated PHP and was previously untested by
        // `php -l` — a typo in any of those lines would ship green.
        $php = (new GameEntryGenerator())->generate([
            'name' => 'Platformer',
            '_scene' => 'App\\Scene\\Platformer',
            'systems' => [
                'PHPolygon\\System\\PlatformerControllerSystem',
                'PHPolygon\\System\\PatrolSystem',
                'PHPolygon\\System\\StompSystem',
                'PHPolygon\\System\\CollectibleSystem',
                'PHPolygon\\System\\GoalSystem',
                'PHPolygon\\System\\SpinBobSystem',
                'PHPolygon\\System\\PlatformerAnimationSystem',
                'PHPolygon\\System\\FollowCameraSystem',
                'PHPolygon\\System\\Transform3DSystem',
                'PHPolygon\\System\\Camera3DSystem',
                'PHPolygon\\System\\Renderer3DSystem',
            ],
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'phpolygon-game-gp-') . '.php';
        file_put_contents($tmp, $php);
        try {
            $output = [];
            $code = 0;
            exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $code);
            $this->assertSame(0, $code, 'generated gameplay game.php must be valid PHP: ' . implode("\n", $output));
        } finally {
            unlink($tmp);
        }
    }
}
