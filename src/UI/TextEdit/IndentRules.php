<?php

declare(strict_types=1);

namespace PHPolygon\UI\TextEdit;

/**
 * How a {@see TextEditModel} indents: the width of one level, whether a level
 * is a tab or spaces, which line endings open a block (Enter indents one
 * level more after them) and which characters close one (typed as the first
 * thing on a line, they take one level away).
 *
 * The default suits both brace languages and colon-block languages; a
 * caller that knows the language narrows it with {@see braces()} or
 * {@see offside()} so, e.g., a ternary's ':' at the end of a line does not
 * indent the next one.
 */
final readonly class IndentRules
{
    /** Bracket pairs Enter splits apart when the caret sits between them. */
    private const array PAIRS = ['{' => '}', '(' => ')', '[' => ']'];

    /**
     * @param list<string> $openers line endings after which Enter indents one level
     * @param list<string> $closers characters that dedent an indentation-only line when typed
     */
    public function __construct(
        public int $width = 4,
        public array $openers = ['{', '(', '[', ':'],
        public array $closers = ['}', ')', ']'],
        public bool $useTabs = false,
    ) {
        if ($width < 1) {
            throw new \InvalidArgumentException('Indent width must be at least 1');
        }
    }

    /** Brace languages (C, Java, JavaScript, PHP, Rust, ...): blocks open with '{', '(' or '['. */
    public static function braces(int $width = 4, bool $useTabs = false): self
    {
        return new self($width, ['{', '(', '['], ['}', ')', ']'], $useTabs);
    }

    /** Colon-block (off-side rule) languages: a line ending with ':' opens a block, brackets too. */
    public static function offside(int $width = 4, bool $useTabs = false): self
    {
        return new self($width, [':', '(', '[', '{'], ['}', ')', ']'], $useTabs);
    }

    /** One indentation level as text. */
    public function unit(): string
    {
        return $this->useTabs ? "\t" : str_repeat(' ', $this->width);
    }

    /** The closing bracket that belongs to $opener, null when it is no bracket. */
    public function closerFor(string $opener): ?string
    {
        return self::PAIRS[$opener] ?? null;
    }
}
