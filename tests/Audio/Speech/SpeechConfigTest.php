<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Speech;

use PHPolygon\Audio\Speech\SpeechConfig;
use PHPolygon\Build\BuildConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpeechConfigTest extends TestCase
{
    public function testAbsentSectionIsNull(): void
    {
        self::assertNull(SpeechConfig::fromBuildJson(null));
    }

    public function testDefaultsAndVoiceSelection(): void
    {
        $config = SpeechConfig::fromBuildJson([
            'keys' => ['dialog.*', 'tutorial.*'],
            'voices' => [
                'robot' => ['reference' => 'ref/robot.wav', 'keys' => ['dialog.robot.*'], 'profile' => ['vocoderHz' => 110, 'crush' => 0.3]],
                'narrator' => ['reference' => 'ref/narrator.wav/'],
            ],
        ]);

        self::assertNotNull($config);
        self::assertSame('resources/lang', $config->localesDir);
        self::assertSame('resources/voice', $config->outputDir);
        self::assertSame(SpeechConfig::BUILD_GENERATE, $config->build);
        self::assertSame(SpeechConfig::RUNNER_AUTO, $config->runner);
        self::assertFalse($config->gpu);
        self::assertTrue($config->isSpoken('tutorial.step1'));
        self::assertFalse($config->isSpoken('menu.start'));
        self::assertSame('robot', $config->voiceFor('dialog.robot.beep')?->name);
        $narrator = $config->voiceFor('dialog.intro');
        self::assertNotNull($narrator);
        self::assertSame('narrator', $narrator->name);
        self::assertSame('ref/narrator.wav', $narrator->reference);
        $profile = $config->voices[0]->profile;
        self::assertNotNull($profile);
        self::assertSame(110.0, $profile->vocoderHz);
        self::assertSame(0.3, $profile->crush);
    }

    public function testVoiceWithoutReferenceUsesTheBuiltinVoice(): void
    {
        $config = SpeechConfig::fromBuildJson([
            'keys' => ['dialog.*'],
            'voices' => ['guide' => ['reference' => null], 'host' => ['profile' => ['pitch' => 0.8]], 'clone' => ['reference' => 'ref/clone.wav', 'keys' => ['dialog.clone.*']]],
        ]);

        self::assertNotNull($config);
        self::assertTrue($config->voices[0]->isBuiltin());
        self::assertTrue($config->voices[1]->isBuiltin());
        self::assertFalse($config->voices[2]->isBuiltin());
        self::assertNotSame($config->voices[0]->fingerprint('builtin'), $config->voices[1]->fingerprint('builtin'));
    }

    public function testWithoutFallbackVoiceUnclaimedKeysHaveNone(): void
    {
        $config = SpeechConfig::fromBuildJson([
            'keys' => ['dialog.*'],
            'voices' => ['robot' => ['reference' => 'ref/robot.wav', 'keys' => ['dialog.robot.*']]],
        ]);

        self::assertNull($config?->voiceFor('dialog.intro'));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalid(): iterable
    {
        $voices = ['n' => ['reference' => 'ref/n.wav']];
        yield 'list' => [['dialog.*'], 'must be an object'];
        yield 'unknown key' => [['keys' => ['a'], 'voices' => $voices, 'voice' => 1], 'speech.voice is unknown'];
        yield 'no keys' => [['voices' => $voices], 'speech.keys must list'];
        yield 'no voices' => [['keys' => ['a']], 'speech.voices must be an object'];
        yield 'bad reference' => [['keys' => ['a'], 'voices' => ['n' => ['reference' => '']]], 'voices.n.reference must be'];
        yield 'bad build' => [['keys' => ['a'], 'voices' => $voices, 'build' => 'always'], 'speech.build must be one of'];
        yield 'bad runner' => [['keys' => ['a'], 'voices' => $voices, 'runner' => 'podman'], 'speech.runner must be one of'];
        yield 'bad seed' => [['keys' => ['a'], 'voices' => ['n' => ['reference' => 'r.wav', 'seed' => 1.5]]], 'voices.n.seed must be an integer'];
        yield 'exaggeration range' => [['keys' => ['a'], 'voices' => ['n' => ['reference' => 'r.wav', 'exaggeration' => 3]]], 'voices.n.exaggeration must be a number between'];
        yield 'profile field' => [['keys' => ['a'], 'voices' => ['n' => ['reference' => 'r.wav', 'profile' => ['shout' => 1]]]], "unknown voice profile field 'shout'"];
    }

    #[DataProvider('invalid')]
    public function testInvalidSectionsAreRejected(mixed $data, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        SpeechConfig::fromBuildJson($data);
    }

    public function testBuildConfigReadsTheSection(): void
    {
        $dir = sys_get_temp_dir() . '/phpolygon-speech-config-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($dir);
        file_put_contents($dir . '/build.json', json_encode([
            'speech' => ['keys' => ['dialog.*'], 'voices' => ['n' => ['reference' => 'ref/n.wav']], 'build' => 'check'],
        ]));
        try {
            $config = BuildConfig::load($dir);
        } finally {
            unlink($dir . '/build.json');
            rmdir($dir);
        }

        self::assertNotNull($config->speech);
        self::assertSame(SpeechConfig::BUILD_CHECK, $config->speech->build);
        self::assertSame(['n'], $config->speech->toArray()['voices']);
    }
}
