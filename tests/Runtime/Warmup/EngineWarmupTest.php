<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime\Warmup;

use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Runtime\Warmup\CallbackWarmupTask;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Engine integration without a GPU: the headless path of Engine::runWarmup(). */
final class EngineWarmupTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHPOLYGON_WARMUP');
    }

    private function runEngine(Engine $engine, ?callable $onInit = null): array
    {
        $order = [];
        $engine->onInit(function (Engine $e) use (&$order, $onInit): void {
            $order[] = 'init';
            if ($onInit !== null) {
                $onInit($e);
            }
        });
        $engine->onUpdate(function (Engine $e, float $dt) use (&$order): void {
            $order[] = 'update';
            $e->stop();
        });
        $engine->run();
        return $order;
    }

    public function testWithoutTasksNothingChanges(): void
    {
        $engine = new Engine(new EngineConfig(headless: true));
        $this->assertSame(['init', 'update'], $this->runEngine($engine));
        $this->assertTrue($engine->warmup()->isEmpty());
        $this->assertTrue($engine->warmup()->finished());
    }

    public function testTasksRegisteredInOnInitRunAfterItAndBeforeTheFirstUpdate(): void
    {
        $engine = new Engine(new EngineConfig(headless: true));
        $events = [];
        $order = $this->runEngine($engine, function (Engine $e) use (&$events): void {
            $e->addWarmupTask(new CallbackWarmupTask('a', function () use (&$events): void {
                $events[] = 'a';
            }));
            $e->addWarmupTask(new CallbackWarmupTask('b', function () use (&$events): \Generator {
                $events[] = 'b1';
                yield 0.5;
                $events[] = 'b2';
            }, 'warm.b', 2.0, ['a']));
            $e->addWarmupTask(new CallbackWarmupTask('bad', function (): void {
                throw new \RuntimeException('no');
            }));
        });
        $this->assertSame(['a', 'b1', 'b2'], $events);
        $this->assertSame(['init', 'update'], $order);
        $res = $engine->warmup()->results();
        $this->assertSame('done', $res['b']['status']);
        $this->assertSame('failed', $res['bad']['status']);
        $this->assertSame(1.0, $engine->warmup()->fraction());
    }

    public function testConfigOptOutRunsNothing(): void
    {
        $engine = new Engine(new EngineConfig(headless: true, warmupEnabled: false));
        $ran = false;
        $this->runEngine($engine, function (Engine $e) use (&$ran): void {
            $e->addWarmupTask(new CallbackWarmupTask('a', function () use (&$ran): void {
                $ran = true;
            }));
        });
        $this->assertFalse($ran);
        $this->assertSame('skipped', $engine->warmup()->results()['a']['status']);
    }

    #[DataProvider('offValues')]
    public function testEnvOptOutRunsNothing(string $value): void
    {
        putenv('PHPOLYGON_WARMUP=' . $value);
        $engine = new Engine(new EngineConfig(headless: true));
        $ran = false;
        $this->runEngine($engine, function (Engine $e) use (&$ran): void {
            $e->addWarmupTask(new CallbackWarmupTask('a', function () use (&$ran): void {
                $ran = true;
            }));
        });
        $this->assertFalse($ran);
    }

    /** @return array<string, array{string}> */
    public static function offValues(): array
    {
        return ['zero' => ['0'], 'off' => ['off'], 'false' => ['False'], 'no' => ['NO']];
    }

    public function testEnvValueOneKeepsItOn(): void
    {
        putenv('PHPOLYGON_WARMUP=1');
        $engine = new Engine(new EngineConfig(headless: true));
        $ran = false;
        $this->runEngine($engine, function (Engine $e) use (&$ran): void {
            $e->addWarmupTask(new CallbackWarmupTask('a', function () use (&$ran): void {
                $ran = true;
            }));
        });
        $this->assertTrue($ran);
    }

    public function testTasksAFinishedGameRunnerDoesNotRunAgain(): void
    {
        $engine = new Engine(new EngineConfig(headless: true));
        $count = 0;
        $this->runEngine($engine, function (Engine $e) use (&$count): void {
            $e->addWarmupTask(new CallbackWarmupTask('a', function () use (&$count): void {
                $count++;
            }));
            // a game with a boot screen of its own drives the runner itself
            $e->warmup()->drain();
        });
        $this->assertSame(1, $count);
    }
}
