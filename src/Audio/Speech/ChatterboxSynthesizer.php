<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

/**
 * Chatterbox Multilingual (Resemble AI, MIT) through `tools/speech/chatterbox_worker.py`.
 * The worker runs in a Python environment of its own (`speech:setup`) or in Docker;
 * it loads the model once per run and clones every voice from its reference WAV.
 *
 * Environment overrides: PHPOLYGON_SPEECH_RUNNER (auto|native|docker),
 * PHPOLYGON_SPEECH_GPU (1/0), PHPOLYGON_SPEECH_PYTHON (interpreter of a ready environment).
 */
final class ChatterboxSynthesizer implements SpeechSynthesizerInterface
{
    public const string VERSION = '0.1.7';

    /** Languages of chatterbox.mtl_tts.SUPPORTED_LANGUAGES. */
    public const array LANGUAGES = ['ar', 'da', 'de', 'el', 'en', 'es', 'fi', 'fr', 'he', 'hi', 'it', 'ja', 'ko', 'ms', 'nl', 'no', 'pl', 'pt', 'ru', 'sv', 'sw', 'tr', 'zh'];

    public const string MODEL_VOLUME = 'phpolygon-speech-models';

    private const string CUDA_INDEX = 'https://download.pytorch.org/whl/cu124';
    private const string CPU_INDEX = 'https://download.pytorch.org/whl/cpu';
    private const string CONTAINER_ROOT = '/work';

    private readonly string $toolsDir;

    public function __construct(
        private readonly string $projectRoot,
        private readonly string $runner = SpeechConfig::RUNNER_AUTO,
        private readonly bool $gpu = false,
        ?string $toolsDir = null,
    ) {
        $this->toolsDir = $toolsDir ?? dirname(__DIR__, 3) . '/tools/speech';
    }

    public static function fromConfig(SpeechConfig $config, string $projectRoot): self
    {
        $runner = getenv('PHPOLYGON_SPEECH_RUNNER');
        $gpu = getenv('PHPOLYGON_SPEECH_GPU');
        return new self(
            $projectRoot,
            is_string($runner) && $runner !== '' ? $runner : $config->runner,
            is_string($gpu) && $gpu !== '' ? $gpu === '1' : $config->gpu,
        );
    }

    public function id(): string
    {
        return 'chatterbox-multilingual/' . self::VERSION;
    }

    public function language(string $locale): ?string
    {
        $tag = strtolower(str_replace('_', '-', $locale));
        // Mandarin from simplified script only; zh-TW/HK/Hant would be read with the wrong characters.
        if (str_starts_with($tag, 'zh')) {
            return in_array($tag, ['zh', 'zh-cn', 'zh-sg', 'zh-hans', 'zh-hans-cn', 'schinese'], true) ? 'zh' : null;
        }
        $language = explode('-', $tag)[0];
        if ($language === 'nb' || $language === 'nn') {
            $language = 'no';
        }
        return in_array($language, self::LANGUAGES, true) ? $language : null;
    }

    public function synthesize(array $jobs, string $workDir, callable $onResult): void
    {
        if ($jobs === []) {
            return;
        }
        $runner = $this->resolveRunner();
        if ($runner === SpeechConfig::RUNNER_DOCKER) {
            $this->ensureImage();
        }

        $byId = [];
        $lines = [];
        foreach ($jobs as $job) {
            $byId[$job->id] = $job;
            $lines[] = json_encode([
                'id' => $job->id,
                'text' => $job->text,
                'language' => $job->language,
                'reference' => $this->workerPath($job->reference, $runner),
                'out' => $this->workerPath($job->wavPath, $runner),
                'seed' => $job->seed,
                'exaggeration' => $job->exaggeration,
                'cfg_weight' => $job->cfgWeight,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $jobsFile = $workDir . '/jobs.jsonl';
        if (file_put_contents($jobsFile, implode("\n", $lines) . "\n") === false) {
            throw new \RuntimeException("cannot write {$jobsFile}");
        }

        $command = $this->command($runner, $this->workerPath($jobsFile, $runner));
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes, $this->projectRoot);
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start the speech worker: ' . implode(' ', $command));
        }
        fclose($pipes[0]);
        $reported = 0;
        while (($line = fgets($pipes[1])) !== false) {
            $result = json_decode($line, true);
            $id = is_array($result) ? ($result['id'] ?? null) : null;
            if (!is_string($id) || !isset($byId[$id]) || !is_array($result)) {
                fwrite(STDERR, $line); // stray library output
                continue;
            }
            $reported++;
            $error = isset($result['error']) && is_string($result['error']) ? $result['error'] : null;
            $onResult($byId[$id], $error);
        }
        fclose($pipes[1]);
        $exit = proc_close($process);
        if ($exit !== 0 && $reported === 0) {
            throw new \RuntimeException("the speech worker exited with code {$exit} ({$runner}) before finishing a line");
        }
    }

    /**
     * Native when a Python environment is set up, else Docker when it is installed.
     */
    public function resolveRunner(): string
    {
        return match ($this->runner) {
            SpeechConfig::RUNNER_NATIVE => is_file($this->python())
                ? SpeechConfig::RUNNER_NATIVE
                : throw new \RuntimeException('no speech environment at ' . $this->python() . ' - run: vendor/bin/phpolygon speech:setup'),
            SpeechConfig::RUNNER_DOCKER => self::which('docker') !== null
                ? SpeechConfig::RUNNER_DOCKER
                : throw new \RuntimeException('speech runner "docker" needs the docker CLI on PATH'),
            SpeechConfig::RUNNER_AUTO => match (true) {
                is_file($this->python()) => SpeechConfig::RUNNER_NATIVE,
                self::which('docker') !== null => SpeechConfig::RUNNER_DOCKER,
                default => throw new \RuntimeException('no speech runner: run vendor/bin/phpolygon speech:setup (Python) or install Docker'),
            },
            default => throw new \RuntimeException("unknown speech runner \"{$this->runner}\" (auto, native, docker)"),
        };
    }

    /**
     * The worker command for a runner; $jobsFile as the worker sees it.
     *
     * @return list<string>
     */
    public function command(string $runner, string $jobsFile): array
    {
        if ($runner === SpeechConfig::RUNNER_NATIVE) {
            return [$this->python(), $this->toolsDir . '/chatterbox_worker.py', '--jobs', $jobsFile];
        }
        $command = ['docker', 'run', '--rm', '-v', $this->projectRoot . ':' . self::CONTAINER_ROOT, '-v', self::MODEL_VOLUME . ':/models'];
        if (function_exists('posix_getuid') && function_exists('posix_getgid')) {
            // Generated files belong to the user, not to root.
            array_push($command, '--user', posix_getuid() . ':' . posix_getgid());
        }
        if ($this->gpu) {
            array_push($command, '--gpus', 'all');
        }
        return [...$command, $this->image(), '--jobs', $jobsFile, '--device', $this->gpu ? 'cuda' : 'auto'];
    }

    public function image(): string
    {
        return 'phpolygon-speech:chatterbox-' . self::VERSION . ($this->gpu ? '-cuda' : '');
    }

    /** Interpreter of the native environment. */
    public function python(): string
    {
        $override = getenv('PHPOLYGON_SPEECH_PYTHON');
        if (is_string($override) && $override !== '') {
            return $override;
        }
        return $this->environmentDir() . (PHP_OS_FAMILY === 'Windows' ? '/Scripts/python.exe' : '/bin/python');
    }

    public function environmentDir(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
        return $home . '/.phpolygon/speech/chatterbox-' . self::VERSION;
    }

    /**
     * Create the native Python environment (uv when installed, else Python 3.10–3.12)
     * or build the Docker image.
     *
     * @param callable(string): void $log
     */
    public function setup(string $runner, callable $log): void
    {
        if ($runner === SpeechConfig::RUNNER_DOCKER) {
            $this->buildImage($log);
            return;
        }

        $dir = $this->environmentDir();
        $python = $this->python();
        $requirements = $this->toolsDir . '/requirements.txt';
        $uv = self::which('uv');
        if ($uv !== null) {
            $log("Creating {$dir} with uv (Python 3.11)...");
            self::run([$uv, 'venv', '--python', '3.11', $dir]);
            $install = [$uv, 'pip', 'install', '--python', $python];
        } else {
            $base = self::which('python3.11') ?? self::which('python3.12') ?? self::which('python3.10')
                ?? throw new \RuntimeException('speech:setup needs uv or Python 3.10-3.12 on PATH (chatterbox-tts pins torch 2.6)');
            $log("Creating {$dir} with {$base}...");
            self::run([$base, '-m', 'venv', $dir]);
            $install = [$python, '-m', 'pip', 'install'];
        }
        if ($this->gpu && PHP_OS_FAMILY === 'Windows') {
            // PyPI ships CPU-only torch for Windows.
            $log('Installing CUDA torch...');
            self::run([...$install, 'torch==2.6.0', 'torchaudio==2.6.0', '--index-url', self::CUDA_INDEX]);
        }
        $log('Installing chatterbox-tts ' . self::VERSION . ' (a few GB)...');
        self::run([...$install, '-r', $requirements]);
    }

    private function ensureImage(): void
    {
        if (self::run(['docker', 'image', 'inspect', $this->image()], quiet: true) !== 0) {
            $this->buildImage(static function (string $message): void {
                fwrite(STDERR, $message . "\n");
            });
        }
    }

    /** @param callable(string): void $log */
    private function buildImage(callable $log): void
    {
        $log('Building Docker image ' . $this->image() . ' (a few GB)...');
        self::run([
            'docker', 'build',
            '-t', $this->image(),
            '--build-arg', 'TORCH_INDEX=' . ($this->gpu ? self::CUDA_INDEX : self::CPU_INDEX),
            $this->toolsDir,
        ]);
    }

    /** A path as the worker sees it: unchanged natively, under /work in Docker. */
    private function workerPath(string $path, string $runner): string
    {
        if ($runner !== SpeechConfig::RUNNER_DOCKER) {
            return $path;
        }
        $root = rtrim($this->projectRoot, '/\\') . '/';
        if (!str_starts_with($path, $root)) {
            throw new \RuntimeException("{$path} is outside the project: the Docker runner only sees the project directory");
        }
        return self::CONTAINER_ROOT . '/' . substr($path, strlen($root));
    }

    /** @param list<string> $command */
    private static function run(array $command, bool $quiet = false): int
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $out = $quiet ? ['file', $null, 'w'] : STDERR;
        $process = proc_open($command, [0 => ['file', $null, 'r'], 1 => $out, 2 => $out], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot run ' . $command[0]);
        }
        $exit = proc_close($process);
        if ($exit !== 0 && !$quiet) {
            throw new \RuntimeException(implode(' ', $command) . " failed with exit code {$exit}");
        }
        return $exit;
    }

    private static function which(string $name): ?string
    {
        $suffixes = PHP_OS_FAMILY === 'Windows' ? ['.exe', '.cmd', '.bat', ''] : [''];
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            foreach ($suffixes as $suffix) {
                $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . $suffix;
                if ($dir !== '' && is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }
        return null;
    }
}
