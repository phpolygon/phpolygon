<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

/**
 * VoxCPM2 (OpenBMB, Apache-2.0; 2B parameters, 48 kHz) through
 * `tools/speech/voxcpm_worker.py` – the same runners, workers and remote hosts as
 * Chatterbox ({@see PythonSynthesizer}).
 *
 * - Cloning from a reference WAV, optionally with its transcript
 *   ({@see VoxCpmParams} `referenceText`: "ultimate" cloning, closest timbre).
 * - Voice Design: a voice from a description ({@see self::design()}, `speech:design`);
 *   a job without reference needs `params.description` – the model has no built-in voice.
 * - No language id: the model reads the language from the text; {@see self::language()}
 *   only says which locales it speaks.
 * - dtype: bfloat16 from compute capability 8.0 (Ampere) on, float16 below it
 *   (Turing has no native bfloat16), float32 on CPU and MPS; PHPOLYGON_SPEECH_DTYPE
 *   (bfloat16|float16|float32) overrides. PHPOLYGON_SPEECH_COMPILE=0 skips
 *   torch.compile (on by default on CUDA where triton is installed, i.e. Linux/Docker).
 * - No watermark: voxcpm embeds none, and the worker adds none.
 */
final class VoxCpmSynthesizer extends PythonSynthesizer
{
    public const string VERSION = '2.0.3';

    /** The checkpoint the worker loads (Hugging Face). */
    public const string MODEL = 'openbmb/VoxCPM2';

    /** The 30 languages of the model card (ISO 639-1; my = Burmese, km = Khmer, lo = Lao, tl = Tagalog). */
    public const array LANGUAGES = ['ar', 'da', 'de', 'el', 'en', 'es', 'fi', 'fr', 'he', 'hi', 'id', 'it', 'ja', 'km', 'ko', 'lo', 'ms', 'my', 'nl', 'no', 'pl', 'pt', 'ru', 'sv', 'sw', 'th', 'tl', 'tr', 'vi', 'zh'];

    /**
     * Not on the model card, spoken intelligibly in a Whisper check (see the engine
     * CLAUDE.md, Speech synthesis): accepted by {@see self::language()} as well.
     * Ukrainian: character error rate ~3 %, with a Russian-leaning "и". Measured and
     * left out: cs (14 %), ro (19 %), bg (20 %), hu (the take ran on and on).
     */
    public const array UNLISTED_LANGUAGES = ['uk'];

    /** Output sample rate of the model. */
    public const int SAMPLE_RATE = 48000;

    public const int CPU_WORKER_RAM = 10_000_000_000;

    public const int GPU_WORKER_RAM = 3_000_000_000;

    /** @var list<string> */
    public const array DTYPES = ['auto', 'bfloat16', 'float16', 'float32'];

    public static function name(): string
    {
        return 'voxcpm';
    }

    public static function version(): string
    {
        return self::VERSION;
    }

    protected static function package(): string
    {
        return 'voxcpm';
    }

    protected static function requirements(): string
    {
        return 'requirements-voxcpm.txt';
    }

    /** gcc and the libc headers: triton compiles the kernels of torch.compile at run time. */
    protected static function systemPackages(): string
    {
        return 'gcc libc6-dev';
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
     * Every parameter a voice can set, for editor UIs ({@see VoxCpmParams::FIELDS}).
     *
     * @return list<array{name: string, type: string, default: int|float|bool|string|null, min: int|float|null, max: int|float|null, step: int|float|null, group: string, description: string}>
     */
    public static function parameterSchema(): array
    {
        return VoxCpmParams::schema();
    }

    public function id(): string
    {
        return 'voxcpm2/' . self::VERSION . '/' . $this->dtype();
    }

    /** The dtype the workers run in: PHPOLYGON_SPEECH_DTYPE or auto. */
    public function dtype(): string
    {
        $dtype = self::env('PHPOLYGON_SPEECH_DTYPE') ?? 'auto';
        if (!in_array($dtype, self::DTYPES, true)) {
            throw new \InvalidArgumentException("PHPOLYGON_SPEECH_DTYPE must be one of " . implode(', ', self::DTYPES) . ", got \"{$dtype}\"");
        }
        return $dtype;
    }

    public function language(string $locale): ?string
    {
        $tag = strtolower(str_replace('_', '-', $locale));
        // Mandarin from simplified script; traditional characters are not measured yet.
        if (str_starts_with($tag, 'zh')) {
            return in_array($tag, ['zh', 'zh-cn', 'zh-sg', 'zh-hans', 'zh-hans-cn'], true) ? 'zh' : null;
        }
        if ($tag === 'schinese') {
            return 'zh';
        }
        $language = explode('-', $tag)[0];
        $language = match ($language) {
            'nb', 'nn' => 'no',
            'fil' => 'tl',
            'iw' => 'he',
            'in' => 'id',
            default => $language,
        };
        return in_array($language, self::LANGUAGES, true) || in_array($language, self::UNLISTED_LANGUAGES, true) ? $language : null;
    }

    protected function workerArgs(): array
    {
        $args = [];
        $dtype = $this->dtype();
        if ($dtype !== 'auto') {
            array_push($args, '--dtype', $dtype);
        }
        if (self::env('PHPOLYGON_SPEECH_COMPILE') === '0') {
            $args[] = '--no-compile';
        }
        return $args;
    }

    protected function jobFields(SpeechJob $job): array
    {
        if ($job->params !== null && !$job->params instanceof VoxCpmParams) {
            throw new \InvalidArgumentException('VoxCPM2 takes VoxCpmParams, got ' . $job->params::class);
        }
        if ($job->reference === null && ($job->params === null || $job->params->description === null)) {
            throw new \InvalidArgumentException('VoxCPM2 has no built-in voice: give the voice a reference WAV or params.description (Voice Design)');
        }
        return $job->params?->toWorker() ?? [];
    }

    /**
     * Voice Design: speak $design->text with the described voice, once per seed, and
     * write each take as a 48 kHz mono WAV (leading/trailing silence trimmed) –
     * `$out` for one seed, `name-s{seed}.wav` for several ({@see VoiceDesign::takePath()}).
     * The takes run in one worker (one model load). Docker sees only the project:
     * the takes are generated in a scratch directory inside it and moved to `$out`.
     *
     * @param list<int> $seeds one take per seed
     * @param (callable(int, string, ?string, ?float): void)|null $onTake seed, path, error or null, seconds
     * @return array<int, array{path: string, seconds: ?float, error: ?string}> per seed
     */
    public function design(VoiceDesign $design, string $out, array $seeds = [0], ?callable $onTake = null): array
    {
        $seeds = array_values(array_unique($seeds));
        if ($seeds === []) {
            throw new \InvalidArgumentException('voice design needs at least one seed');
        }
        $language = $this->language($design->locale)
            ?? throw new \InvalidArgumentException("VoxCPM2 does not speak \"{$design->locale}\" (" . implode(', ', [...self::LANGUAGES, ...self::UNLISTED_LANGUAGES]) . ')');
        $params = $design->jobParams();
        $work = rtrim($this->projectRoot, '/\\') . '/.speech-design-' . bin2hex(random_bytes(4));
        if (!mkdir($work, 0755, true) && !is_dir($work)) {
            throw new \RuntimeException("cannot create {$work}");
        }
        $jobs = [];
        foreach ($seeds as $i => $seed) {
            VoxCpmParams::check('seed', $seed, 'seed');
            $jobs[] = new SpeechJob((string) $i, $design->text, $language, null, "{$work}/{$i}.wav", $seed, 0.0, 0.0, $params);
        }
        $results = [];
        try {
            $this->synthesize($jobs, $work, function (SpeechJob $job, ?string $error) use ($out, $seeds, $onTake, &$results): void {
                $path = VoiceDesign::takePath($out, $job->seed, $seeds);
                $seconds = null;
                if ($error === null) {
                    try {
                        self::move($job->wavPath, $path);
                        $seconds = self::wavSeconds($path);
                    } catch (\RuntimeException $e) {
                        $error = $e->getMessage();
                    }
                }
                $results[$job->seed] = ['path' => $path, 'seconds' => $seconds, 'error' => $error];
                if ($onTake !== null) {
                    $onTake($job->seed, $path, $error, $seconds);
                }
            });
        } finally {
            foreach (glob($work . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($work);
        }
        foreach ($jobs as $job) {
            $results[$job->seed] ??= ['path' => VoiceDesign::takePath($out, $job->seed, $seeds), 'seconds' => null, 'error' => 'the synthesizer did not report this take'];
        }
        return $results;
    }

    private static function move(string $from, string $to): void
    {
        $dir = dirname($to);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create {$dir}");
        }
        if (!@rename($from, $to) && !(@copy($from, $to) && @unlink($from))) {
            throw new \RuntimeException("cannot write {$to}");
        }
    }

    /**
     * Length of a PCM WAV in seconds from its header (fmt and data chunks).
     *
     * @throws \RuntimeException when it is no readable WAV
     */
    public static function wavSeconds(string $path): float
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("cannot read {$path}");
        }
        try {
            $head = fread($handle, 12);
            if (!is_string($head) || strlen($head) < 12 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WAVE') {
                throw new \RuntimeException("{$path} is no WAV");
            }
            $byteRate = null;
            while (!feof($handle)) {
                $chunk = fread($handle, 8);
                if (!is_string($chunk) || strlen($chunk) < 8) {
                    break;
                }
                /** @var array{1: int} $size */
                $size = unpack('V', substr($chunk, 4, 4));
                $id = substr($chunk, 0, 4);
                if ($id === 'fmt ') {
                    $fmt = $size[1] > 0 ? (string) fread($handle, $size[1]) : '';
                    /** @var array{rate: int} $parsed */
                    $parsed = unpack('vformat/vchannels/Vsamples/Vrate', $fmt);
                    $byteRate = $parsed['rate'];
                    if ($size[1] % 2 === 1) {
                        fread($handle, 1);
                    }
                    continue;
                }
                if ($id === 'data' && $byteRate !== null && $byteRate > 0) {
                    return $size[1] / $byteRate;
                }
                fseek($handle, $size[1] + ($size[1] % 2), SEEK_CUR);
            }
        } finally {
            fclose($handle);
        }
        throw new \RuntimeException("{$path} has no fmt/data chunk");
    }
}
