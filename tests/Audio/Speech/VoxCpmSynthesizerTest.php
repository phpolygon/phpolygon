<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Speech;

use PHPolygon\Audio\Speech\ChatterboxSynthesizer;
use PHPolygon\Audio\Speech\SpeechConfig;
use PHPolygon\Audio\Speech\SpeechJob;
use PHPolygon\Audio\Speech\SpeechParams;
use PHPolygon\Audio\Speech\SpeechSynthesizers;
use PHPolygon\Audio\Speech\VoiceDesign;
use PHPolygon\Audio\Speech\VoxCpmParams;
use PHPolygon\Audio\Speech\VoxCpmSynthesizer;
use PHPolygon\Platform\SteamLanguage;
use PHPUnit\Framework\TestCase;

final class VoxCpmSynthesizerTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        foreach (['PHPOLYGON_SPEECH_PYTHON', 'PHPOLYGON_SPEECH_DTYPE', 'PHPOLYGON_SPEECH_COMPILE', 'FAKE_VOXCPM_SECONDS'] as $name) {
            putenv($name);
        }
        if ($this->dir !== '' && is_dir($this->dir)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                /** @var \SplFileInfo $file */
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->dir);
        }
    }

    public function testSpeaksTheSteamLanguagesChatterboxLacksExceptFour(): void
    {
        $synthesizer = new VoxCpmSynthesizer('/game');
        $silent = [];
        foreach (SteamLanguage::API_CODES as $tag) {
            if ($synthesizer->language($tag) === null) {
                $silent[] = $tag;
            }
        }

        // The model card's 30 languages plus Ukrainian; cs, hu, ro, bg measured too poor.
        self::assertSame(['bg', 'zh-TW', 'cs', 'hu', 'ro'], $silent);
        foreach (['id', 'th', 'vi', 'uk', 'zh-CN'] as $tag) {
            self::assertNotNull($synthesizer->language($tag), $tag);
        }
        self::assertSame('zh', $synthesizer->language('zh-CN'));
        self::assertSame('uk', $synthesizer->language('uk_UA'));
        self::assertSame('tl', $synthesizer->language('fil'));
        self::assertSame('no', $synthesizer->language('nb'));
        self::assertNull($synthesizer->language('zh-Hant'));
        self::assertCount(30, VoxCpmSynthesizer::LANGUAGES);
    }

    public function testEveryChatterboxLanguageIsAlsoSpokenForVoiceDesign(): void
    {
        foreach (ChatterboxSynthesizer::LANGUAGES as $language) {
            self::assertSame($language, (new VoxCpmSynthesizer('/game'))->language($language), $language);
        }
    }

    public function testIdCarriesModelVersionAndDtype(): void
    {
        self::assertSame('voxcpm2/' . VoxCpmSynthesizer::VERSION . '/auto', (new VoxCpmSynthesizer('/game'))->id());
        putenv('PHPOLYGON_SPEECH_DTYPE=float32');
        self::assertSame('voxcpm2/' . VoxCpmSynthesizer::VERSION . '/float32', (new VoxCpmSynthesizer('/game'))->id());
        self::assertNotSame((new ChatterboxSynthesizer('/game'))->id(), (new VoxCpmSynthesizer('/game'))->id());

        putenv('PHPOLYGON_SPEECH_DTYPE=bf16');
        $this->expectExceptionMessage('PHPOLYGON_SPEECH_DTYPE must be one of');
        (new VoxCpmSynthesizer('/game'))->id();
    }

    public function testCommandsRunTheVoxCpmWorkerInItsOwnEnvironmentAndImage(): void
    {
        putenv('PHPOLYGON_SPEECH_PYTHON');
        $synthesizer = new VoxCpmSynthesizer('/game', gpu: true, toolsDir: '/engine/tools/speech');

        self::assertStringEndsWith('.phpolygon/speech/voxcpm-' . VoxCpmSynthesizer::VERSION, str_replace('\\', '/', $synthesizer->environmentDir()));
        self::assertSame('phpolygon-speech:voxcpm-' . VoxCpmSynthesizer::VERSION . '-cuda', $synthesizer->image());
        $native = $synthesizer->command(SpeechConfig::RUNNER_NATIVE, 'cuda');
        self::assertSame(['/engine/tools/speech/voxcpm_worker.py', '--jobs', '-', '--device', 'cuda'], array_slice($native, 1));

        putenv('PHPOLYGON_SPEECH_DTYPE=float16');
        putenv('PHPOLYGON_SPEECH_COMPILE=0');
        $docker = $synthesizer->command(SpeechConfig::RUNNER_DOCKER, 'cuda');
        self::assertSame(['phpolygon-speech:voxcpm-' . VoxCpmSynthesizer::VERSION . '-cuda', '--jobs', '-', '--device', 'cuda', '--dtype', 'float16', '--no-compile'], array_slice($docker, -8));
        self::assertContains(VoxCpmSynthesizer::MODEL_VOLUME . ':/models', $docker);

        $build = $synthesizer->buildCommand();
        self::assertContains('WORKER=voxcpm_worker.py', $build);
        self::assertContains('REQUIREMENTS=requirements-voxcpm.txt', $build);
        self::assertContains('SYSTEM_PACKAGES=gcc libc6-dev', $build);
    }

    public function testRemoteWorkersGetTheirOwnEnvironmentAndSetupHint(): void
    {
        $synthesizer = new VoxCpmSynthesizer('/game', workers: ['ssh://me@mac.local?device=mps']);
        $remote = $synthesizer->workerPlan()[0]['remote'];
        self::assertNotNull($remote);

        $shell = $synthesizer->remoteCommand($remote)[count($synthesizer->remoteCommand($remote)) - 1];
        self::assertStringStartsWith('PY="$HOME"/\'.phpolygon/speech/voxcpm-' . VoxCpmSynthesizer::VERSION . '/bin/python\';', $shell);
        self::assertStringContainsString('speech:setup --synth=voxcpm --remote=ssh://me@mac.local?device=mps', $shell);
        self::assertStringEndsWith('--jobs - --inline --require ' . VoxCpmSynthesizer::VERSION . ' --device mps', $shell);
        preg_match_all('~d\(\W*([A-Za-z0-9+/=]{100,})~', $shell, $m);
        self::assertSame(file_get_contents(dirname(__DIR__, 3) . '/tools/speech/voxcpm_worker.py'), gzuncompress(base64_decode($m[1][1] ?? '')));
    }

    public function testJobLinesCarryTheVoxCpmFieldsButNoChatterboxOnes(): void
    {
        $this->useFakeWorker();
        $ref = "{$this->dir}/ref.wav";
        file_put_contents($ref, 'VOICE');
        $params = new VoxCpmParams(cfgValue: 1.6, inferenceTimesteps: 16, referenceText: 'transcript', retryBadcase: false);
        $jobs = [
            new SpeechJob('a', 'line a', 'th', $ref, "{$this->dir}/a.wav", 3, 0.7, 0.3, $params),
            new SpeechJob('b', 'line b', 'th', $ref, "{$this->dir}/b.wav", 4, 0.5, 0.5),
        ];

        $results = $this->synthesize($jobs);

        self::assertSame(['a' => null, 'b' => null], $results);
        $a = self::note($jobs[0]->wavPath);
        self::assertSame(['id' => 'a', 'text' => 'line a', 'language' => 'th', 'reference' => $ref, 'seed' => 3,
            'cfg_value' => 1.6, 'inference_timesteps' => 16, 'retry_badcase' => false, 'reference_text' => 'transcript'], array_diff_key($a, ['args' => 0]));
        self::assertArrayNotHasKey('exaggeration', self::note($jobs[1]->wavPath));
        self::assertArrayNotHasKey('cfg_weight', self::note($jobs[1]->wavPath));
    }

    public function testAJobWithoutReferenceNeedsADescription(): void
    {
        $this->useFakeWorker();
        $this->expectExceptionMessage('VoxCPM2 has no built-in voice');
        $this->synthesize([new SpeechJob('a', 'line', 'th', null, "{$this->dir}/a.wav", 0, 0.5, 0.5)]);
    }

    public function testChatterboxParamsAreRefused(): void
    {
        $this->useFakeWorker();
        $this->expectExceptionMessage('VoxCPM2 takes VoxCpmParams');
        $this->synthesize([new SpeechJob('a', 'line', 'th', null, "{$this->dir}/a.wav", 0, 0.5, 0.5, new SpeechParams(temperature: 0.5))]);
    }

    public function testVoiceDesignWritesOneTakePerSeed(): void
    {
        $this->useFakeWorker();
        $design = new VoiceDesign('warm female voice, calm', 'สวัสดีค่ะ', 'th', new VoxCpmParams(cfgValue: 2.5));
        $out = "{$this->dir}/refs/th-female.wav";
        $seen = [];

        $results = $this->synthesizer()->design($design, $out, [7, 8], static function (int $seed, string $path, ?string $error, ?float $seconds) use (&$seen): void {
            $seen[$seed] = [$path, $error, $seconds];
        });

        self::assertSame([7, 8], self::sortedKeys($results));
        foreach ([7, 8] as $seed) {
            $path = "{$this->dir}/refs/th-female-s{$seed}.wav";
            self::assertSame(['path' => $path, 'seconds' => 12.0, 'error' => null], $results[$seed]);
            self::assertSame([$path, null, 12.0], $seen[$seed]);
            $note = self::note($path);
            self::assertSame($seed, $note['seed']);
            self::assertNull($note['reference']);
            self::assertSame('warm female voice, calm', $note['description']);
            self::assertTrue($note['trim_silence']);
            self::assertSame(2.5, $note['cfg_value']);
        }
        // The scratch directory inside the project is gone again.
        self::assertSame([], glob("{$this->dir}/.speech-design-*") ?: []);
    }

    public function testASingleTakeIsWrittenToTheGivenPath(): void
    {
        $this->useFakeWorker();
        putenv('FAKE_VOXCPM_SECONDS=1.5');
        $results = $this->synthesizer()->design(new VoiceDesign('calm male voice', 'Halo', 'id'), "{$this->dir}/id-male.wav", [3]);

        self::assertSame(['path' => "{$this->dir}/id-male.wav", 'seconds' => 1.5, 'error' => null], $results[3]);
        self::assertSame(1.5, VoxCpmSynthesizer::wavSeconds("{$this->dir}/id-male.wav"));
    }

    public function testVoiceDesignReportsAFailedTake(): void
    {
        $this->useFakeWorker();
        $results = $this->synthesizer()->design(new VoiceDesign('calm male voice', 'fail', 'id'), "{$this->dir}/x.wav", [1]);

        self::assertSame('RuntimeError: fake failure', $results[1]['error']);
        self::assertFileDoesNotExist("{$this->dir}/x.wav");
    }

    public function testVoiceDesignRefusesLanguagesTheModelCannotSpeak(): void
    {
        $this->expectExceptionMessage('VoxCPM2 does not speak "hu"');
        (new VoxCpmSynthesizer('/game'))->design(new VoiceDesign('calm male voice', 'Szia', 'hu'), '/game/x.wav');
    }

    public function testVoiceDesignNeedsDescriptionTextAndNoReferenceFields(): void
    {
        self::assertSame('warm voice', (new VoiceDesign('warm voice', 'Hi', 'en'))->jobParams()->description);
        self::assertTrue((new VoiceDesign('warm voice', 'Hi', 'en'))->jobParams()->trimSilence);
        self::assertFalse((new VoiceDesign('warm voice', 'Hi', 'en', new VoxCpmParams(trimSilence: false)))->jobParams()->trimSilence);

        $this->expectExceptionMessage('need a reference');
        new VoiceDesign('warm voice', 'Hi', 'en', new VoxCpmParams(referenceText: 'Hi'));
    }

    public function testTakePaths(): void
    {
        self::assertSame('refs/a.wav', VoiceDesign::takePath('refs/a.wav', 4, [4]));
        self::assertSame('refs/a-s4.wav', VoiceDesign::takePath('refs/a.wav', 4, [4, 5]));
        self::assertSame('refs.d/a-s5', VoiceDesign::takePath('refs.d/a', 5, [4, 5]));
    }

    public function testParamsValidateTheirOwnTable(): void
    {
        $params = VoxCpmParams::fromArray(['cfgValue' => 2, 'inferenceTimesteps' => 20, 'description' => 'deep voice', 'normalize' => true]);
        self::assertSame(['description' => 'deep voice', 'cfgValue' => 2.0, 'inferenceTimesteps' => 20, 'normalize' => true], $params->toArray());
        self::assertSame(['description' => 'deep voice', 'cfg_value' => 2.0, 'inference_timesteps' => 20, 'normalize' => true], $params->toWorker());
        self::assertFalse($params->usesReference());
        self::assertTrue((new VoxCpmParams(referenceStart: 1.0))->usesReference());
        self::assertTrue((new VoxCpmParams())->isDefault());

        $names = array_column(VoxCpmSynthesizer::parameterSchema(), 'name');
        self::assertSame('seed', $names[0]);
        self::assertContains('description', $names);
        self::assertNotContains('exaggeration', $names);
        self::assertSame('string', VoxCpmSynthesizer::parameterSchema()[1]['type']);

        foreach ([
            [['temperature' => 0.5], 'params.temperature is unknown'],
            [['cfgValue' => 0.1], 'params.cfgValue must be a number between 0.5 and 10'],
            [['description' => ''], 'params.description must be a non-empty string'],
            [['inferenceTimesteps' => 2.5], 'params.inferenceTimesteps must be an integer'],
            [['seed' => 1], 'params.seed belongs to the voice itself'],
        ] as [$data, $message]) {
            try {
                VoxCpmParams::fromArray($data);
                self::fail("accepted " . json_encode($data));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testBuildJsonPicksTheSynthesizerAndItsParameterTable(): void
    {
        $config = SpeechConfig::fromBuildJson([
            'keys' => ['dialog.*'],
            'synthesizer' => 'voxcpm',
            'voices' => [
                'guide' => ['reference' => 'refs/guide.wav', 'seed' => 5, 'params' => ['cfgValue' => 1.8, 'referenceText' => 'Hello there']],
                'designed' => ['params' => ['description' => 'young male voice, energetic']],
            ],
        ]);
        self::assertNotNull($config);
        self::assertSame('voxcpm', $config->synthesizer);
        self::assertInstanceOf(VoxCpmParams::class, $config->voices[0]->params);
        self::assertSame('Hello there', $config->voices[0]->params->referenceText);
        self::assertInstanceOf(VoxCpmSynthesizer::class, SpeechSynthesizers::fromConfig($config, '/game'));

        $default = SpeechConfig::fromBuildJson(['keys' => ['a'], 'voices' => ['n' => ['reference' => null, 'params' => ['temperature' => 0.6]]]]);
        self::assertNotNull($default);
        self::assertSame('chatterbox', $default->synthesizer);
        self::assertInstanceOf(SpeechParams::class, $default->voices[0]->params);
        self::assertInstanceOf(ChatterboxSynthesizer::class, SpeechSynthesizers::fromConfig($default, '/game'));
    }

    public function testBuildJsonRejectsWhatVoxCpmCannotDo(): void
    {
        foreach ([
            [['n' => ['reference' => 'n.wav', 'exaggeration' => 0.7]], 'exaggeration is a Chatterbox setting'],
            [['n' => ['reference' => 'n.wav', 'params' => ['temperature' => 0.6]]], 'params.temperature is unknown'],
            [['n' => ['reference' => null]], 'VoxCPM2 has no built-in voice'],
            [['n' => ['params' => ['description' => 'calm', 'referenceText' => 'x']]], 'need a reference WAV'],
        ] as [$voices, $message]) {
            try {
                SpeechConfig::fromBuildJson(['keys' => ['a'], 'synthesizer' => 'voxcpm', 'voices' => $voices]);
                self::fail('accepted ' . json_encode($voices));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->expectExceptionMessage('speech.synthesizer must be one of: chatterbox, voxcpm');
        SpeechConfig::fromBuildJson(['keys' => ['a'], 'synthesizer' => 'piper', 'voices' => ['n' => ['reference' => null]]]);
    }

    /**
     * @param list<SpeechJob> $jobs
     * @return array<string, ?string>
     */
    private function synthesize(array $jobs): array
    {
        $results = [];
        $this->synthesizer()->synthesize($jobs, $this->dir, static function (SpeechJob $job, ?string $error) use (&$results): void {
            $results[$job->id] = $error;
        });
        ksort($results);
        return $results;
    }

    private function synthesizer(): VoxCpmSynthesizer
    {
        return new VoxCpmSynthesizer(
            $this->dir,
            SpeechConfig::RUNNER_NATIVE,
            toolsDir: __DIR__ . '/fake-worker',
            freeMemory: static fn (): int => PHP_INT_MAX,
            log: static function (string $line): void {},
        );
    }

    private function useFakeWorker(): void
    {
        $this->dir = str_replace('\\', '/', sys_get_temp_dir()) . '/phpolygon-voxcpm-' . bin2hex(random_bytes(4));
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

    /**
     * @param array<int, mixed> $results
     * @return list<int>
     */
    private static function sortedKeys(array $results): array
    {
        $keys = array_keys($results);
        sort($keys);
        return $keys;
    }
}
