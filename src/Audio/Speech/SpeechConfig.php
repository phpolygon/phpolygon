<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

use PHPolygon\Audio\Dsp\Voice\VoiceProfile;

/**
 * build.json `speech`: spoken lines generated from the translation files.
 *
 * ```json
 * "speech": {
 *   "locales": "resources/lang",
 *   "output": "resources/voice",
 *   "keys": ["dialog.*"],
 *   "voices": {
 *     "narrator": { "reference": "resources/voice-ref/narrator.wav" },
 *     "guide": { "reference": null, "profile": { "pitch": 0.8 } },
 *     "robot": { "reference": "resources/voice-ref/robot.wav", "keys": ["dialog.robot.*"],
 *                "seed": 7, "exaggeration": 0.6, "profile": { "crush": 0.2 } }
 *   },
 *   "build": "generate",
 *   "runner": "auto",
 *   "gpu": false
 * }
 * ```
 *
 *   locales  directory of `{locale}.json` translation files (as LocaleManager reads them)
 *   output   where `{locale}/{key}.flac` and `speech.lock.json` are written; keep it out
 *            of Git and list it in `resources.external`
 *   keys     fnmatch patterns of the spoken keys; the text of each locale is spoken
 *   only     locales to generate; empty = every translation file
 *   voices   the first voice whose `keys` match a line speaks it, else a voice without `keys`;
 *            `reference` is the WAV the voice is cloned from, null or absent = the
 *            synthesizer's built-in voice
 *   build    what `phpolygon build` does: generate missing lines, only check, or nothing
 *   runner   auto (native Python environment, else Docker), native or docker
 *   gpu      Docker: `--gpus all` with the CUDA image
 */
final readonly class SpeechConfig
{
    public const string BUILD_GENERATE = 'generate';
    public const string BUILD_CHECK = 'check';
    public const string BUILD_OFF = 'off';

    public const string RUNNER_AUTO = 'auto';
    public const string RUNNER_NATIVE = 'native';
    public const string RUNNER_DOCKER = 'docker';

    /**
     * @param list<string> $keys
     * @param list<SpeechVoice> $voices
     * @param list<string> $only
     */
    public function __construct(
        public string $localesDir,
        public string $outputDir,
        public array $keys,
        public array $voices,
        public array $only = [],
        public string $build = self::BUILD_GENERATE,
        public string $runner = self::RUNNER_AUTO,
        public bool $gpu = false,
    ) {}

    /**
     * @throws \InvalidArgumentException on anything malformed
     */
    public static function fromBuildJson(mixed $data): ?self
    {
        if ($data === null) {
            return null;
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException('build.json speech must be an object like {"locales": "resources/lang", "keys": ["dialog.*"], "voices": {...}}');
        }
        $known = ['locales', 'output', 'keys', 'only', 'voices', 'build', 'runner', 'gpu'];
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $known, true)) {
                throw new \InvalidArgumentException("build.json speech.{$key} is unknown (supported: " . implode(', ', $known) . ')');
            }
        }

        $keys = self::stringList($data['keys'] ?? null, 'keys');
        if ($keys === []) {
            throw new \InvalidArgumentException('build.json speech.keys must list the spoken keys, e.g. ["dialog.*"]');
        }

        $voicesData = $data['voices'] ?? null;
        if (!is_array($voicesData) || $voicesData === [] || array_is_list($voicesData)) {
            throw new \InvalidArgumentException('build.json speech.voices must be an object of named voices, e.g. {"narrator": {"reference": "resources/voice-ref/narrator.wav"}}');
        }
        $voices = [];
        foreach ($voicesData as $name => $voice) {
            $voices[] = self::parseVoice((string) $name, $voice);
        }

        $build = self::choice($data['build'] ?? self::BUILD_GENERATE, 'build', [self::BUILD_GENERATE, self::BUILD_CHECK, self::BUILD_OFF]);
        $runner = self::choice($data['runner'] ?? self::RUNNER_AUTO, 'runner', [self::RUNNER_AUTO, self::RUNNER_NATIVE, self::RUNNER_DOCKER]);
        $gpu = $data['gpu'] ?? false;
        if (!is_bool($gpu)) {
            throw new \InvalidArgumentException('build.json speech.gpu must be true or false');
        }

        return new self(
            self::path($data['locales'] ?? 'resources/lang', 'locales'),
            self::path($data['output'] ?? 'resources/voice', 'output'),
            $keys,
            $voices,
            self::stringList($data['only'] ?? null, 'only'),
            $build,
            $runner,
            $gpu,
        );
    }

    /** True when the line key is one the game speaks. */
    public function isSpoken(string $key): bool
    {
        foreach ($this->keys as $pattern) {
            if (fnmatch($pattern, $key)) {
                return true;
            }
        }
        return false;
    }

    public function voiceFor(string $key): ?SpeechVoice
    {
        $fallback = null;
        foreach ($this->voices as $voice) {
            if ($voice->claims($key)) {
                return $voice;
            }
            if ($voice->keys === [] && $fallback === null) {
                $fallback = $voice;
            }
        }
        return $fallback;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'locales' => $this->localesDir,
            'output' => $this->outputDir,
            'keys' => $this->keys,
            'only' => $this->only,
            'voices' => array_map(static fn (SpeechVoice $v): string => $v->name, $this->voices),
            'build' => $this->build,
            'runner' => $this->runner,
            'gpu' => $this->gpu,
        ];
    }

    private static function parseVoice(string $name, mixed $voice): SpeechVoice
    {
        if (!is_array($voice) || array_is_list($voice)) {
            throw new \InvalidArgumentException("build.json speech.voices.{$name} must be an object (\"reference\": a WAV to clone, or null for the built-in voice)");
        }
        $known = ['reference', 'keys', 'seed', 'exaggeration', 'cfgWeight', 'profile'];
        foreach (array_keys($voice) as $key) {
            if (!in_array($key, $known, true)) {
                throw new \InvalidArgumentException("build.json speech.voices.{$name}.{$key} is unknown (supported: " . implode(', ', $known) . ')');
            }
        }
        $seed = $voice['seed'] ?? 0;
        if (!is_int($seed)) {
            throw new \InvalidArgumentException("build.json speech.voices.{$name}.seed must be an integer");
        }
        $profile = null;
        if (isset($voice['profile'])) {
            if (!is_array($voice['profile']) || array_is_list($voice['profile'])) {
                throw new \InvalidArgumentException("build.json speech.voices.{$name}.profile must be an object of VoiceProfile fields");
            }
            $overrides = [];
            foreach ($voice['profile'] as $field => $value) {
                if ($value !== null && !is_int($value) && !is_float($value)) {
                    throw new \InvalidArgumentException("build.json speech.voices.{$name}.profile.{$field} must be a number");
                }
                $overrides[(string) $field] = $value;
            }
            $profile = (new VoiceProfile())->withOverrides($overrides);
        }

        return new SpeechVoice(
            $name,
            isset($voice['reference']) ? self::path($voice['reference'], "voices.{$name}.reference") : null,
            self::stringList($voice['keys'] ?? null, "voices.{$name}.keys"),
            $seed,
            self::number($voice['exaggeration'] ?? 0.5, "voices.{$name}.exaggeration", 0.0, 2.0),
            self::number($voice['cfgWeight'] ?? 0.5, "voices.{$name}.cfgWeight", 0.0, 1.0),
            $profile,
        );
    }

    private static function path(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value, '/\\ ') === '') {
            throw new \InvalidArgumentException("build.json speech.{$field} must be a project-relative path");
        }
        return rtrim($value, '/\\');
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $field): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException("build.json speech.{$field} must be a list of strings");
        }
        $list = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new \InvalidArgumentException("build.json speech.{$field} must be a list of strings");
            }
            $list[] = $item;
        }
        return $list;
    }

    /** @param list<string> $allowed */
    private static function choice(mixed $value, string $field, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException("build.json speech.{$field} must be one of: " . implode(', ', $allowed));
        }
        return $value;
    }

    private static function number(mixed $value, string $field, float $min, float $max): float
    {
        if ((!is_int($value) && !is_float($value)) || $value < $min || $value > $max) {
            throw new \InvalidArgumentException("build.json speech.{$field} must be a number between {$min} and {$max}");
        }
        return (float) $value;
    }
}
