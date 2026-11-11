<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Event;

use PHPUnit\Framework\TestCase;
use PHPolygon\Event\EventDispatcher;
use PHPolygon\Log\Log;
use PHPolygon\Tests\Log\RecordingLogWriter;

final class EventDispatcherLoggingTestScalarEvent
{
    public function __construct(
        public readonly string $name,
        public readonly int $count,
        public readonly ?float $ratio = null,
    ) {}
}

final class EventDispatcherLoggingTestMixedEvent
{
    public string $label = 'x';
    public object $payload; // never scalar - must be skipped, never crash reading it

    public function __construct()
    {
        $this->payload = new \stdClass();
    }
}

final class EventDispatcherLoggingTestUninitializedEvent
{
    public string $set = 'value';
    public string $neverSet; // typed, never assigned - reading it throws
}

final class EventDispatcherLoggingTest extends TestCase
{
    private RecordingLogWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new RecordingLogWriter();
    }

    protected function tearDown(): void
    {
        Log::reset();
        parent::tearDown();
    }

    public function testHooksOffLogsNothing(): void
    {
        Log::startWithWriter($this->writer, hooks: false);
        $dispatcher = new EventDispatcher();

        $dispatcher->dispatch(new EventDispatcherLoggingTestScalarEvent('a', 1));

        $this->assertSame([], $this->writer->writes);
    }

    public function testDispatchLogsTheClassAndItsPublicScalarFields(): void
    {
        Log::startWithWriter($this->writer, hooks: true);
        $dispatcher = new EventDispatcher();

        $dispatcher->dispatch(new EventDispatcherLoggingTestScalarEvent('terminal_01', 3, 0.5));

        $log = $this->writer->all();
        $this->assertStringContainsString('[event]', $log);
        $this->assertStringContainsString(EventDispatcherLoggingTestScalarEvent::class, $log);
        $this->assertStringContainsString('name=terminal_01', $log);
        $this->assertStringContainsString('count=3', $log);
        $this->assertStringContainsString('ratio=0.5', $log);
    }

    public function testNonScalarPublicFieldsAreSilentlyOmitted(): void
    {
        Log::startWithWriter($this->writer, hooks: true);
        $dispatcher = new EventDispatcher();

        $dispatcher->dispatch(new EventDispatcherLoggingTestMixedEvent());

        $log = $this->writer->all();
        $this->assertStringContainsString('label=x', $log);
        $this->assertStringNotContainsString('payload', $log);
    }

    public function testAnUninitializedTypedPropertyDoesNotCrashTheHook(): void
    {
        Log::startWithWriter($this->writer, hooks: true);
        $dispatcher = new EventDispatcher();

        $dispatcher->dispatch(new EventDispatcherLoggingTestUninitializedEvent());

        $log = $this->writer->all();
        $this->assertStringContainsString('set=value', $log);
        $this->assertStringNotContainsString('neverSet', $log);
    }

    public function testListenersStillRunAfterTheHookLogs(): void
    {
        Log::startWithWriter($this->writer, hooks: true);
        $dispatcher = new EventDispatcher();
        $heard = null;
        $dispatcher->listen(EventDispatcherLoggingTestScalarEvent::class, function (EventDispatcherLoggingTestScalarEvent $e) use (&$heard): void {
            $heard = $e->name;
        });

        $dispatcher->dispatch(new EventDispatcherLoggingTestScalarEvent('x', 1));

        $this->assertSame('x', $heard);
    }
}
