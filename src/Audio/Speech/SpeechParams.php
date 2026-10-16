<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

/**
 * The fine controls of a voice beyond seed, exaggeration and cfg weight: how
 * speech tokens are sampled, how the flow-matching decoder runs, whether the
 * output is watermarked and which part of the reference WAV the voice is
 * cloned from. Every field null = the model's own default, so a voice without
 * params sounds (and caches) exactly as before.
 *
 * {@see self::FIELDS} is the one source of the ranges: the constructor, build.json
 * parsing ({@see SpeechConfig}) and the editor schema ({@see self::schema()}) all
 * read it. seed, exaggeration and cfgWeight live on {@see SpeechVoice} /
 * {@see SpeechJob} (older API) but are listed in the schema as well.
 *
 * Defaults and ranges are those of Chatterbox Multilingual 0.1.7. The model has
 * no speaking-rate control (its `speed` is an unimplemented TODO); change the
 * tempo afterwards with {@see \PHPolygon\Audio\Dsp\Voice\VoiceProfile} `tempo`.
 */
final readonly class SpeechParams
{
    public const string TYPE_FLOAT = 'float';
    public const string TYPE_INT = 'int';
    public const string TYPE_BOOL = 'bool';

    public const string GROUP_VOICE = 'voice';
    public const string GROUP_SAMPLING = 'sampling';
    public const string GROUP_REFERENCE = 'reference';
    public const string GROUP_DECODER = 'decoder';
    public const string GROUP_OUTPUT = 'output';

    /** Fields of the voice itself ({@see SpeechVoice}), not of the params object. */
    public const array VOICE_FIELDS = ['seed', 'exaggeration', 'cfgWeight'];

    /** Fields that only act on a cloned voice (a reference WAV), not on the built-in one. */
    public const array REFERENCE_FIELDS = ['referenceStart', 'referenceLength', 'promptSeconds', 'voiceSeconds'];

    /**
     * Every parameter: type, model default (null = none, e.g. "the whole file"),
     * range, editor step, group, the worker's job field and a description.
     *
     * @var array<string, array{type: string, default: int|float|bool|null, min: int|float|null, max: int|float|null, step: int|float|null, group: string, worker: string, description: string}>
     */
    public const array FIELDS = [
        'seed' => [
            'type' => self::TYPE_INT, 'default' => 0, 'min' => 0, 'max' => 4294967295, 'step' => 1,
            'group' => self::GROUP_VOICE, 'worker' => 'seed',
            'description' => 'Random seed of a line; the same seed, text and settings give the same take on the same device.',
        ],
        'exaggeration' => [
            'type' => self::TYPE_FLOAT, 'default' => 0.5, 'min' => 0.0, 'max' => 2.0, 'step' => 0.05,
            'group' => self::GROUP_VOICE, 'worker' => 'exaggeration',
            'description' => 'Emotional intensity; 0.5 is neutral, higher is more expressive and usually faster.',
        ],
        'cfgWeight' => [
            'type' => self::TYPE_FLOAT, 'default' => 0.5, 'min' => 0.0, 'max' => 1.0, 'step' => 0.05,
            'group' => self::GROUP_VOICE, 'worker' => 'cfg_weight',
            'description' => 'Classifier-free guidance: how closely the delivery follows the reference; lower is slower and more deliberate, 0 ignores the reference accent.',
        ],
        'temperature' => [
            'type' => self::TYPE_FLOAT, 'default' => 0.8, 'min' => 0.05, 'max' => 2.0, 'step' => 0.05,
            'group' => self::GROUP_SAMPLING, 'worker' => 'temperature',
            'description' => 'Sampling temperature of the speech tokens; lower keeps the takes of a voice closer to each other, higher varies more.',
        ],
        'topP' => [
            'type' => self::TYPE_FLOAT, 'default' => 1.0, 'min' => 0.01, 'max' => 1.0, 'step' => 0.01,
            'group' => self::GROUP_SAMPLING, 'worker' => 'top_p',
            'description' => 'Nucleus sampling: only the most likely tokens up to this probability mass; 1 disables it.',
        ],
        'minP' => [
            'type' => self::TYPE_FLOAT, 'default' => 0.05, 'min' => 0.0, 'max' => 1.0, 'step' => 0.01,
            'group' => self::GROUP_SAMPLING, 'worker' => 'min_p',
            'description' => 'Drops tokens less likely than this fraction of the most likely one; 0 disables it.',
        ],
        'repetitionPenalty' => [
            'type' => self::TYPE_FLOAT, 'default' => 2.0, 'min' => 1.0, 'max' => 3.0, 'step' => 0.05,
            'group' => self::GROUP_SAMPLING, 'worker' => 'repetition_penalty',
            'description' => 'Penalty on speech tokens already generated; 1 disables it, higher fights stutter and loops.',
        ],
        'maxNewTokens' => [
            'type' => self::TYPE_INT, 'default' => 1000, 'min' => 1, 'max' => 4096, 'step' => 25,
            'group' => self::GROUP_SAMPLING, 'worker' => 'max_new_tokens',
            'description' => 'Upper limit of speech tokens per line (25 per second, so 1000 = 40 s); a longer line is cut off.',
        ],
        'cfmSteps' => [
            'type' => self::TYPE_INT, 'default' => 10, 'min' => 1, 'max' => 50, 'step' => 1,
            'group' => self::GROUP_DECODER, 'worker' => 'cfm_steps',
            'description' => 'Flow-matching steps of the decoder that turns tokens into audio; fewer is faster and rougher.',
        ],
        'referenceStart' => [
            'type' => self::TYPE_FLOAT, 'default' => 0.0, 'min' => 0.0, 'max' => 3600.0, 'step' => 0.1,
            'group' => self::GROUP_REFERENCE, 'worker' => 'reference_start',
            'description' => 'Seconds of the reference WAV skipped before the voice is cloned from it.',
        ],
        'referenceLength' => [
            'type' => self::TYPE_FLOAT, 'default' => null, 'min' => 0.5, 'max' => 3600.0, 'step' => 0.1,
            'group' => self::GROUP_REFERENCE, 'worker' => 'reference_length',
            'description' => 'Seconds of the reference WAV used from referenceStart on; empty = to the end. The speaker embedding hears all of it.',
        ],
        'promptSeconds' => [
            'type' => self::TYPE_FLOAT, 'default' => 6.0, 'min' => 0.5, 'max' => 6.0, 'step' => 0.5,
            'group' => self::GROUP_REFERENCE, 'worker' => 'prompt_seconds',
            'description' => 'Seconds of the reference the token model hears as its speech prompt (at most 150 tokens = 6 s).',
        ],
        'voiceSeconds' => [
            'type' => self::TYPE_FLOAT, 'default' => 10.0, 'min' => 0.5, 'max' => 10.0, 'step' => 0.5,
            'group' => self::GROUP_REFERENCE, 'worker' => 'voice_seconds',
            'description' => 'Seconds of the reference the decoder takes its timbre from (the model is trained on at most 10 s).',
        ],
        'watermark' => [
            'type' => self::TYPE_BOOL, 'default' => true, 'min' => null, 'max' => null, 'step' => null,
            'group' => self::GROUP_OUTPUT, 'worker' => 'watermark',
            'description' => 'Embed the model\'s inaudible Perth watermark; switch it off only where your own licence and disclosure policy allow it.',
        ],
    ];

    public function __construct(
        public ?float $temperature = null,
        public ?float $topP = null,
        public ?float $minP = null,
        public ?float $repetitionPenalty = null,
        public ?int $maxNewTokens = null,
        public ?int $cfmSteps = null,
        public ?float $referenceStart = null,
        public ?float $referenceLength = null,
        public ?float $promptSeconds = null,
        public ?float $voiceSeconds = null,
        public ?bool $watermark = null,
    ) {
        foreach ($this->toArray() as $name => $value) {
            if (!is_bool($value)) {
                self::check($name, $value, 'params.' . $name);
            }
        }
    }

    /**
     * build.json `speech.voices.<voice>.params`: camelCase keys of {@see self::FIELDS}
     * except seed, exaggeration and cfgWeight (those stay on the voice); null = default.
     *
     * @param array<mixed> $data
     * @param string $field the prefix of error messages
     * @throws \InvalidArgumentException on unknown keys, wrong types or values out of range
     */
    public static function fromArray(array $data, string $field = 'params'): self
    {
        $known = self::names();
        foreach (array_keys($data) as $key) {
            if (in_array($key, self::VOICE_FIELDS, true)) {
                throw new \InvalidArgumentException("{$field}.{$key} belongs to the voice itself, not to params");
            }
            if (!in_array($key, $known, true)) {
                throw new \InvalidArgumentException("{$field}.{$key} is unknown (supported: " . implode(', ', $known) . ')');
            }
        }
        $values = [];
        foreach ($known as $name) {
            $value = $data[$name] ?? null;
            if ($value === null) {
                $values[$name] = null;
                continue;
            }
            $values[$name] = match (self::FIELDS[$name]['type']) {
                self::TYPE_BOOL => is_bool($value) ? $value : throw new \InvalidArgumentException("{$field}.{$name} must be true or false"),
                self::TYPE_INT => is_int($value) ? self::check($name, $value, "{$field}.{$name}") : throw new \InvalidArgumentException("{$field}.{$name} must be an integer"),
                default => is_int($value) || is_float($value) ? (float) self::check($name, $value, "{$field}.{$name}") : throw new \InvalidArgumentException("{$field}.{$name} must be a number"),
            };
        }
        /** @var array{temperature: ?float, topP: ?float, minP: ?float, repetitionPenalty: ?float, maxNewTokens: ?int, cfmSteps: ?int, referenceStart: ?float, referenceLength: ?float, promptSeconds: ?float, voiceSeconds: ?float, watermark: ?bool} $values */
        return new self(...$values);
    }

    /**
     * The parameters for an editor UI, in display order: seed, exaggeration and
     * cfgWeight (fields of the voice) first, then every field of this class.
     * `default` null = no default value (referenceLength: the whole file).
     *
     * @return list<array{name: string, type: string, default: int|float|bool|null, min: int|float|null, max: int|float|null, step: int|float|null, group: string, description: string}>
     */
    public static function schema(): array
    {
        $schema = [];
        foreach (self::FIELDS as $name => $spec) {
            $schema[] = [
                'name' => $name,
                'type' => $spec['type'],
                'default' => $spec['default'],
                'min' => $spec['min'],
                'max' => $spec['max'],
                'step' => $spec['step'],
                'group' => $spec['group'],
                'description' => $spec['description'],
            ];
        }
        return $schema;
    }

    /**
     * Range check of one parameter (of this class or a voice field), with the
     * message build.json errors use.
     *
     * @throws \InvalidArgumentException
     */
    public static function check(string $name, int|float $value, string $label): int|float
    {
        $spec = self::FIELDS[$name] ?? throw new \InvalidArgumentException("unknown speech parameter {$name}");
        if (($spec['min'] !== null && $value < $spec['min']) || ($spec['max'] !== null && $value > $spec['max'])) {
            throw new \InvalidArgumentException(sprintf(
                '%s must be a number between %s and %s, got %s',
                $label,
                self::format($spec['min']),
                self::format($spec['max']),
                self::format($value),
            ));
        }
        return $value;
    }

    /** Nothing set: the model runs with its own defaults. */
    public function isDefault(): bool
    {
        return $this->toArray() === [];
    }

    /** Something only a cloned voice can use is set (reference excerpt or window). */
    public function usesReference(): bool
    {
        return array_intersect_key($this->toArray(), array_flip(self::REFERENCE_FIELDS)) !== [];
    }

    /**
     * The set fields (camelCase), in {@see self::FIELDS} order.
     *
     * @return array<string, int|float|bool>
     */
    public function toArray(): array
    {
        $values = [];
        foreach (self::names() as $name) {
            $value = $this->{$name};
            if ($value !== null) {
                /** @var int|float|bool $value */
                $values[$name] = $value;
            }
        }
        return $values;
    }

    /**
     * The worker's job fields (snake_case), only those that are set.
     *
     * @return array<string, int|float|bool>
     */
    public function toWorker(): array
    {
        $fields = [];
        foreach ($this->toArray() as $name => $value) {
            $fields[self::FIELDS[$name]['worker']] = $value;
        }
        return $fields;
    }

    /**
     * The camelCase names of the fields of this class.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_values(array_diff(array_keys(self::FIELDS), self::VOICE_FIELDS));
    }

    private static function format(int|float|null $value): string
    {
        return $value === null ? '-' : (string) $value;
    }
}
