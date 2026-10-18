<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

/**
 * The fine controls of a voice for one synthesizer, described by one table
 * ({@see static::FIELDS}): type, model default, range, editor step, group, the
 * worker's job field and a description. Validation, build.json parsing and the
 * editor schema all read it. Every field null = the model's own default, so a voice
 * without params sounds (and caches) exactly as the model does by default.
 *
 * {@see SpeechParams} = Chatterbox, {@see VoxCpmParams} = VoxCPM2. A subclass
 * declares FIELDS, a constructor with one nullable promoted property per field
 * that is not a voice field ({@see static::VOICE_FIELDS}), and calls validate().
 *
 * @phpstan-consistent-constructor
 */
abstract readonly class SynthesizerParams
{
    public const string TYPE_FLOAT = 'float';
    public const string TYPE_INT = 'int';
    public const string TYPE_BOOL = 'bool';
    public const string TYPE_STRING = 'string';

    public const string GROUP_VOICE = 'voice';
    public const string GROUP_SAMPLING = 'sampling';
    public const string GROUP_REFERENCE = 'reference';
    public const string GROUP_DECODER = 'decoder';
    public const string GROUP_OUTPUT = 'output';

    /**
     * Every parameter of the synthesizer.
     *
     * @var array<string, array{type: string, default: int|float|bool|string|null, min: int|float|null, max: int|float|null, step: int|float|null, group: string, worker: string, description: string}>
     */
    public const array FIELDS = [];

    /**
     * Fields of the voice itself ({@see SpeechVoice}), not of the params object.
     *
     * @var list<string>
     */
    public const array VOICE_FIELDS = ['seed'];

    /**
     * Fields that only act on a cloned voice (a reference WAV).
     *
     * @var list<string>
     */
    public const array REFERENCE_FIELDS = [];

    /** Range and type check of every set field. */
    protected function validate(): void
    {
        foreach ($this->toArray() as $name => $value) {
            if (is_int($value) || is_float($value)) {
                static::check($name, $value, 'params.' . $name);
            }
        }
    }

    /**
     * build.json `speech.voices.<voice>.params`: camelCase keys of {@see static::FIELDS}
     * except the voice fields; null = default.
     *
     * @param array<mixed> $data
     * @param string $field the prefix of error messages
     * @throws \InvalidArgumentException on unknown keys, wrong types or values out of range
     */
    public static function fromArray(array $data, string $field = 'params'): static
    {
        $known = static::names();
        foreach (array_keys($data) as $key) {
            if (in_array($key, static::VOICE_FIELDS, true)) {
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
            $values[$name] = match (static::FIELDS[$name]['type']) {
                self::TYPE_BOOL => is_bool($value) ? $value : throw new \InvalidArgumentException("{$field}.{$name} must be true or false"),
                self::TYPE_STRING => is_string($value) && trim($value) !== '' ? $value : throw new \InvalidArgumentException("{$field}.{$name} must be a non-empty string"),
                self::TYPE_INT => is_int($value) ? static::check($name, $value, "{$field}.{$name}") : throw new \InvalidArgumentException("{$field}.{$name} must be an integer"),
                default => is_int($value) || is_float($value) ? (float) static::check($name, $value, "{$field}.{$name}") : throw new \InvalidArgumentException("{$field}.{$name} must be a number"),
            };
        }
        return new static(...$values);
    }

    /**
     * The parameters for an editor UI, in display order (voice fields first).
     * `default` null = no default value.
     *
     * @return list<array{name: string, type: string, default: int|float|bool|string|null, min: int|float|null, max: int|float|null, step: int|float|null, group: string, description: string}>
     */
    public static function schema(): array
    {
        $schema = [];
        foreach (static::FIELDS as $name => $spec) {
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
     * Range check of one numeric parameter (of this class or a voice field), with the
     * message build.json errors use.
     *
     * @throws \InvalidArgumentException
     */
    public static function check(string $name, int|float $value, string $label): int|float
    {
        $spec = static::FIELDS[$name] ?? throw new \InvalidArgumentException("unknown speech parameter {$name}");
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

    /** Something only a cloned voice can use is set (reference excerpt, window, transcript). */
    public function usesReference(): bool
    {
        return array_intersect_key($this->toArray(), array_flip(static::REFERENCE_FIELDS)) !== [];
    }

    /**
     * The set fields (camelCase), in FIELDS order.
     *
     * @return array<string, int|float|bool|string>
     */
    public function toArray(): array
    {
        $values = [];
        foreach (static::names() as $name) {
            $value = $this->{$name};
            if ($value !== null) {
                /** @var int|float|bool|string $value */
                $values[$name] = $value;
            }
        }
        return $values;
    }

    /**
     * The worker's job fields (snake_case), only those that are set.
     *
     * @return array<string, int|float|bool|string>
     */
    public function toWorker(): array
    {
        $fields = [];
        foreach ($this->toArray() as $name => $value) {
            $fields[static::FIELDS[$name]['worker']] = $value;
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
        return array_values(array_diff(array_keys(static::FIELDS), static::VOICE_FIELDS));
    }

    private static function format(int|float|null $value): string
    {
        return $value === null ? '-' : (string) $value;
    }
}
