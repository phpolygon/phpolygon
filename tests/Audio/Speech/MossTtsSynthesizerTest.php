<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Speech;

use PHPolygon\Audio\Speech\ChatterboxSynthesizer;
use PHPolygon\Audio\Speech\MossParams;
use PHPolygon\Audio\Speech\MossTtsSynthesizer;
use PHPolygon\Audio\Speech\SpeechConfig;
use PHPolygon\Audio\Speech\SpeechJob;
use PHPolygon\Audio\Speech\SpeechSynthesizers;
use PHPolygon\Audio\Speech\VoxCpmParams;
use PHPolygon\Audio\Speech\VoxCpmSynthesizer;
use PHPolygon\Platform\SteamLanguage;
use PHPUnit\Framework\TestCase;

final class MossTtsSynthesizerTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        foreach (['PHPOLYGON_SPEECH_PYTHON', 'PHPOLYGON_SPEECH_DTYPE', 'PHPOLYGON_SPEECH_QUANT', 'PHPOLYGON_SPEECH_CODEC_DEVICE'] as $name) {
            putenv($name);
        }
        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->dir);
        }
    }

    public function testSpeaksTheLanguagesVoxCpmLacks(): void
    {
        $synthesizer = new MossTtsSynthesizer('/game');
        foreach (['cs', 'hu', 'ro', 'cs-CZ', 'hu_HU', 'mk'] as $tag) {
            self::assertNotNull($synthesizer->language($tag), $tag);
        }
        self::assertSame('ro', $synthesizer->language('ro-RO'));
        self::assertSame('zh', $synthesizer->language('zh-CN'));
        self::assertSame('yue', $synthesizer->language('yue'));
        self::assertSame('tl', $synthesizer->language('fil'));
        self::assertSame('he', $synthesizer->language('iw'));
        self::assertNull($synthesizer->language('zh-TW'));
        self::assertNull($synthesizer->language('bg'));
        self::assertCount(31, MossTtsSynthesizer::LANGUAGES);

        $silent = [];
        foreach (SteamLanguage::API_CODES as $tag) {
            if ($synthesizer->language($tag) === null) {
                $silent[] = $tag;
            }
        }
        self::assertSame(['bg', 'zh-TW', 'id', 'no', 'uk'], $silent);

        // Together with VoxCPM2 every Steam language but bg and zh-TW has a model.
        $voxcpm = new VoxCpmSynthesizer('/game');
        $neither = array_values(array_filter(SteamLanguage::API_CODES, static fn (string $t): bool => $voxcpm->language($t) === null && $synthesizer->language($t) === null));
        self::assertSame(['bg', 'zh-TW'], $neither);
    }

    public function testIdCarriesVersionDtypeAndQuantisation(): void
    {
        self::assertSame('moss-tts/' . MossTtsSynthesizer::VERSION . '/auto/auto', (new MossTtsSynthesizer('/game'))->id());
        putenv('PHPOLYGON_SPEECH_DTYPE=float16');
        putenv('PHPOLYGON_SPEECH_QUANT=nf4');
        self::assertSame('moss-tts/' . MossTtsSynthesizer::VERSION . '/float16/nf4', (new MossTtsSynthesizer('/game'))->id());
        // Where the audio tokenizer runs does not change the sound.
        putenv('PHPOLYGON_SPEECH_CODEC_DEVICE=cpu');
        self::assertSame('moss-tts/' . MossTtsSynthesizer::VERSION . '/float16/nf4', (new MossTtsSynthesizer('/game'))->id());
        self::assertNotSame((new VoxCpmSynthesizer('/game'))->id(), (new MossTtsSynthesizer('/game'))->id());

        putenv('PHPOLYGON_SPEECH_QUANT=q4');
        $this->expectExceptionMessage('PHPOLYGON_SPEECH_QUANT must be one of auto, none, nf4, int8');
        (new MossTtsSynthesizer('/game'))->id();
    }

    public function testCommandsRunTheMossWorkerInItsOwnEnvironmentAndImage(): void
    {
        putenv('PHPOLYGON_SPEECH_PYTHON');
        $synthesizer = new MossTtsSynthesizer('/game', gpu: true, toolsDir: '/engine/tools/speech');

        self::assertStringEndsWith('.phpolygon/speech/moss-' . MossTtsSynthesizer::VERSION, str_replace('\\', '/', $synthesizer->environmentDir()));
        self::assertSame('phpolygon-speech:moss-' . MossTtsSynthesizer::VERSION . '-cuda', $synthesizer->image());
        $native = $synthesizer->command(SpeechConfig::RUNNER_NATIVE, 'cuda');
        self::assertSame(['/engine/tools/speech/moss_worker.py', '--jobs', '-', '--device', 'cuda'], array_slice($native, 1));

        putenv('PHPOLYGON_SPEECH_DTYPE=float16');
        putenv('PHPOLYGON_SPEECH_QUANT=nf4');
        putenv('PHPOLYGON_SPEECH_CODEC_DEVICE=cpu');
        $docker = $synthesizer->command(SpeechConfig::RUNNER_DOCKER, 'cuda');
        self::assertSame(['phpolygon-speech:moss-' . MossTtsSynthesizer::VERSION . '-cuda', '--jobs', '-', '--device', 'cuda', '--dtype', 'float16', '--quant', 'nf4', '--codec-device', 'cpu'], array_slice($docker, -11));

        $build = $synthesizer->buildCommand();
        self::assertContains('WORKER=moss_worker.py', $build);
        self::assertContains('REQUIREMENTS=requirements-moss.txt', $build);
    }

    public function testRemoteWorkersRequireTheWorkerVersion(): void
    {
        $synthesizer = new MossTtsSynthesizer('/game', workers: ['ssh://me@mac.local?device=mps']);
        $remote = $synthesizer->workerPlan()[0]['remote'];
        self::assertNotNull($remote);

        $command = $synthesizer->remoteCommand($remote);
        $shell = $command[count($command) - 1];
        self::assertStringStartsWith('PY="$HOME"/\'.phpolygon/speech/moss-' . MossTtsSynthesizer::VERSION . '/bin/python\';', $shell);
        self::assertStringContainsString('speech:setup --synth=moss --remote=ssh://me@mac.local?device=mps', $shell);
        self::assertStringEndsWith('--jobs - --inline --require ' . MossTtsSynthesizer::VERSION . ' --device mps', $shell);
        preg_match_all('~d\(\W*([A-Za-z0-9+/=]{100,})~', $shell, $m);
        self::assertSame(file_get_contents(dirname(__DIR__, 3) . '/tools/speech/moss_worker.py'), gzuncompress(base64_decode($m[1][1] ?? '')));
    }

    public function testTheWorkerPinsWhatThePhpSideNames(): void
    {
        $worker = (string) file_get_contents(dirname(__DIR__, 3) . '/tools/speech/moss_worker.py');
        self::assertStringContainsString('EXACT_VERSION = "' . MossTtsSynthesizer::VERSION . '"', $worker);
        self::assertStringContainsString('TRANSFORMERS_VERSION = "' . MossTtsSynthesizer::TRANSFORMERS_VERSION . '"', $worker);
        self::assertStringContainsString('MODEL_ID = "' . MossTtsSynthesizer::MODEL . '"', $worker);
        self::assertStringContainsString('CODEC_ID = "' . MossTtsSynthesizer::CODEC . '"', $worker);
        self::assertMatchesRegularExpression('/MODEL_REVISION = "[0-9a-f]{40}"/', $worker);
        self::assertMatchesRegularExpression('/CODEC_REVISION = "[0-9a-f]{40}"/', $worker);
        $requirements = (string) file_get_contents(dirname(__DIR__, 3) . '/tools/speech/requirements-moss.txt');
        self::assertStringContainsString('transformers==' . MossTtsSynthesizer::TRANSFORMERS_VERSION, $requirements);
        // Every language the PHP side accepts has a tag in the worker.
        foreach (MossTtsSynthesizer::LANGUAGES as $language) {
            self::assertMatchesRegularExpression('/"' . $language . '": "[A-Z][^"]+"/', $worker, $language);
        }
    }

    public function testJobLinesCarryTheMossFields(): void
    {
        $this->useFakeWorker();
        $ref = "{$this->dir}/ref.wav";
        file_put_contents($ref, 'VOICE');
        $params = new MossParams(audioTemperature: 1.2, audioTopK: 30, referenceText: 'Ahoj, jak se máš?', trimSilence: true);
        $jobs = [
            new SpeechJob('a', 'Dobrý den.', 'cs', $ref, "{$this->dir}/a.wav", 3, 0.5, 0.5, $params),
            new SpeechJob('b', 'Jó napot.', 'hu', $ref, "{$this->dir}/b.wav", 4, 0.5, 0.5),
        ];

        $results = [];
        $this->synthesizer()->synthesize($jobs, $this->dir, static function (SpeechJob $job, ?string $error) use (&$results): void {
            $results[$job->id] = $error;
        });
        ksort($results);

        self::assertSame(['a' => null, 'b' => null], $results);
        self::assertSame(['id' => 'a', 'text' => 'Dobrý den.', 'language' => 'cs', 'reference' => $ref, 'seed' => 3,
            'audio_temperature' => 1.2, 'audio_top_k' => 30, 'reference_text' => 'Ahoj, jak se máš?', 'trim_silence' => true], array_diff_key(self::note($jobs[0]->wavPath), ['args' => 0]));
        $b = self::note($jobs[1]->wavPath);
        self::assertSame(['id' => 'b', 'text' => 'Jó napot.', 'language' => 'hu', 'reference' => $ref, 'seed' => 4], array_diff_key($b, ['args' => 0]));
        self::assertSame(1.0, VoxCpmSynthesizer::wavSeconds($jobs[1]->wavPath));
    }

    public function testAJobNeedsAReferenceAndMossParams(): void
    {
        $this->useFakeWorker();
        try {
            $this->synthesizer()->synthesize([new SpeechJob('a', 'line', 'cs', null, "{$this->dir}/a.wav", 0, 0.5, 0.5)], $this->dir, static function (): void {});
            self::fail('accepted a job without reference');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('MOSS-TTS has no built-in voice', $e->getMessage());
        }
        $this->expectExceptionMessage('MOSS-TTS takes MossParams');
        $this->synthesizer()->synthesize([new SpeechJob('a', 'line', 'cs', "{$this->dir}/ref.wav", "{$this->dir}/a.wav", 0, 0.5, 0.5, new VoxCpmParams(cfgValue: 2.0))], $this->dir, static function (): void {});
    }

    public function testParamsValidateTheirOwnTable(): void
    {
        $params = MossParams::fromArray(['audioTemperature' => 1, 'maxNewTokens' => 1024, 'durationTokens' => 60]);
        self::assertSame(['audioTemperature' => 1.0, 'maxNewTokens' => 1024, 'durationTokens' => 60], $params->toArray());
        self::assertSame(['audio_temperature' => 1.0, 'max_new_tokens' => 1024, 'duration_tokens' => 60], $params->toWorker());
        self::assertFalse($params->usesReference());
        self::assertTrue((new MossParams(referenceLength: 8.0))->usesReference());
        self::assertTrue((new MossParams())->isDefault());

        $names = array_column(MossTtsSynthesizer::parameterSchema(), 'name');
        self::assertSame('seed', $names[0]);
        self::assertContains('referenceText', $names);
        self::assertNotContains('description', $names);

        foreach ([
            [['cfgValue' => 2.0], 'params.cfgValue is unknown'],
            [['audioTopP' => 1.5], 'params.audioTopP must be a number between 0.1 and 1'],
            [['referenceText' => ' '], 'params.referenceText must be a non-empty string'],
            [['audioTopK' => 2.5], 'params.audioTopK must be an integer'],
            [['referenceText' => 'Ahoj', 'durationTokens' => 50], 'durationTokens cannot be combined with params.referenceText'],
        ] as [$data, $message]) {
            try {
                MossParams::fromArray($data);
                self::fail('accepted ' . json_encode($data));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testBuildJsonPicksMossAndNeedsAReference(): void
    {
        $config = SpeechConfig::fromBuildJson([
            'keys' => ['dialog.*'],
            'synthesizer' => 'moss',
            'voices' => ['guide' => ['reference' => 'refs/guide.wav', 'seed' => 5, 'params' => ['audioTemperature' => 1.5, 'referenceText' => 'Dobrý den']]],
        ]);
        self::assertNotNull($config);
        self::assertSame(SpeechSynthesizers::MOSS, $config->synthesizer);
        self::assertInstanceOf(MossParams::class, $config->voices[0]->params);
        self::assertInstanceOf(MossTtsSynthesizer::class, SpeechSynthesizers::fromConfig($config, '/game'));
        self::assertNotInstanceOf(ChatterboxSynthesizer::class, SpeechSynthesizers::fromConfig($config, '/game'));

        foreach ([
            [['n' => ['reference' => null]], 'MOSS-TTS has no built-in voice'],
            [['n' => ['reference' => 'n.wav', 'exaggeration' => 0.7]], 'exaggeration is a Chatterbox setting'],
            [['n' => ['reference' => 'n.wav', 'params' => ['description' => 'calm']]], 'params.description is unknown'],
        ] as [$voices, $message]) {
            try {
                SpeechConfig::fromBuildJson(['keys' => ['a'], 'synthesizer' => 'moss', 'voices' => $voices]);
                self::fail('accepted ' . json_encode($voices));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    private function synthesizer(): MossTtsSynthesizer
    {
        return new MossTtsSynthesizer(
            $this->dir,
            SpeechConfig::RUNNER_NATIVE,
            toolsDir: __DIR__ . '/fake-worker',
            freeMemory: static fn (): int => PHP_INT_MAX,
            log: static function (string $line): void {},
        );
    }

    private function useFakeWorker(): void
    {
        $this->dir = str_replace('\\', '/', sys_get_temp_dir()) . '/phpolygon-moss-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        putenv('PHPOLYGON_SPEECH_PYTHON=' . PHP_BINARY);
    }

    /** @return array<string, mixed> the job line the fake worker received (its "phpo" chunk) */
    private static function note(string $wav): array
    {
        $bytes = (string) file_get_contents($wav);
        $at = strpos($bytes, 'phpo');
        self::assertNotFalse($at, "{$wav} has no phpo chunk");
        /** @var array{1: int} $size */
        $size = unpack('V', substr($bytes, $at + 4, 4));
        /** @var array<string, mixed> */
        return json_decode(trim(substr($bytes, $at + 8, $size[1])), true, flags: JSON_THROW_ON_ERROR);
    }
}
