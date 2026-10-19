<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

/**
 * MOSS-TTS v1.5 (OpenMOSS, Apache-2.0; 8B MossTTSDelay on a Qwen3 backbone with the
 * 1.6B MOSS-Audio-Tokenizer, 24 kHz) through `tools/speech/moss_worker.py` – the same
 * runners, workers and remote hosts as Chatterbox ({@see PythonSynthesizer}).
 *
 * - Cloning from a reference WAV, optionally with its transcript ({@see MossParams}
 *   `referenceText`: the reference also opens the answer, closest timbre). No
 *   built-in voice: without a reference every line gets a random voice, so a voice
 *   needs a `reference`.
 * - Language tag per line from the locale ({@see self::language()}; the 31 languages
 *   of the model card, among them cs, hu, ro and mk).
 * - dtype (PHPOLYGON_SPEECH_DTYPE, auto|bfloat16|float16|float32): auto = bfloat16 on
 *   Ampere and newer and on MPS, float16 below Ampere, float32 on CPU.
 * - quant (PHPOLYGON_SPEECH_QUANT, auto|none|nf4|int8): 4-/8-bit backbone weights
 *   (bitsandbytes, CUDA only); auto = nf4 on a card under 20 GB – 16 bit needs ~17 GB.
 * - codec device (PHPOLYGON_SPEECH_CODEC_DEVICE, auto|model|cpu): where the audio
 *   tokenizer (float32, ~7 GB) runs; auto = beside the model while there is room, else
 *   on the CPU. It does not change the sound, so it is no part of {@see self::id()}.
 * - No watermark: the model embeds none, and the worker adds none.
 */
final class MossTtsSynthesizer extends PythonSynthesizer
{
    /** The engine's version of the MOSS-TTS v1.5 worker (environment, image, --require). */
    public const string VERSION = '1.5.0';

    /** transformers runs the checkpoints' own model code (trust_remote_code). */
    public const string TRANSFORMERS_VERSION = '5.0.0';

    /** The checkpoints the worker loads (Hugging Face, pinned revisions in the worker). */
    public const string MODEL = 'OpenMOSS-Team/MOSS-TTS-v1.5';
    public const string CODEC = 'OpenMOSS-Team/MOSS-Audio-Tokenizer';

    /** The 31 languages of the model card (ISO 639; yue = Cantonese, mk = Macedonian, fa = Persian, tl = Tagalog). */
    public const array LANGUAGES = ['ar', 'cs', 'da', 'de', 'el', 'en', 'es', 'fa', 'fi', 'fr', 'he', 'hi', 'hu', 'it', 'ja', 'ko', 'mk', 'ms', 'nl', 'pl', 'pt', 'ro', 'ru', 'sv', 'sw', 'th', 'tl', 'tr', 'vi', 'yue', 'zh'];

    /** Output sample rate of the audio tokenizer. */
    public const int SAMPLE_RATE = 24000;

    /** 8B in float32 plus the tokenizer. */
    public const int CPU_WORKER_RAM = 42_000_000_000;

    /** Loading shard by shard (~5 GB) plus the float32 tokenizer while it waits on the CPU. */
    public const int GPU_WORKER_RAM = 14_000_000_000;

    /** @var list<string> */
    public const array DTYPES = ['auto', 'bfloat16', 'float16', 'float32'];

    /** @var list<string> */
    public const array QUANTS = ['auto', 'none', 'nf4', 'int8'];

    /** @var list<string> */
    public const array CODEC_DEVICES = ['auto', 'model', 'cpu'];

    public static function name(): string
    {
        return 'moss';
    }

    public static function version(): string
    {
        return self::VERSION;
    }

    /** The worker pins transformers; `speech:setup --remote` prints its version. */
    protected static function package(): string
    {
        return 'transformers';
    }

    protected static function requirements(): string
    {
        return 'requirements-moss.txt';
    }

    public static function cpuWorkerRam(): int
    {
        return self::CPU_WORKER_RAM;
    }

    public static function gpuWorkerRam(): int
    {
        return self::GPU_WORKER_RAM;
    }

    /**
     * Every parameter a voice can set, for editor UIs ({@see MossParams::FIELDS}).
     *
     * @return list<array{name: string, type: string, default: int|float|bool|string|null, min: int|float|null, max: int|float|null, step: int|float|null, group: string, description: string}>
     */
    public static function parameterSchema(): array
    {
        return MossParams::schema();
    }

    public function id(): string
    {
        return 'moss-tts/' . self::VERSION . '/' . $this->dtype() . '/' . $this->quant();
    }

    /** The dtype the workers run in: PHPOLYGON_SPEECH_DTYPE or auto. */
    public function dtype(): string
    {
        return self::choice('PHPOLYGON_SPEECH_DTYPE', self::DTYPES);
    }

    /** The weights of the backbone: PHPOLYGON_SPEECH_QUANT or auto. */
    public function quant(): string
    {
        return self::choice('PHPOLYGON_SPEECH_QUANT', self::QUANTS);
    }

    /** Where the audio tokenizer runs: PHPOLYGON_SPEECH_CODEC_DEVICE or auto. */
    public function codecDevice(): string
    {
        return self::choice('PHPOLYGON_SPEECH_CODEC_DEVICE', self::CODEC_DEVICES);
    }

    /** @param list<string> $choices the first one is the default */
    private static function choice(string $env, array $choices): string
    {
        $value = self::env($env) ?? $choices[0];
        if (!in_array($value, $choices, true)) {
            throw new \InvalidArgumentException("{$env} must be one of " . implode(', ', $choices) . ", got \"{$value}\"");
        }
        return $value;
    }

    public function language(string $locale): ?string
    {
        $tag = strtolower(str_replace('_', '-', $locale));
        // Mandarin from simplified script; Cantonese by its own code. Traditional
        // characters (zh-TW) are not measured yet.
        if ($tag === 'schinese' || in_array($tag, ['zh', 'zh-cn', 'zh-sg', 'zh-hans', 'zh-hans-cn'], true)) {
            return 'zh';
        }
        if (str_starts_with($tag, 'zh')) {
            return null;
        }
        $language = match ($language = explode('-', $tag)[0]) {
            'fil' => 'tl',
            'iw' => 'he',
            default => $language,
        };
        return in_array($language, self::LANGUAGES, true) ? $language : null;
    }

    protected function workerArgs(): array
    {
        $args = [];
        foreach (['--dtype' => $this->dtype(), '--quant' => $this->quant(), '--codec-device' => $this->codecDevice()] as $option => $value) {
            if ($value !== 'auto') {
                array_push($args, $option, $value);
            }
        }
        return $args;
    }

    protected function jobFields(SpeechJob $job): array
    {
        if ($job->params !== null && !$job->params instanceof MossParams) {
            throw new \InvalidArgumentException('MOSS-TTS takes MossParams, got ' . $job->params::class);
        }
        if ($job->reference === null) {
            throw new \InvalidArgumentException('MOSS-TTS has no built-in voice: give the voice a reference WAV');
        }
        return $job->params?->toWorker() ?? [];
    }
}
