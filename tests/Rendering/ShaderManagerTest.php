<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Command\SetShader;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\ShaderDefinition;
use PHPolygon\Rendering\ShaderManager;
use PHPolygon\Rendering\ShaderRegistry;

class ShaderManagerTest extends TestCase
{
    private RenderCommandList $commandList;
    private ShaderManager $manager;

    protected function setUp(): void
    {
        ShaderRegistry::clear();
        $this->commandList = new RenderCommandList();
        $this->manager = new ShaderManager($this->commandList);
    }

    public function testRegisterDelegatesToShaderRegistry(): void
    {
        $def = new ShaderDefinition('/v.glsl', '/f.glsl');
        $this->manager->register('custom', $def);

        $this->assertTrue(ShaderRegistry::has('custom'));
        $this->assertSame($def, ShaderRegistry::get('custom'));
    }

    public function testHasDelegatesToShaderRegistry(): void
    {
        $this->assertFalse($this->manager->has('missing'));

        ShaderRegistry::register('present', new ShaderDefinition('/v', '/f'));
        $this->assertTrue($this->manager->has('present'));
    }

    public function testGetDelegatesToShaderRegistry(): void
    {
        $this->assertNull($this->manager->get('missing'));

        $def = new ShaderDefinition('/v', '/f');
        ShaderRegistry::register('test', $def);
        $this->assertSame($def, $this->manager->get('test'));
    }

    public function testAvailableListsAllRegistered(): void
    {
        ShaderRegistry::register('a', new ShaderDefinition('/a.v', '/a.f'));
        ShaderRegistry::register('b', new ShaderDefinition('/b.v', '/b.f'));

        $available = $this->manager->available();
        $this->assertContains('a', $available);
        $this->assertContains('b', $available);
    }

    public function testUseEmitsSetShaderCommand(): void
    {
        $this->manager->use('unlit');

        $commands = $this->commandList->ofType(SetShader::class);
        $this->assertCount(1, $commands);
        $this->assertSame('unlit', $commands[0]->shaderId);
    }

    public function testUseSetsActiveOverride(): void
    {
        $this->assertNull($this->manager->active());
        $this->assertFalse($this->manager->isOverridden());

        $this->manager->use('normals');

        $this->assertSame('normals', $this->manager->active());
        $this->assertTrue($this->manager->isOverridden());
    }

    public function testResetEmitsNullSetShaderCommand(): void
    {
        $this->manager->use('unlit');
        $this->manager->reset();

        $commands = $this->commandList->ofType(SetShader::class);
        $this->assertCount(2, $commands);
        $this->assertSame('unlit', $commands[0]->shaderId);
        $this->assertNull($commands[1]->shaderId);
    }

    public function testResetClearsActiveOverride(): void
    {
        $this->manager->use('depth');
        $this->manager->reset();

        $this->assertNull($this->manager->active());
        $this->assertFalse($this->manager->isOverridden());
    }

    public function testUseAndResetSequence(): void
    {
        $this->manager->use('unlit');
        $this->assertSame('unlit', $this->manager->active());

        $this->manager->use('normals');
        $this->assertSame('normals', $this->manager->active());

        $this->manager->reset();
        $this->assertNull($this->manager->active());

        $commands = $this->commandList->ofType(SetShader::class);
        $this->assertCount(3, $commands);
        $this->assertSame('unlit', $commands[0]->shaderId);
        $this->assertSame('normals', $commands[1]->shaderId);
        $this->assertNull($commands[2]->shaderId);
    }

    public function testTheOverrideIsInTheListAgainAfterEveryFrameClear(): void
    {
        $this->manager->use('unlit');

        // The renderer drops its override at the start of each frame and the system clears the list after
        // submitting it: without a repeat the override held for exactly one frame (ShaderQuality::Unlit never took).
        for ($frame = 0; $frame < 3; $frame++) {
            $this->commandList->clear();
            $commands = $this->commandList->getCommands();
            $this->assertCount(1, $commands, "frame {$frame}");
            $this->assertInstanceOf(SetShader::class, $commands[0]);
            $this->assertSame('unlit', $commands[0]->shaderId);
        }
    }

    public function testTheOverrideComesFirstSoItReachesEveryDraw(): void
    {
        $this->manager->use('normals');
        $this->commandList->clear();
        $this->commandList->add(new \PHPolygon\Rendering\Command\SetAmbientLight(new \PHPolygon\Rendering\Color(1.0, 1.0, 1.0), 1.0));

        $this->assertInstanceOf(SetShader::class, $this->commandList->getCommands()[0]);
    }

    public function testResetStopsTheRepeat(): void
    {
        $this->manager->use('unlit');
        $this->manager->reset();

        $this->commandList->clear();
        $this->assertTrue($this->commandList->isEmpty());
    }

    public function testASecondUseReplacesTheRepeatedOverride(): void
    {
        $this->manager->use('unlit');
        $this->manager->use('depth');

        $this->commandList->clear();
        $commands = $this->commandList->getCommands();
        $this->assertCount(1, $commands);
        $this->assertSame('depth', $commands[0]->shaderId);
    }

    public function testAListWithoutAnOverrideClearsToEmptyAsBefore(): void
    {
        $this->commandList->add(new \PHPolygon\Rendering\Command\SetAmbientLight(new \PHPolygon\Rendering\Color(1.0, 1.0, 1.0), 1.0));
        $this->commandList->clear();

        $this->assertTrue($this->commandList->isEmpty());
    }

    public function testRenderedFramesCarryTheOverrideThroughTheSystem(): void
    {
        $renderer = new \PHPolygon\Rendering\NullRenderer3D(64, 64);
        $system = new \PHPolygon\System\Renderer3DSystem($renderer, $this->commandList);
        $world = new \PHPolygon\ECS\World();
        $this->manager->use('unlit');

        for ($frame = 0; $frame < 3; $frame++) {
            $system->render($world);
            $sent = $renderer->getLastCommandList()?->ofType(SetShader::class) ?? [];
            $this->assertNotEmpty($sent, "frame {$frame}: the renderer saw no SetShader");
            $this->assertSame('unlit', $sent[0]->shaderId, "frame {$frame}");
        }

        $this->manager->reset();
        $system->render($world);
        $afterReset = $renderer->getLastCommandList()?->ofType(SetShader::class) ?? [];
        // The frame in which reset() ran still holds the override put back by the last clear(); the last
        // SetShader before any draw is the null that ends it.
        $this->assertNull($afterReset[array_key_last($afterReset)]->shaderId);
        $system->render($world);
        $this->assertSame([], $renderer->getLastCommandList()?->ofType(SetShader::class) ?? []);
    }

    public function testWorksWithNullCommandList(): void
    {
        $manager = new ShaderManager(null);

        // Should not throw even without a command list
        $manager->use('unlit');
        $this->assertSame('unlit', $manager->active());

        $manager->reset();
        $this->assertNull($manager->active());
    }
}
