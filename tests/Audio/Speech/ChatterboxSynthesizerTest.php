<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Speech;

use PHPolygon\Audio\Speech\ChatterboxSynthesizer;
use PHPolygon\Audio\Speech\SpeechConfig;
use PHPolygon\Platform\SteamLanguage;
use PHPUnit\Framework\TestCase;

final class ChatterboxSynthesizerTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHPOLYGON_SPEECH_PYTHON');
        putenv('PHPOLYGON_SPEECH_RUNNER');
        putenv('PHPOLYGON_SPEECH_GPU');
    }

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
                [$python, '/engine/tools/speech/chatterbox_worker.py', '--jobs', '/game/voice/.work/jobs.jsonl'],
                $synthesizer->command(SpeechConfig::RUNNER_NATIVE, '/game/voice/.work/jobs.jsonl'),
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
        $command = $synthesizer->command(SpeechConfig::RUNNER_DOCKER, '/work/resources/voice/.work/jobs.jsonl');

        self::assertSame(['docker', 'run', '--rm', '-v', '/game:/work', '-v', ChatterboxSynthesizer::MODEL_VOLUME . ':/models'], array_slice($command, 0, 7));
        self::assertContains('--gpus', $command);
        self::assertSame(['phpolygon-speech:chatterbox-' . ChatterboxSynthesizer::VERSION . '-cuda', '--jobs', '/work/resources/voice/.work/jobs.jsonl', '--device', 'cuda'], array_slice($command, -5));
    }

    public function testEnvironmentOverridesTheConfig(): void
    {
        $config = SpeechConfig::fromBuildJson(['keys' => ['a'], 'voices' => ['n' => ['reference' => 'n.wav']], 'runner' => 'native']);
        self::assertNotNull($config);
        putenv('PHPOLYGON_SPEECH_GPU=1');

        self::assertStringEndsWith('-cuda', ChatterboxSynthesizer::fromConfig($config, '/game')->image());
    }
}
