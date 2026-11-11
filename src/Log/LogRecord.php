<?php

declare(strict_types=1);

namespace PHPolygon\Log;

/**
 * One entry of the game log and the rules that turn it into a line.
 *
 *     2026-11-09 14:03:12.481 f=18233 [ui] click opt_benchmark_start "Benchmark starten"
 *
 * Time with milliseconds, the frame it was written in, the category, the
 * text, then the context: values under integer keys follow bare, values
 * under string keys as `key=value`. A string made of word characters and
 * `. : / \ -` stands as it is, anything else is quoted the JSON way - so a
 * text with spaces, quotes or line breaks stays complete and on one line.
 * A level other than info is named after the category (`WARN`, `ERROR`,
 * `TRACE`), except for an error in the error category.
 */
final class LogRecord
{
    /**
     * @param float $time unix time with fractions of a second
     * @param array<array-key, mixed> $context flat: scalars, null, enums; anything else is JSON-encoded
     */
    public function __construct(
        public readonly float $time,
        public readonly int $frame,
        public readonly LogCategory $category,
        public readonly LogLevel $level,
        public readonly string $text,
        public readonly array $context = [],
    ) {}

    /** The line without its line break. */
    public function format(): string
    {
        return self::stamp($this->time) . ' f=' . $this->frame
            . self::body($this->category, $this->level, $this->text, $this->context);
    }

    private static int $stampSecond = -1;
    private static string $stampText = '';

    /** `Y-m-d H:i:s.mmm` in local time; the date part is formatted once per second. */
    public static function stamp(float $time): string
    {
        $second = (int) $time;
        // A double holding today's unix time keeps only ~6-7 digits after the
        // decimal point (10 of its ~15-17 significant digits are already the
        // whole seconds): truncating the millisecond can read 480 off a value
        // that is really 481 minus a sliver of float noise. round() recovers
        // it; the carry (999.6ms -> 1000) is folded into the next second.
        $ms = (int) round(($time - $second) * 1000.0);
        if ($ms >= 1000) {
            $second++;
            $ms = 0;
        }
        if ($second !== self::$stampSecond) {
            self::$stampSecond = $second;
            self::$stampText = date('Y-m-d H:i:s', $second);
        }

        return self::$stampText . ($ms < 10 ? '.00' : ($ms < 100 ? '.0' : '.')) . $ms;
    }

    /**
     * Everything after the frame number, with its leading space.
     *
     * @param array<array-key, mixed> $context
     */
    public static function body(LogCategory $category, LogLevel $level, string $text, array $context): string
    {
        $line = ' [' . $category->value . ']';
        if ($level !== LogLevel::Info && !($level === LogLevel::Error && $category === LogCategory::Error)) {
            $line .= ' ' . strtoupper($level->value);
        }
        if ($text !== '') {
            // A text of several lines (a stack trace) keeps them, indented, so
            // every line that starts with a date is the start of an entry.
            $line .= ' ' . (str_contains($text, "\n") ? str_replace(["\r\n", "\n"], "\n    ", rtrim($text)) : $text);
        }
        foreach ($context as $key => $value) {
            $line .= is_int($key) ? ' ' . self::value($value) : ' ' . $key . '=' . self::value($value);
        }

        return $line;
    }

    /** One context value as it stands in the line. */
    public static function value(mixed $value): string
    {
        if (is_string($value)) {
            if ($value !== '' && strlen($value) <= 96 && strspn($value, self::BARE) === strlen($value)) {
                return $value;
            }

            return self::quote($value);
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? (string) round($value, 4) : (is_nan($value) ? 'nan' : ($value > 0 ? 'inf' : '-inf'));
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if ($value instanceof \BackedEnum) {
            return self::value($value->value);
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if ($value instanceof \Stringable) {
            return self::quote((string) $value);
        }
        if (is_array($value)) {
            $json = json_encode($value, self::JSON);

            return $json !== false ? $json : '[...]';
        }

        return is_object($value) ? '{' . $value::class . '}' : '?';
    }

    private const string BARE = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.:/\\-';
    private const int JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    private static function quote(string $value): string
    {
        $json = json_encode($value, self::JSON);

        return $json !== false ? $json : '"?"';
    }
}
