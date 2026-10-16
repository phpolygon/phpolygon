<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Speech;

use PHPolygon\Audio\Speech\ChatterboxSynthesizer;
use PHPolygon\Audio\Speech\SpeechConfig;
use PHPolygon\Audio\Speech\SpeechJob;
use PHPolygon\Audio\Speech\SpeechParams;
use PHPolygon\Platform\SteamLanguage;
use PHPUnit\Framework\TestCase;

final class ChatterboxSynthesizerTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHPOLYGON_SPEECH_PYTHON');
        putenv('PHPOLYGON_SPEECH_RUNNER');
        putenv('PHPOLYGON_SPEECH_GPU');
        putenv('PHPOLYGON_SPEECH_WORKERS');
        putenv('PHPOLYGON_SPEECH_DEVICE');
        putenv('PHPOLYGON_SPEECH_CPU_THREADS');
        putenv('FAKE_SPEECH_CUDA');
        putenv('FAKE_SPEECH_CPU');
        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->dir);
        }
    }

    private string $dir = '';

    /** @var list<string> */
    private array $log = [];

    public function testSpeaksTwentyTwoOfTheSteamLanguages(): void
    {
        $synthesizer = new ChatterboxSynthesizer('/game');
        $spoken = [];
        $silent = [];
        foreach (SteamLanguage::API_CODES as $tag) {
            $language = $synthesizer->language($tag);
            if ($language === null) {
                $silent[] = $tag;
            } else {
                $spoken[$tag] = $language;
            }
        }

        self::assertCount(22, $spoken);
        self::assertSame(['bg', 'zh-TW', 'cs', 'hu', 'id', 'ro', 'th', 'uk', 'vi'], $silent);
        self::assertSame('zh', $spoken['zh-CN']);
        self::assertSame('pt', $spoken['pt-BR']);
        self::assertSame('es', $spoken['es-419']);
    }

    public function testLocaleSpellings(): void
    {
        $synthesizer = new ChatterboxSynthesizer('/game');

        self::assertSame('de', $synthesizer->language('de_DE'));
        self::assertSame('no', $synthesizer->language('nb'));
        self::assertSame('zh', $synthesizer->language('zh-Hans'));
        self::assertNull($synthesizer->language('zh-Hant'));
        self::assertNull($synthesizer->language('xx'));
    }

    public function testNativeCommandRunsTheWorkerInTheEnvironment(): void
    {
        $python = (string) tempnam(sys_get_temp_dir(), 'python');
        putenv("PHPOLYGON_SPEECH_PYTHON={$python}");
        $synthesizer = new ChatterboxSynthesizer('/game', toolsDir: '/engine/tools/speech');

        try {
            self::assertSame(SpeechConfig::RUNNER_NATIVE, $synthesizer->resolveRunner());
            self::assertSame(
                [$python, '/engine/tools/speech/chatterbox_worker.py', '--jobs', '-', '--device', 'cpu', '--threads', '4'],
                $synthesizer->command(SpeechConfig::RUNNER_NATIVE, 'cpu', 4),
            );
        } finally {
            unlink($python);
        }
    }

    public function testNativeRunnerWithoutEnvironmentPointsToSetup(): void
    {
        putenv('PHPOLYGON_SPEECH_PYTHON=/nonexistent/python');

        $this->expectExceptionMessage('run: vendor/bin/phpolygon speech:setup');
        (new ChatterboxSynthesizer('/game', SpeechConfig::RUNNER_NATIVE))->resolveRunner();
    }

    public function testDockerCommandMountsTheProjectAndModels(): void
    {
        $synthesizer = new ChatterboxSynthesizer('/game', SpeechConfig::RUNNER_DOCKER, gpu: true);
        $command = $synthesizer->command(SpeechConfig::RUNNER_DOCKER, 'cuda');

        self::assertSame(['docker', 'run', '-i', '--rm', '-v', '/game:/work', '-v', ChatterboxSynthesizer::MODEL_VOLUME . ':/models'], array_slice($command, 0, 8));
        self::assertContains('--gpus', $command);
        self::assertSame(['phpolygon-speech:chatterbox-' . ChatterboxSynthesizer::VERSION . '-cuda', '--jobs', '-', '--device', 'cuda'], array_slice($command, -5));
        self::assertNotContains('--gpus', $synthesizer->command(SpeechConfig::RUNNER_DOCKER, 'cpu', 4));
    }

    public function testOptionsWithoutBuildJsonHonourTheEnvironment(): void
    {
        self::assertStringEndsWith('-cuda', ChatterboxSynthesizer::fromOptions('/game', gpu: true)->image());

        putenv('PHPOLYGON_SPEECH_GPU=0');
        self::assertStringEndsNotWith('-cuda', ChatterboxSynthesizer::fromOptions('/game', gpu: true)->image());

        putenv('PHPOLYGON_SPEECH_RUNNER=bogus');
        $this->expectExceptionMessage('unknown speech runner "bogus"');
        ChatterboxSynthesizer::fromOptions('/game')->resolveRunner();
    }

    public function testEnvironmentOverridesTheConfig(): void
    {
        $config = SpeechConfig::fromBuildJson(['keys' => ['a'], 'voices' => ['n' => ['reference' => 'n.wav']], 'runner' => 'native']);
        self::assertNotNull($config);
        putenv('PHPOLYGON_SPEECH_GPU=1');

        self::assertStringEndsWith('-cuda', ChatterboxSynthesizer::fromConfig($config, '/game')->image());
    }

    public function testIntWorkersRunOnTheDefaultDevice(): void
    {
        $plan = (new ChatterboxSynthesizer('/game', gpu: true, workers: 2, freeMemory: static fn (): int => PHP_INT_MAX))->workerPlan();

        self::assertSame(['cuda', 'cuda'], array_column($plan, 'device'));
        self::assertSame([null, null], array_column($plan, 'threads'));
    }

    public function testCpuWorkersShareHalfTheCoresNextToGpuWorkers(): void
    {
        $mixed = new ChatterboxSynthesizer('/game', workers: ['cuda', 'cuda', 'cpu'], freeMemory: static fn (): int => PHP_INT_MAX, logicalCores: 16);
        self::assertSame([null, null, 8], array_column($mixed->workerPlan(), 'threads'));

        $cpuOnly = new ChatterboxSynthesizer('/game', workers: ['cpu', 'cpu'], freeMemory: static fn (): int => PHP_INT_MAX, logicalCores: 16);
        self::assertSame([8, 8], array_column($cpuOnly->workerPlan(), 'threads'));

        $fixed = new ChatterboxSynthesizer('/game', workers: ['cuda', 'cpu'], cpuThreads: 3, freeMemory: static fn (): int => PHP_INT_MAX, logicalCores: 16);
        self::assertSame([null, 3], array_column($fixed->workerPlan(), 'threads'));
    }

    public function testShortRamDropsCpuWorkersFirst(): void
    {
        $synthesizer = new ChatterboxSynthesizer(
            '/game',
            workers: ['cpu', 'cuda', 'cuda', 'cpu'],
            freeMemory: static fn (): int => 6_000_000_000,
            logicalCores: 16,
            log: $this->collect(...),
        );

        self::assertSame(['cuda', 'cuda'], array_column($synthesizer->workerPlan(), 'device'));
        self::assertStringContainsString('6.0 GB free RAM is not enough for the workers [cpu, cuda, cuda, cpu]', $this->log[0] ?? '');
        self::assertStringContainsString('running [cuda, cuda]', $this->log[0]);
    }

    public function testShortRamKeepsOneWorker(): void
    {
        $synthesizer = new ChatterboxSynthesizer('/game', workers: ['cpu', 'cpu'], freeMemory: static fn (): int => 1_000_000_000, log: $this->collect(...));

        self::assertSame(['cpu'], array_column($synthesizer->workerPlan(), 'device'));
    }

    public function testUnknownFreeRamKeepsEveryWorker(): void
    {
        $synthesizer = new ChatterboxSynthesizer('/game', workers: 3, device: 'cpu', freeMemory: static fn (): ?int => null, logicalCores: 12);

        self::assertSame(['cpu', 'cpu', 'cpu'], array_column($synthesizer->workerPlan(), 'device'));
        self::assertSame([4, 4, 4], array_column($synthesizer->workerPlan(), 'threads'));
    }

    public function testWorkerEnvironmentOverridesTheOptions(): void
    {
        putenv('PHPOLYGON_SPEECH_WORKERS=cuda,cpu');
        putenv('PHPOLYGON_SPEECH_CPU_THREADS=2');
        $plan = ChatterboxSynthesizer::fromOptions('/game', workers: 4, freeMemory: static fn (): ?int => null)->workerPlan();
        self::assertSame([['device' => 'cuda', 'threads' => null, 'remote' => null], ['device' => 'cpu', 'threads' => 2, 'remote' => null]], $plan);

        putenv('PHPOLYGON_SPEECH_WORKERS=2');
        putenv('PHPOLYGON_SPEECH_DEVICE=mps');
        self::assertSame(['mps', 'mps'], array_column(ChatterboxSynthesizer::fromOptions('/game', gpu: true, freeMemory: static fn (): ?int => null)->workerPlan(), 'device'));

        putenv('PHPOLYGON_SPEECH_WORKERS=cuda,tpu');
        $this->expectExceptionMessage('PHPOLYGON_SPEECH_WORKERS: unknown device "tpu"');
        ChatterboxSynthesizer::fromOptions('/game');
    }

    public function testConfigCarriesWorkersDeviceAndThreads(): void
    {
        $config = SpeechConfig::fromBuildJson([
            'keys' => ['a'], 'voices' => ['n' => ['reference' => null]],
            'workers' => ['cuda', 'cpu'], 'device' => 'cpu', 'cpuThreads' => 5,
        ]);
        self::assertNotNull($config);

        $plan = ChatterboxSynthesizer::fromConfig($config, '/game', static fn (): ?int => null)->workerPlan();
        self::assertSame(['cuda', 'cpu'], array_column($plan, 'device'));
        self::assertSame([null, 5], array_column($plan, 'threads'));
    }

    public function testDockerUsesTheCudaImageWhenAWorkerRunsOnCuda(): void
    {
        self::assertStringEndsWith('-cuda', (new ChatterboxSynthesizer('/game', workers: ['cpu', 'cuda']))->image());
        self::assertStringEndsNotWith('-cuda', (new ChatterboxSynthesizer('/game', workers: ['cpu', 'cpu']))->image());
    }

    public function testRemoteWorkersRunTheEngineScriptOverSsh(): void
    {
        $synthesizer = new ChatterboxSynthesizer('/game', workers: ['cuda', 'ssh://me@mac.local:2222?device=mps&workers=2']);
        $plan = $synthesizer->workerPlan();
        self::assertSame(['cuda', 'mps', 'mps'], array_column($plan, 'device'));
        $remote = $plan[1]['remote'];
        self::assertNotNull($remote);
        self::assertSame('me@mac.local', $remote->target());

        $command = $synthesizer->remoteCommand($remote);
        self::assertSame(['ssh', '-o', 'BatchMode=yes'], array_slice($command, 0, 3));
        self::assertSame(['-p', '2222', 'me@mac.local', '--'], array_slice($command, -5, 4));
        $shell = $command[count($command) - 1];
        self::assertStringStartsWith('PY="$HOME"/\'.phpolygon/speech/chatterbox-' . ChatterboxSynthesizer::VERSION . '/bin/python\';', $shell);
        self::assertStringContainsString('PYTORCH_ENABLE_MPS_FALLBACK=1 exec "$PY" -c \'import base64,sys;', $shell);
        self::assertStringEndsWith('--jobs - --inline --require ' . ChatterboxSynthesizer::VERSION . ' --device mps', $shell);
        self::assertStringContainsString('speech:setup --remote=ssh://me@mac.local:2222?device=mps', $shell);

        // The worker script travels with the command.
        preg_match('~b64decode\(\W*([A-Za-z0-9+/=]{100,})~', $shell, $m);
        self::assertSame(file_get_contents(dirname(__DIR__, 3) . '/tools/speech/chatterbox_worker.py'), base64_decode($m[1] ?? ''));
    }

    public function testRemoteWorkerOptionsAreValidated(): void
    {
        $this->expectExceptionMessage('unknown option "gpu"');
        new ChatterboxSynthesizer('/game', workers: ['ssh://me@mac?gpu=1']);
    }

    public function testSynthesizeSpreadsJobsOverLocalAndRemoteWorkers(): void
    {
        $this->useFakeWorkers();
        putenv('FAKE_SPEECH_CUDA=gpu.40');
        putenv('FAKE_SPEECH_CPU=cpu.40');
        $refA = $this->file('ref-a.wav', 'VOICE-A');
        $refB = $this->file('ref-b.wav', 'VOICE-B');
        $jobs = [];
        for ($i = 0; $i < 12; $i++) {
            $jobs[] = new SpeechJob("j{$i}", "line {$i}", 'en', [$refA, $refB, null][$i % 3], "{$this->dir}/j{$i}.wav", 0, 0.5, 0.5);
        }

        $results = $this->synthesize(['cuda', 'cpu', 'ssh://remote.40@mac'], $jobs);

        self::assertCount(12, $results);
        self::assertSame([], array_filter($results));
        $byWorker = [];
        foreach ($jobs as $job) {
            $parts = explode('|', (string) file_get_contents($job->wavPath));
            $byWorker[$parts[0]][] = $parts;
        }
        self::assertSame(['cpu', 'gpu', 'remote'], self::sorted(array_keys($byWorker)));
        // Remote lines carry the reference WAV itself, sent once per reference.
        $sent = [];
        foreach ($byWorker['remote'] as $parts) {
            self::assertContains($parts[2], ['VOICE-A', 'VOICE-B', 'builtin']);
            if ($parts[3] === 'sent=1') {
                self::assertNotContains($parts[2], $sent, 'reference sent twice');
                $sent[] = $parts[2];
            }
        }
        // Local lines get the reference path.
        foreach (array_merge($byWorker['gpu'], $byWorker['cpu']) as $parts) {
            self::assertContains($parts[2], [$refA, $refB, 'builtin']);
        }
    }

    public function testJobLinesCarryOnlyTheSetParamsLocallyAndInline(): void
    {
        $this->useFakeWorkers();
        putenv('FAKE_SPEECH_CPU=local.30');
        $params = new SpeechParams(temperature: 0.6, maxNewTokens: 300, cfmSteps: 12, referenceStart: 1.5, watermark: false);
        $ref = $this->file('ref.wav', 'VOICE');
        $jobs = [];
        for ($i = 0; $i < 6; $i++) {
            $jobs[] = new SpeechJob("j{$i}", "line {$i}", 'en', $ref, "{$this->dir}/j{$i}.wav", 0, 0.5, 0.5, $i === 0 ? null : $params);
        }

        $results = $this->synthesize(['cpu', 'ssh://remote.30@mac'], $jobs);

        self::assertSame([], array_filter($results));
        $expected = 'p={"temperature":0.6,"max_new_tokens":300,"cfm_steps":12,"reference_start":1.5,"watermark":false}';
        $seen = [];
        foreach ($jobs as $job) {
            $parts = explode('|', (string) file_get_contents($job->wavPath));
            $seen[$parts[0]] = true;
            self::assertSame($job->params === null ? 'p={}' : $expected, $parts[count($parts) - 1], $job->id);
        }
        self::assertSame(['local', 'remote'], self::sorted(array_keys($seen)));
    }

    public function testADroppedRemoteWorkerHandsItsLineBack(): void
    {
        $this->useFakeWorkers();
        putenv('FAKE_SPEECH_CPU=local.30');
        $jobs = [];
        for ($i = 0; $i < 6; $i++) {
            $jobs[] = new SpeechJob("j{$i}", "line {$i}", 'en', null, "{$this->dir}/j{$i}.wav", 0, 0.5, 0.5);
        }

        $results = $this->synthesize(['ssh://remote.30.2@mac', 'cpu'], $jobs);

        self::assertCount(6, $results);
        self::assertSame([], array_filter($results));
        self::assertNotEmpty(array_filter($this->log, static fn (string $l): bool => str_contains($l, 'remote.30.2@mac ended')));
    }

    /**
     * @param list<string> $workers
     * @param list<SpeechJob> $jobs
     * @return array<string, ?string>
     */
    private function synthesize(array $workers, array $jobs): array
    {
        $fake = __DIR__ . '/fake-worker';
        $synthesizer = new ChatterboxSynthesizer(
            $this->dir,
            SpeechConfig::RUNNER_NATIVE,
            toolsDir: $fake,
            workers: $workers,
            freeMemory: static fn (): int => PHP_INT_MAX,
            logicalCores: 8,
            log: $this->collect(...),
            ssh: [PHP_BINARY, $fake . '/chatterbox_worker.py'],
        );
        $results = [];
        $synthesizer->synthesize($jobs, $this->dir, static function (SpeechJob $job, ?string $error) use (&$results): void {
            self::assertArrayNotHasKey($job->id, $results, 'reported twice');
            $results[$job->id] = $error;
        });
        return $results;
    }

    private function useFakeWorkers(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpolygon-speech-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        putenv('PHPOLYGON_SPEECH_PYTHON=' . PHP_BINARY);
    }

    private function file(string $name, string $content): string
    {
        file_put_contents("{$this->dir}/{$name}", $content);
        return "{$this->dir}/{$name}";
    }

    /**
     * @param list<string> $list
     * @return list<string>
     */
    private static function sorted(array $list): array
    {
        sort($list);
        return $list;
    }

    private function collect(string $line): void
    {
        $this->log[] = $line;
    }
}
