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
 *                "seed": 7, "exaggeration": 0.6, "profile": { "crush": 0.2 },
 *                "params": { "temperature": 0.6, "cfmSteps": 16, "referenceStart": 2.5 } }
 *   },
 *   "build": "generate",
 *   "runner": "auto",
 *   "gpu": false,
 *   "workers": ["cuda", "cuda", "cpu"],
 *   "device": "auto",
 *   "cpuThreads": null,
 *   "remoteRetries": 5
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
 *            synthesizer's built-in voice; `params` = the fine controls of
 *            {@see SpeechParams} (camelCase, unknown keys are an error)
 *   build    what `phpolygon build` does: generate missing lines, only check, or nothing
 *   runner   auto (native Python environment, else Docker), native or docker
 *   gpu      CUDA: the default device (for `device: auto`), the CUDA torch on Windows,
 *            Docker `--gpus all` with the CUDA image
 *   workers  parallel worker processes, one model each: an int (that many on `device`)
 *            or one device per worker, e.g. ["cuda", "cuda", "cpu"]; ~3.3 GiB VRAM per
 *            CUDA worker, ~4.5 GB RAM per CPU worker, ~2 GB RAM per GPU worker
 *            A worker may run on another machine over SSH:
 *            "ssh://user@host[:port]?device=mps&workers=2" (see SpeechRemote)
 *   device   default device of the workers: auto, cuda, cpu, mps
 *   cpuThreads  torch threads per CPU worker; null = CPU workers share half the
 *            logical cores next to GPU workers, else all of them
 *   remoteRetries  reconnects of a remote worker that drops while lines are queued,
 *            in a row without a finished line (backoff 5, 15, 45, then 120 s);
 *            0 = a dropped remote worker stays gone
 */
final readonly class SpeechConfig
{
    public const string BUILD_GENERATE = 'generate';
    public const string BUILD_CHECK = 'check';
    public const string BUILD_OFF = 'off';

    public const string RUNNER_AUTO = 'auto';
    public const string RUNNER_NATIVE = 'native';
    public const string RUNNER_DOCKER = 'docker';

    public const string DEVICE_AUTO = 'auto';

    /** @var list<string> */
    public const array DEVICES = [self::DEVICE_AUTO, 'cuda', 'cpu', 'mps'];

    /** A worker on the default `device` (the int shorthand of `workers`). */
    public const string WORKER_DEFAULT = 'default';

    /** Reconnects of a dropped remote worker in a row without a finished line. */
    public const int REMOTE_RETRIES = 5;

    /**
     * @param list<string> $keys
     * @param list<SpeechVoice> $voices
     * @param list<string> $only
     * @param list<string> $workers one device (or WORKER_DEFAULT) per worker
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
        public array $workers = [self::WORKER_DEFAULT],
        public string $device = self::DEVICE_AUTO,
        public ?int $cpuThreads = null,
        public int $remoteRetries = self::REMOTE_RETRIES,
    ) {}

    /**
     * Workers from build.json, an option or PHPOLYGON_SPEECH_WORKERS: an int ≥ 1
     * (that many on the default device), a list of devices, or a string "2" /
     * "cuda,cuda,cpu".
     *
     * @return list<string> one device or WORKER_DEFAULT per worker
     * @throws \InvalidArgumentException
     */
    public static function parseWorkers(mixed $value, string $source): array
    {
        if (is_string($value)) {
            $value = trim($value);
            $value = ctype_digit($value) ? (int) $value : array_map('trim', explode(',', $value));
        }
        if (is_int($value)) {
            if ($value < 1) {
                throw new \InvalidArgumentException("{$source} must be at least 1");
            }
            return array_fill(0, $value, self::WORKER_DEFAULT);
        }
        $devices = [self::WORKER_DEFAULT, ...self::DEVICES];
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            throw new \InvalidArgumentException("{$source} must be a worker count or a list of devices like [\"cuda\", \"cuda\", \"cpu\"]");
        }
        $list = [];
        foreach ($value as $device) {
            if (is_string($device) && SpeechRemote::isRemote($device)) {
                [$remote, $count] = SpeechRemote::parse($device, $source);
                array_push($list, ...array_fill(0, $count, $remote->url()));
                continue;
            }
            if (!is_string($device) || !in_array($device, $devices, true)) {
                throw new \InvalidArgumentException("{$source}: unknown device " . json_encode($device) . ' (' . implode(', ', self::DEVICES) . ')');
            }
            $list[] = $device;
        }
        return $list;
    }

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
        $known = ['locales', 'output', 'keys', 'only', 'voices', 'build', 'runner', 'gpu', 'workers', 'device', 'cpuThreads', 'remoteRetries'];
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
        $workers = $data['workers'] ?? 1;
        if (is_string($workers)) {
            throw new \InvalidArgumentException('build.json speech.workers must be a worker count or a list of devices like ["cuda", "cuda", "cpu"]');
        }
        $workers = self::parseWorkers($workers, 'build.json speech.workers');
        $device = self::choice($data['device'] ?? self::DEVICE_AUTO, 'device', self::DEVICES);
        $cpuThreads = $data['cpuThreads'] ?? null;
        if ($cpuThreads !== null && (!is_int($cpuThreads) || $cpuThreads < 1)) {
            throw new \InvalidArgumentException('build.json speech.cpuThreads must be a positive integer or null');
        }
        $remoteRetries = $data['remoteRetries'] ?? self::REMOTE_RETRIES;
        if (!is_int($remoteRetries) || $remoteRetries < 0) {
            throw new \InvalidArgumentException('build.json speech.remoteRetries must be a non-negative integer');
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
            $workers,
            $device,
            $cpuThreads,
            $remoteRetries,
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
            'workers' => $this->workers,
            'device' => $this->device,
            'cpuThreads' => $this->cpuThreads,
            'remoteRetries' => $this->remoteRetries,
        ];
    }

    private static function parseVoice(string $name, mixed $voice): SpeechVoice
    {
        if (!is_array($voice) || array_is_list($voice)) {
            throw new \InvalidArgumentException("build.json speech.voices.{$name} must be an object (\"reference\": a WAV to clone, or null for the built-in voice)");
        }
        $known = ['reference', 'keys', 'seed', 'exaggeration', 'cfgWeight', 'profile', 'params'];
        foreach (array_keys($voice) as $key) {
            if (!in_array($key, $known, true)) {
                throw new \InvalidArgumentException("build.json speech.voices.{$name}.{$key} is unknown (supported: " . implode(', ', $known) . ')');
            }
        }
        $seed = $voice['seed'] ?? SpeechParams::FIELDS['seed']['default'];
        if (!is_int($seed)) {
            throw new \InvalidArgumentException("build.json speech.voices.{$name}.seed must be an integer");
        }
        SpeechParams::check('seed', $seed, "build.json speech.voices.{$name}.seed");
        $params = null;
        if (isset($voice['params'])) {
            if (!is_array($voice['params']) || ($voice['params'] !== [] && array_is_list($voice['params']))) {
                throw new \InvalidArgumentException("build.json speech.voices.{$name}.params must be an object of speech parameters");
            }
            $params = SpeechParams::fromArray($voice['params'], "build.json speech.voices.{$name}.params");
            if (!isset($voice['reference']) && $params->usesReference()) {
                throw new \InvalidArgumentException("build.json speech.voices.{$name}.params: " . implode(', ', SpeechParams::REFERENCE_FIELDS) . ' need a reference WAV; the built-in voice has none');
            }
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
            self::number($voice['exaggeration'] ?? SpeechParams::FIELDS['exaggeration']['default'], 'exaggeration', "voices.{$name}.exaggeration"),
            self::number($voice['cfgWeight'] ?? SpeechParams::FIELDS['cfgWeight']['default'], 'cfgWeight', "voices.{$name}.cfgWeight"),
            $profile,
            $params,
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

    /** A float voice field, in the range of {@see SpeechParams::FIELDS}. */
    private static function number(mixed $value, string $parameter, string $field): float
    {
        if (!is_int($value) && !is_float($value)) {
            $spec = SpeechParams::FIELDS[$parameter];
            throw new \InvalidArgumentException("build.json speech.{$field} must be a number between {$spec['min']} and {$spec['max']}");
        }
        return (float) SpeechParams::check($parameter, $value, "build.json speech.{$field}");
    }
}
