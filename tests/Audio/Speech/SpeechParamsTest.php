<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Speech;

use PHPolygon\Audio\Speech\ChatterboxSynthesizer;
use PHPolygon\Audio\Speech\SpeechConfig;
use PHPolygon\Audio\Speech\SpeechParams;
use PHPolygon\Audio\Speech\SpeechVoice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpeechParamsTest extends TestCase
{
    public function testSchemaListsEveryParameterOnce(): void
    {
        $schema = ChatterboxSynthesizer::parameterSchema();
        $names = array_column($schema, 'name');

        self::assertSame(array_keys(SpeechParams::FIELDS), $names);
        self::assertSame(['seed', 'exaggeration', 'cfgWeight'], array_slice($names, 0, 3));
        self::assertNotContains('speed', $names, 'Chatterbox 0.1.7 has no speaking-rate control');
        // Every constructor field of SpeechParams is in the schema, nothing else besides the voice fields.
        $constructor = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(SpeechParams::class, '__construct'))->getParameters(),
        );
        self::assertSame($constructor, SpeechParams::names());
        self::assertSame(array_merge(SpeechParams::VOICE_FIELDS, $constructor), $names);
    }

    public function testSchemaEntriesAreConsistent(): void
    {
        $groups = [SpeechParams::GROUP_VOICE, SpeechParams::GROUP_SAMPLING, SpeechParams::GROUP_REFERENCE, SpeechParams::GROUP_DECODER, SpeechParams::GROUP_OUTPUT];
        $workers = [];
        foreach (SpeechParams::schema() as $entry) {
            $name = $entry['name'];
            self::assertContains($entry['group'], $groups, $name);
            self::assertNotSame('', $entry['description'], $name);
            $workers[] = SpeechParams::FIELDS[$name]['worker'];
            if ($entry['type'] === SpeechParams::TYPE_BOOL) {
                self::assertIsBool($entry['default'], $name);
                self::assertNull($entry['min'], $name);
                continue;
            }
            self::assertNotNull($entry['min'], $name);
            self::assertNotNull($entry['max'], $name);
            self::assertNotNull($entry['step'], $name);
            self::assertLessThan($entry['max'], $entry['min'], $name);
            if ($entry['type'] === SpeechParams::TYPE_INT) {
                self::assertIsInt($entry['min'], $name);
                self::assertIsInt($entry['max'], $name);
            }
            if ($entry['default'] !== null) {
                self::assertGreaterThanOrEqual($entry['min'], $entry['default'], $name);
                self::assertLessThanOrEqual($entry['max'], $entry['default'], $name);
                // The default passes the validation the build.json values go through.
                SpeechParams::check($name, $entry['default'], $name);
            }
        }
        self::assertSame(count($workers), count(array_unique($workers)));
        self::assertSame(['temperature' => 0.8, 'topP' => 1.0, 'minP' => 0.05, 'repetitionPenalty' => 2.0, 'maxNewTokens' => 1000, 'cfmSteps' => 10], array_intersect_key(
            array_column(SpeechParams::schema(), 'default', 'name'),
            array_flip(['temperature', 'topP', 'minP', 'repetitionPenalty', 'maxNewTokens', 'cfmSteps']),
        ));
    }

    public function testBoundsComeFromTheSchema(): void
    {
        foreach (SpeechParams::FIELDS as $name => $spec) {
            if ($spec['type'] === SpeechParams::TYPE_BOOL) {
                continue;
            }
            /** @var int|float $min */
            $min = $spec['min'];
            /** @var int|float $max */
            $max = $spec['max'];
            self::assertSame($min, SpeechParams::check($name, $min, $name));
            self::assertSame($max, SpeechParams::check($name, $max, $name));
            foreach ([$min - ($spec['type'] === SpeechParams::TYPE_INT ? 1 : 0.001), $max + ($spec['type'] === SpeechParams::TYPE_INT ? 1 : 0.001)] as $outside) {
                try {
                    SpeechParams::check($name, $outside, $name);
                    self::fail("{$name} = {$outside} passed");
                } catch (\InvalidArgumentException $e) {
                    self::assertStringContainsString("{$name} must be a number between", $e->getMessage());
                }
            }
        }
    }

    public function testFromArrayAndWorkerFields(): void
    {
        $params = SpeechParams::fromArray([
            'temperature' => 0.6, 'topP' => 1, 'maxNewTokens' => 400, 'cfmSteps' => 16, 'watermark' => false,
            'referenceStart' => 2, 'promptSeconds' => null,
        ]);

        self::assertSame(0.6, $params->temperature);
        self::assertSame(1.0, $params->topP);
        self::assertSame(400, $params->maxNewTokens);
        self::assertSame(2.0, $params->referenceStart);
        self::assertNull($params->promptSeconds);
        self::assertFalse($params->isDefault());
        self::assertTrue($params->usesReference());
        self::assertSame(
            ['temperature' => 0.6, 'top_p' => 1.0, 'max_new_tokens' => 400, 'cfm_steps' => 16, 'reference_start' => 2.0, 'watermark' => false],
            $params->toWorker(),
        );
        self::assertSame([], (new SpeechParams())->toWorker());
        self::assertTrue((new SpeechParams())->isDefault());
        self::assertTrue(SpeechParams::fromArray([])->isDefault());
        self::assertFalse((new SpeechParams(temperature: 0.5))->usesReference());
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'unknown' => [['speed' => 1.2], 'params.speed is unknown (supported: temperature, topP'];
        yield 'voice field' => [['seed' => 3], 'params.seed belongs to the voice itself'];
        yield 'int as float' => [['maxNewTokens' => 300.5], 'params.maxNewTokens must be an integer'];
        yield 'string number' => [['temperature' => '0.7'], 'params.temperature must be a number'];
        yield 'bool' => [['watermark' => 0], 'params.watermark must be true or false'];
        yield 'temperature range' => [['temperature' => 0.0], 'params.temperature must be a number between 0.05 and 2, got 0'];
        yield 'tokens range' => [['maxNewTokens' => 5000], 'params.maxNewTokens must be a number between 1 and 4096'];
        yield 'window range' => [['promptSeconds' => 8], 'params.promptSeconds must be a number between 0.5 and 6'];
    }

    /** @param array<mixed> $data */
    #[DataProvider('invalid')]
    public function testInvalidParamsAreRejected(array $data, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        SpeechParams::fromArray($data);
    }

    public function testConstructorValidatesToo(): void
    {
        $this->expectExceptionMessage('params.cfmSteps must be a number between 1 and 50, got 0');
        new SpeechParams(cfmSteps: 0);
    }

    public function testFingerprintIsUnchangedWithoutParams(): void
    {
        // The key every voice had before params existed: cached lines stay current.
        $before = hash('sha256', (string) json_encode(['abc', 7, 0.6, 0.5, null], JSON_PRESERVE_ZERO_FRACTION));

        self::assertSame($before, (new SpeechVoice('v', 'r.wav', [], 7, 0.6, 0.5))->fingerprint('abc'));
        self::assertSame($before, (new SpeechVoice('v', 'r.wav', [], 7, 0.6, 0.5, params: new SpeechParams()))->fingerprint('abc'));

        $a = (new SpeechVoice('v', 'r.wav', [], 7, 0.6, 0.5, params: new SpeechParams(temperature: 0.6)))->fingerprint('abc');
        $b = (new SpeechVoice('v', 'r.wav', [], 7, 0.6, 0.5, params: new SpeechParams(temperature: 0.7)))->fingerprint('abc');
        $c = (new SpeechVoice('v', 'r.wav', [], 7, 0.6, 0.5, params: new SpeechParams(referenceStart: 1.0)))->fingerprint('abc');
        $d = (new SpeechVoice('v', 'r.wav', [], 7, 0.6, 0.5, params: new SpeechParams(voiceSeconds: 8.0)))->fingerprint('abc');
        self::assertCount(5, array_unique([$before, $a, $b, $c, $d]));
    }

    public function testBuildJsonVoiceParams(): void
    {
        $config = SpeechConfig::fromBuildJson([
            'keys' => ['a'],
            'voices' => [
                'clone' => ['reference' => 'r.wav', 'params' => ['temperature' => 0.5, 'referenceStart' => 1.5, 'referenceLength' => 8, 'watermark' => false]],
                'plain' => ['reference' => 'r.wav'],
                'empty' => ['reference' => null, 'params' => []],
            ],
        ]);

        self::assertNotNull($config);
        $params = $config->voices[0]->params;
        self::assertNotNull($params);
        self::assertSame(['temperature' => 0.5, 'referenceStart' => 1.5, 'referenceLength' => 8.0, 'watermark' => false], $params->toArray());
        self::assertNull($config->voices[1]->params);
        self::assertTrue($config->voices[2]->params?->isDefault());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidVoices(): iterable
    {
        yield 'unknown param' => [['reference' => 'r.wav', 'params' => ['tempo' => 1.1]], 'build.json speech.voices.n.params.tempo is unknown'];
        yield 'params list' => [['reference' => 'r.wav', 'params' => [0.5]], 'build.json speech.voices.n.params must be an object'];
        yield 'range' => [['reference' => 'r.wav', 'params' => ['minP' => 2]], 'build.json speech.voices.n.params.minP must be a number between 0 and 1'];
        yield 'excerpt of the built-in voice' => [['reference' => null, 'params' => ['referenceStart' => 1]], 'need a reference WAV'];
        yield 'negative seed' => [['reference' => 'r.wav', 'seed' => -1], 'voices.n.seed must be a number between 0 and 4294967295'];
        yield 'cfg weight range' => [['reference' => 'r.wav', 'cfgWeight' => 1.5], 'voices.n.cfgWeight must be a number between 0 and 1'];
    }

    /** @param array<string, mixed> $voice */
    #[DataProvider('invalidVoices')]
    public function testInvalidVoiceParamsAreRejected(array $voice, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        SpeechConfig::fromBuildJson(['keys' => ['a'], 'voices' => ['n' => $voice]]);
    }
}
