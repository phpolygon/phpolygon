<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

use PHPolygon\Audio\Codec\FlacWriter;
use PHPolygon\Audio\Codec\WavReader;
use PHPolygon\Audio\Dsp\Pcm;
use PHPolygon\Audio\Dsp\Voice\VoiceChain;
use PHPolygon\Audio\Dsp\Voice\VoiceProfile;
use PHPolygon\Locale\LocaleManager;

/**
 * Generates the spoken lines of build.json `speech` from the translation files:
 * {@see plan()} compares every line with `speech.lock.json`, {@see sync()}
 * generates what is missing or stale and writes 44.1 kHz mono FLAC at −16 LUFS.
 */
final class SpeechSync
{
    /**
     * Bumped whenever the files written for the same synthesizer output change
     * (post-processing, format); part of every cache key.
     */
    public const int VERSION = 1;

    private ?VoiceChain $chain = null;

    /** @var array<string, string> reference path => sha256 */
    private array $referenceHashes = [];

    public function __construct(
        private readonly SpeechConfig $config,
        private readonly string $projectRoot,
        private readonly SpeechSynthesizerInterface $synthesizer,
    ) {}

    public function outputDir(): string
    {
        return $this->projectRoot . '/' . $this->config->outputDir;
    }

    /**
     * Every spoken line with its status.
     *
     * @param list<string> $locales only these locales; [] = config `only`, else every translation file
     * @param list<string> $keys only keys matching one of these fnmatch patterns; [] = all
     * @return list<SpeechLine>
     */
    public function plan(array $locales = [], array $keys = []): array
    {
        $translations = new LocaleManager();
        $translations->loadDirectory($this->projectRoot . '/' . $this->config->localesDir);
        $manifest = SpeechManifest::load($this->outputDir() . '/' . SpeechFiles::MANIFEST);

        $wanted = $locales !== [] ? $locales : $this->config->only;
        $lines = [];
        foreach ($translations->getAvailableLocales() as $locale) {
            if ($wanted !== [] && !in_array($locale, $wanted, true)) {
                continue;
            }
            $language = $this->synthesizer->language($locale);
            foreach ($translations->all($locale) as $key => $text) {
                if (!$this->config->isSpoken($key) || ($keys !== [] && !self::matchesAny($key, $keys))) {
                    continue;
                }
                $lines[] = $this->line($locale, $key, $text, $language, $manifest);
            }
        }
        return $lines;
    }

    /**
     * Generate every line of $lines that is missing or stale.
     *
     * @param list<SpeechLine> $lines from {@see plan()}
     * @param (callable(SpeechLine, ?string, int, int): void)|null $progress line, error or null, done, total
     */
    public function sync(array $lines, ?callable $progress = null): SpeechResult
    {
        /** @var array<string, SpeechLine> $pending */
        $pending = [];
        $jobs = [];
        $workDir = $this->outputDir() . '/.work';
        foreach ($lines as $line) {
            if (!$line->status->needsSynthesis() || $line->voice === null || $line->language === null) {
                continue;
            }
            $id = (string) count($jobs);
            $pending[$id] = $line;
            $jobs[] = new SpeechJob(
                $id,
                $line->text,
                $line->language,
                $this->projectRoot . '/' . $line->voice->reference,
                $workDir . '/' . $id . '.wav',
                $line->voice->seed,
                $line->voice->exaggeration,
                $line->voice->cfgWeight,
            );
        }
        if ($jobs === []) {
            return new SpeechResult();
        }

        if (!is_dir($workDir) && !mkdir($workDir, 0755, true) && !is_dir($workDir)) {
            throw new \RuntimeException("cannot create {$workDir}");
        }
        $manifest = SpeechManifest::load($this->outputDir() . '/' . SpeechFiles::MANIFEST);
        $generated = [];
        $failed = [];
        /** @var array<string, true> $reported */
        $reported = [];
        $total = count($jobs);

        try {
            $this->synthesizer->synthesize($jobs, $workDir, function (SpeechJob $job, ?string $error) use ($pending, $manifest, &$generated, &$failed, &$reported, $total, $progress): void {
                $line = $pending[$job->id] ?? null;
                if ($line === null || isset($reported[$job->id])) {
                    return;
                }
                $reported[$job->id] = true;
                if ($error === null) {
                    try {
                        $this->writeLine($job->wavPath, $line);
                        $manifest->set($line->locale, $line->key, $line->cacheKey);
                        $manifest->save();
                    } catch (\Throwable $e) {
                        $error = $e->getMessage();
                    }
                }
                @unlink($job->wavPath);
                if ($error === null) {
                    $generated[] = $line;
                } else {
                    $failed[] = ['line' => $line, 'error' => $error];
                }
                if ($progress !== null) {
                    $progress($line, $error, count($generated) + count($failed), $total);
                }
            });
        } finally {
            self::removeTree($workDir);
        }

        foreach (array_diff_key($pending, $reported) as $line) {
            $failed[] = ['line' => $line, 'error' => 'the synthesizer did not report this line'];
        }

        return new SpeechResult($generated, $failed);
    }

    /** True when the text is complete at build time: no `:placeholder`, no plural forms. */
    public static function isSpeakable(string $text): bool
    {
        return trim($text) !== ''
            && !str_contains($text, '|')
            && preg_match('/(?<![\w:]):[A-Za-z_]\w*/u', $text) !== 1;
    }

    private function line(string $locale, string $key, string $text, ?string $language, SpeechManifest $manifest): SpeechLine
    {
        $path = SpeechFiles::path($this->outputDir(), $locale, $key);
        $voice = $this->config->voiceFor($key);
        if ($voice === null) {
            return new SpeechLine($key, $locale, $text, null, $language, '', $path, SpeechStatus::NoVoice);
        }
        if ($language === null) {
            return new SpeechLine($key, $locale, $text, $voice, null, '', $path, SpeechStatus::Unsupported);
        }
        if (!self::isSpeakable($text)) {
            return new SpeechLine($key, $locale, $text, $voice, $language, '', $path, SpeechStatus::Unspeakable);
        }

        $cacheKey = hash('sha256', (string) json_encode([
            self::VERSION,
            VoiceChain::CHAIN_VERSION,
            $this->synthesizer->id(),
            $language,
            $text,
            $voice->fingerprint($this->referenceHash($voice)),
        ], JSON_UNESCAPED_UNICODE));

        $recorded = $manifest->get($locale, $key);
        $status = match (true) {
            $recorded === $cacheKey && is_file($path) => SpeechStatus::Current,
            $recorded !== null || is_file($path) => SpeechStatus::Stale,
            default => SpeechStatus::Missing,
        };
        return new SpeechLine($key, $locale, $text, $voice, $language, $cacheKey, $path, $status);
    }

    private function referenceHash(SpeechVoice $voice): string
    {
        $path = $this->projectRoot . '/' . $voice->reference;
        if (!isset($this->referenceHashes[$path])) {
            $hash = is_file($path) ? hash_file('sha256', $path) : false;
            if ($hash === false) {
                throw new \RuntimeException("speech voice '{$voice->name}': reference {$voice->reference} not found");
            }
            $this->referenceHashes[$path] = $hash;
        }
        return $this->referenceHashes[$path];
    }

    private function writeLine(string $wavPath, SpeechLine $line): void
    {
        $this->chain ??= new VoiceChain();
        $pcm = WavReader::read($wavPath, Pcm::SAMPLE_RATE);
        $pcm = $this->chain->render($pcm, $line->voice->profile ?? new VoiceProfile());
        $flac = FlacWriter::encodeMono(array_map(Pcm::toInt16(...), $pcm), Pcm::SAMPLE_RATE);

        $dir = dirname($line->path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create {$dir}");
        }
        if (file_put_contents($line->path, $flac) === false) {
            throw new \RuntimeException("cannot write {$line->path}");
        }
    }

    /** @param list<string> $patterns */
    private static function matchesAny(string $key, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $key)) {
                return true;
            }
        }
        return false;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }
}
