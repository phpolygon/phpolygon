<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

/**
 * Chatterbox Multilingual (Resemble AI, MIT) through `tools/speech/chatterbox_worker.py`.
 * The worker runs in a Python environment of its own (`speech:setup`) or in Docker;
 * it loads the model once per run and clones every voice from its reference WAV, or
 * speaks with the model's built-in voice when a job has no reference.
 *
 * Runners, workers, remote hosts and setup: {@see PythonSynthesizer}. Each instance
 * needs about 3.3 GiB of VRAM on CUDA (an 8 GiB card fits two workers).
 *
 * Every job carries seed, exaggeration and cfg weight; the fields of
 * {@see SpeechParams} (sampling, max tokens, decoder steps, watermark, reference
 * excerpt and windows) join the job line only when set, so the worker runs with
 * the model's defaults otherwise ({@see self::parameterSchema()}).
 */
final class ChatterboxSynthesizer extends PythonSynthesizer
{
    public const string VERSION = '0.1.7';

    /** Languages of chatterbox.mtl_tts.SUPPORTED_LANGUAGES. */
    public const array LANGUAGES = ['ar', 'da', 'de', 'el', 'en', 'es', 'fi', 'fr', 'he', 'hi', 'it', 'ja', 'ko', 'ms', 'nl', 'no', 'pl', 'pt', 'ru', 'sv', 'sw', 'tr', 'zh'];

    /** System RAM of a worker that runs the model on the CPU (or "auto" without GPU), in bytes. */
    public const int CPU_WORKER_RAM = 4_500_000_000;

    /** System RAM of a worker on CUDA or MPS, in bytes. */
    public const int GPU_WORKER_RAM = 2_000_000_000;

    public static function name(): string
    {
        return 'chatterbox';
    }

    public static function version(): string
    {
        return self::VERSION;
    }

    protected static function package(): string
    {
        return 'chatterbox-tts';
    }

    protected static function requirements(): string
    {
        return 'requirements.txt';
    }

    /** The default synthesizer: `speech:setup` needs no `--synth` for it. */
    protected static function setupOption(): string
    {
        return '';
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
     * Every parameter a voice can set, for editor UIs: name, type (float|int|bool),
     * model default, min, max, step, group (voice|sampling|reference|decoder|output)
     * and description. The same table validates build.json ({@see SpeechParams::FIELDS}).
     * Chatterbox 0.1.7 has no speaking-rate control, so there is no `speed`.
     *
     * @return list<array{name: string, type: string, default: int|float|bool|string|null, min: int|float|null, max: int|float|null, step: int|float|null, group: string, description: string}>
     */
    public static function parameterSchema(): array
    {
        return SpeechParams::schema();
    }

    public function id(): string
    {
        return 'chatterbox-multilingual/' . self::VERSION;
    }

    public function language(string $locale): ?string
    {
        $tag = strtolower(str_replace('_', '-', $locale));
        // Mandarin in either script: Chatterbox reads traditional characters as well
        // (zh-TW/HK/Hant measured as accurate as simplified ones).
        if (str_starts_with($tag, 'zh') || in_array($tag, ['schinese', 'tchinese'], true)) {
            return 'zh';
        }
        $language = explode('-', $tag)[0];
        if ($language === 'nb' || $language === 'nn') {
            $language = 'no';
        }
        return in_array($language, self::LANGUAGES, true) ? $language : null;
    }

    protected function jobFields(SpeechJob $job): array
    {
        if ($job->params !== null && !$job->params instanceof SpeechParams) {
            throw new \InvalidArgumentException('Chatterbox takes SpeechParams, got ' . $job->params::class);
        }
        return [
            'exaggeration' => $job->exaggeration,
            'cfg_weight' => $job->cfgWeight,
        ] + ($job->params?->toWorker() ?? []);
    }
}
