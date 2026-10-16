<?php

declare(strict_types=1);

namespace PHPolygon\Input;

use PHPolygon\Locale\LocaleManager;

/**
 * Display names of keys and mouse buttons for a key-binding menu or a prompt
 * ("Press [E]").
 *
 * - A printable key shows the character it types in the CURRENT keyboard
 *   layout (php-vio `vio_key_name`, >= 2.33): key code 89 is 'Z' on a German
 *   keyboard. Without php-vio it falls back to the US position.
 * - Every other key and the mouse buttons have a stable slug (`space`,
 *   `left_shift`, `f1`, `mouse_left` ...). With a LocaleManager the label is
 *   the translation of `<prefix><slug>` (default prefix `input.key.`) when the
 *   game provides one, otherwise an English name.
 *
 * Key codes follow GLFW semantics (US positions).
 */
final class KeyLabel
{
    /** @var array<int, string> GLFW key code => slug */
    public const array KEY_SLUGS = [
        32 => 'space',
        256 => 'escape', 257 => 'enter', 258 => 'tab', 259 => 'backspace',
        260 => 'insert', 261 => 'delete', 262 => 'right', 263 => 'left',
        264 => 'down', 265 => 'up', 266 => 'page_up', 267 => 'page_down',
        268 => 'home', 269 => 'end', 280 => 'caps_lock', 281 => 'scroll_lock',
        282 => 'num_lock', 283 => 'print_screen', 284 => 'pause',
        320 => 'kp_0', 321 => 'kp_1', 322 => 'kp_2', 323 => 'kp_3', 324 => 'kp_4',
        325 => 'kp_5', 326 => 'kp_6', 327 => 'kp_7', 328 => 'kp_8', 329 => 'kp_9',
        330 => 'kp_decimal', 331 => 'kp_divide', 332 => 'kp_multiply',
        333 => 'kp_subtract', 334 => 'kp_add', 335 => 'kp_enter', 336 => 'kp_equal',
        340 => 'left_shift', 341 => 'left_control', 342 => 'left_alt', 343 => 'left_super',
        344 => 'right_shift', 345 => 'right_control', 346 => 'right_alt', 347 => 'right_super',
        348 => 'menu',
    ];

    /** @var array<string, string> slug => English fallback */
    private const array ENGLISH = [
        'space' => 'Space', 'escape' => 'Esc', 'enter' => 'Enter', 'tab' => 'Tab',
        'backspace' => 'Backspace', 'insert' => 'Insert', 'delete' => 'Delete',
        'right' => 'Right', 'left' => 'Left', 'down' => 'Down', 'up' => 'Up',
        'page_up' => 'Page Up', 'page_down' => 'Page Down', 'home' => 'Home', 'end' => 'End',
        'caps_lock' => 'Caps Lock', 'scroll_lock' => 'Scroll Lock', 'num_lock' => 'Num Lock',
        'print_screen' => 'Print', 'pause' => 'Pause',
        'kp_decimal' => 'Num .', 'kp_divide' => 'Num /', 'kp_multiply' => 'Num *',
        'kp_subtract' => 'Num -', 'kp_add' => 'Num +', 'kp_enter' => 'Num Enter', 'kp_equal' => 'Num =',
        'left_shift' => 'Shift', 'left_control' => 'Ctrl', 'left_alt' => 'Alt', 'left_super' => 'Super',
        'right_shift' => 'Right Shift', 'right_control' => 'Right Ctrl', 'right_alt' => 'AltGr',
        'right_super' => 'Right Super', 'menu' => 'Menu',
        'mouse_left' => 'Left Mouse', 'mouse_right' => 'Right Mouse', 'mouse_middle' => 'Middle Mouse',
    ];

    /** @var \Closure(int): ?string */
    private \Closure $keyName;

    /**
     * @param (\Closure(int): ?string)|null $keyName key code -> character in the
     *        current layout (default vio_key_name when php-vio is loaded)
     */
    public function __construct(
        private readonly ?LocaleManager $locale = null,
        ?\Closure $keyName = null,
        private readonly string $prefix = 'input.key.',
    ) {
        $this->keyName = $keyName ?? (function_exists('vio_key_name')
            ? static fn (int $key): ?string => vio_key_name($key)
            : static fn (int $key): ?string => null);
    }

    /** Label for a key code or binding. */
    public function label(InputBinding|int $binding): string
    {
        $binding = is_int($binding) ? InputBinding::key($binding) : $binding;

        if ($binding->type === InputBindingType::Key && $binding->code > 32 && $binding->code < 256) {
            $name = ($this->keyName)($binding->code);
            if ($name !== null && $name !== '') {
                return mb_strtoupper($name);
            }
            if ($binding->code <= 126) {
                return chr($binding->code);
            }
        }

        $slug = self::slug($binding);
        if ($slug === null) {
            return '#' . $binding->code;
        }
        $key = $this->prefix . $slug;
        if ($this->locale !== null && $this->locale->has($key)) {
            return $this->locale->get($key);
        }
        return self::english($slug);
    }

    /**
     * Stable identifier of a non-printable key or a mouse button, used as the
     * translation-key suffix; null for printable keys and unknown codes.
     */
    public static function slug(InputBinding|int $binding): ?string
    {
        $binding = is_int($binding) ? InputBinding::key($binding) : $binding;
        if ($binding->type === InputBindingType::MouseButton) {
            return match ($binding->code) {
                0 => 'mouse_left',
                1 => 'mouse_right',
                2 => 'mouse_middle',
                default => 'mouse_' . ($binding->code + 1),
            };
        }
        $code = $binding->code;
        if ($code >= 290 && $code <= 314) {
            return 'f' . ($code - 289);
        }
        return self::KEY_SLUGS[$code] ?? null;
    }

    private static function english(string $slug): string
    {
        if (isset(self::ENGLISH[$slug])) {
            return self::ENGLISH[$slug];
        }
        if (preg_match('/^f(\d+)$/', $slug, $m) === 1) {
            return 'F' . $m[1];
        }
        if (preg_match('/^kp_(\d)$/', $slug, $m) === 1) {
            return 'Num ' . $m[1];
        }
        if (preg_match('/^mouse_(\d+)$/', $slug, $m) === 1) {
            return 'Mouse ' . $m[1];
        }
        return $slug;
    }
}
