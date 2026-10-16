<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

/**
 * Chatterbox Multilingual (Resemble AI, MIT) through `tools/speech/chatterbox_worker.py`.
 * The worker runs in a Python environment of its own (`speech:setup`) or in Docker;
 * it loads the model once per run and clones every voice from its reference WAV, or
 * speaks with the model's built-in voice when a job has no reference.
 *
 * Parallel workers: Chatterbox generates token by token with batch size 1, so one
 * model instance leaves most of a GPU idle. `workers` runs several processes, each
 * with its own model, on a device of its own: `["cuda", "cuda", "cpu"]`, or an int
 * as shorthand for that many workers on the default `device`. Jobs are handed out
 * one at a time from a shared queue ({@see SpeechWorkerPool}). Each instance needs
 * about 3.3 GiB of VRAM on CUDA; before the start the free system RAM is checked
 * (~{@see self::CPU_WORKER_RAM} per CPU worker, ~{@see self::GPU_WORKER_RAM} per
 * GPU worker) and workers are dropped, CPU first, with a warning when it is short.
 *
 * Environment overrides: PHPOLYGON_SPEECH_RUNNER (auto|native|docker),
 * PHPOLYGON_SPEECH_GPU (1/0), PHPOLYGON_SPEECH_PYTHON (interpreter of a ready environment),
 * PHPOLYGON_SPEECH_DEVICE (auto|cuda|cpu|mps), PHPOLYGON_SPEECH_WORKERS (`2` or
 * `cuda,cuda,cpu`), PHPOLYGON_SPEECH_CPU_THREADS (torch threads per CPU worker).
 */
final class ChatterboxSynthesizer implements SpeechSynthesizerInterface
{
    public const string VERSION = '0.1.7';

    /** Languages of chatterbox.mtl_tts.SUPPORTED_LANGUAGES. */
    public const array LANGUAGES = ['ar', 'da', 'de', 'el', 'en', 'es', 'fi', 'fr', 'he', 'hi', 'it', 'ja', 'ko', 'ms', 'nl', 'no', 'pl', 'pt', 'ru', 'sv', 'sw', 'tr', 'zh'];

    public const string MODEL_VOLUME = 'phpolygon-speech-models';

    /** System RAM of a worker that runs the model on the CPU (or "auto" without GPU), in bytes. */
    public const int CPU_WORKER_RAM = 4_500_000_000;

    /** System RAM of a worker on CUDA or MPS, in bytes. */
    public const int GPU_WORKER_RAM = 2_000_000_000;

    private const string CUDA_INDEX = 'https://download.pytorch.org/whl/cu124';
    private const string CPU_INDEX = 'https://download.pytorch.org/whl/cpu';
    private const string CONTAINER_ROOT = '/work';

    private readonly string $toolsDir;

    /** @var list<string> device per worker; SpeechConfig::WORKER_DEFAULT = the default device */
    private readonly array $workers;

    /** @var \Closure(): ?int */
    private readonly \Closure $freeMemory;

    /** @var \Closure(string): void */
    private readonly \Closure $log;

    /**
     * @param int|list<string> $workers worker count on the default device, or one device per worker
     * @param string $device default device: auto (cuda when `gpu`, else the worker's choice), cuda, cpu, mps
     * @param ?int $cpuThreads torch threads per CPU worker; null = CPU workers share half the
     *                         logical cores when GPU workers run beside them, else all of them
     * @param (callable(): ?int)|null $freeMemory free system RAM in bytes, null = unknown (no check)
     * @param ?int $logicalCores logical CPU cores; null = detected
     * @param (callable(string): void)|null $log warnings and worker output; default STDERR
     * @param list<string> $ssh the SSH client of remote workers
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly string $runner = SpeechConfig::RUNNER_AUTO,
        private readonly bool $gpu = false,
        ?string $toolsDir = null,
        int|array $workers = 1,
        private readonly string $device = SpeechConfig::DEVICE_AUTO,
        private readonly ?int $cpuThreads = null,
        ?callable $freeMemory = null,
        private readonly ?int $logicalCores = null,
        ?callable $log = null,
        private readonly array $ssh = ['ssh'],
    ) {
        $this->toolsDir = $toolsDir ?? dirname(__DIR__, 3) . '/tools/speech';
        $this->workers = SpeechConfig::parseWorkers($workers, 'speech workers');
        if (!in_array($device, SpeechConfig::DEVICES, true)) {
            throw new \InvalidArgumentException("unknown speech device \"{$device}\" (" . implode(', ', SpeechConfig::DEVICES) . ')');
        }
        if ($cpuThreads !== null && $cpuThreads < 1) {
            throw new \InvalidArgumentException('speech cpuThreads must be at least 1');
        }
        $this->freeMemory = $freeMemory !== null ? \Closure::fromCallable($freeMemory) : self::measureFreeMemory(...);
        $this->log = $log !== null ? \Closure::fromCallable($log) : static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        };
    }

    /** @param (callable(): ?int)|null $freeMemory free system RAM in bytes; null = measured */
    public static function fromConfig(SpeechConfig $config, string $projectRoot, ?callable $freeMemory = null): self
    {
        return self::fromOptions($projectRoot, $config->runner, $config->gpu, $config->workers, $config->device, $config->cpuThreads, $freeMemory);
    }

    /**
     * For tools that run the synthesizer without build.json `speech`: the given
     * options, overridden by PHPOLYGON_SPEECH_RUNNER / _GPU / _WORKERS / _DEVICE / _CPU_THREADS.
     *
     * @param int|list<string> $workers
     * @param (callable(): ?int)|null $freeMemory free system RAM in bytes; null = measured
     */
    public static function fromOptions(
        string $projectRoot,
        string $runner = SpeechConfig::RUNNER_AUTO,
        bool $gpu = false,
        int|array $workers = 1,
        string $device = SpeechConfig::DEVICE_AUTO,
        ?int $cpuThreads = null,
        ?callable $freeMemory = null,
    ): self {
        $env = static function (string $name): ?string {
            $value = getenv($name);
            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };
        $envGpu = $env('PHPOLYGON_SPEECH_GPU');
        $envWorkers = $env('PHPOLYGON_SPEECH_WORKERS');
        $envThreads = $env('PHPOLYGON_SPEECH_CPU_THREADS');
        if ($envThreads !== null && (!ctype_digit($envThreads) || (int) $envThreads < 1)) {
            throw new \InvalidArgumentException("PHPOLYGON_SPEECH_CPU_THREADS must be a positive integer, got \"{$envThreads}\"");
        }
        return new self(
            $projectRoot,
            $env('PHPOLYGON_SPEECH_RUNNER') ?? $runner,
            $envGpu !== null ? $envGpu === '1' : $gpu,
            workers: $envWorkers !== null ? SpeechConfig::parseWorkers($envWorkers, 'PHPOLYGON_SPEECH_WORKERS') : $workers,
            device: $env('PHPOLYGON_SPEECH_DEVICE') ?? $device,
            cpuThreads: $envThreads !== null ? (int) $envThreads : $cpuThreads,
            freeMemory: $freeMemory,
        );
    }

    /**
     * The workers a run starts: one device each (auto only when the default device is
     * auto without `gpu`), and the torch thread count of the CPU workers (null = the
     * worker's own default). Drops workers, CPU first, when the free RAM is short.
     *
     * Remote workers (`ssh://…`) keep their place and cost no local RAM.
     *
     * @return list<array{device: string, threads: ?int, remote: ?SpeechRemote}>
     */
    public function workerPlan(): array
    {
        $default = $this->defaultDevice();
        $remotes = [];
        $devices = [];
        foreach ($this->workers as $worker) {
            if (SpeechRemote::isRemote($worker)) {
                $remotes[] = SpeechRemote::parse($worker)[0];
            } else {
                $devices[] = $worker === SpeechConfig::WORKER_DEFAULT ? $default : $worker;
            }
        }

        if (count($devices) + count($remotes) > 1 && $devices !== []) {
            $free = ($this->freeMemory)();
            if ($free !== null) {
                $wanted = $devices;
                while (count($devices) > ($remotes === [] ? 1 : 0) && self::ramNeeded($devices) > $free) {
                    $drop = array_key_last($devices);
                    foreach (array_reverse(array_keys($devices)) as $i) {
                        if (!self::onGpu($devices[$i])) {
                            $drop = $i;
                            break;
                        }
                    }
                    array_splice($devices, $drop, 1);
                }
                if ($devices !== $wanted) {
                    ($this->log)(sprintf(
                        'speech: %.1f GB free RAM is not enough for the workers [%s] (~%.1f GB per CPU worker, ~%.1f GB per GPU worker) - running [%s]',
                        $free / 1e9,
                        implode(', ', $wanted),
                        self::CPU_WORKER_RAM / 1e9,
                        self::GPU_WORKER_RAM / 1e9,
                        implode(', ', $devices),
                    ));
                }
            }
        }

        $cpuWorkers = count(array_filter($devices, static fn (string $d): bool => !self::onGpu($d)));
        $threads = $this->cpuThreads;
        if ($threads === null && count($devices) > 1 && $cpuWorkers > 0) {
            $cores = $this->logicalCores ?? self::detectLogicalCores();
            $budget = $cpuWorkers < count($devices) ? intdiv($cores, 2) : $cores;
            $threads = max(1, intdiv($budget, $cpuWorkers));
        }
        $plan = array_map(static fn (string $d): array => ['device' => $d, 'threads' => self::onGpu($d) ? null : $threads, 'remote' => null], $devices);
        foreach ($remotes as $remote) {
            $plan[] = ['device' => $remote->device, 'threads' => null, 'remote' => $remote];
        }
        return $plan;
    }

    private function defaultDevice(): string
    {
        return $this->device === SpeechConfig::DEVICE_AUTO && $this->gpu ? 'cuda' : $this->device;
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
        // No more workers than lines: each one loads a model of a few GB.
        $plan = array_slice($this->workerPlan(), 0, count($jobs));
        $runner = SpeechConfig::RUNNER_NATIVE;
        if (array_filter($plan, static fn (array $w): bool => $w['remote'] === null) !== []) {
            $runner = $this->resolveRunner();
            if ($runner === SpeechConfig::RUNNER_DOCKER) {
                $this->ensureImage();
            }
        }
        if (count($plan) > 1) {
            ($this->log)('speech: ' . count($plan) . ' workers (' . implode(', ', array_map(
                static fn (array $w): string => ($w['remote'] !== null ? $w['remote']->target() . ':' : '') . $w['device'] . ($w['threads'] !== null ? "/{$w['threads']}t" : ''),
                $plan,
            )) . ')');
        }
        $specs = [];
        foreach ($plan as $w) {
            $specs[] = $w['remote'] !== null
                ? ['command' => $this->remoteCommand($w['remote']), 'label' => $w['remote']->target(), 'requeue' => true]
                : ['command' => $this->command($runner, $w['device'], $w['threads'])];
        }

        $hashes = [];
        /** @var array<int, array<string, true>> $sent reference hashes each remote worker has */
        $sent = [];
        $encode = function (SpeechJob $job, int $worker) use ($plan, $runner, &$hashes, &$sent): string {
            $line = [
                'id' => $job->id,
                'text' => $job->text,
                'language' => $job->language,
                'reference' => null,
                'out' => null,
                'seed' => $job->seed,
                'exaggeration' => $job->exaggeration,
                'cfg_weight' => $job->cfgWeight,
            ];
            if ($plan[$worker]['remote'] === null) {
                $line['reference'] = $job->reference !== null ? $this->workerPath($job->reference, $runner) : null;
                $line['out'] = $this->workerPath($job->wavPath, $runner);
            } elseif ($job->reference !== null) {
                // Inline: the host gets each reference WAV once and keeps it by hash.
                $hash = $hashes[$job->reference] ??= (string) sha1_file($job->reference);
                $line['reference'] = $hash;
                if (!isset($sent[$worker][$hash])) {
                    $wav = file_get_contents($job->reference);
                    if ($wav === false) {
                        throw new \RuntimeException("cannot read {$job->reference}");
                    }
                    $line['reference_wav'] = base64_encode($wav);
                    $sent[$worker][$hash] = true;
                }
            }
            return json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        };

        (new SpeechWorkerPool($specs, $this->projectRoot, $this->log))->run($jobs, $encode, $onResult);
    }

    /**
     * The command of a worker on a remote host: ssh runs the engine's own worker
     * script (sent inline with the command, so host and engine never differ) in the
     * host's speech environment, with inline WAVs, a version check and
     * PYTORCH_ENABLE_MPS_FALLBACK=1 (ops MPS lacks run on the CPU).
     *
     * @return list<string>
     */
    public function remoteCommand(SpeechRemote $remote): array
    {
        $script = file_get_contents($this->toolsDir . '/chatterbox_worker.py');
        if ($script === false) {
            throw new \RuntimeException("cannot read {$this->toolsDir}/chatterbox_worker.py");
        }
        $bootstrap = "import base64,sys;sys.argv[0]='chatterbox_worker.py';"
            . "exec(compile(base64.b64decode('" . base64_encode($script) . "'),'chatterbox_worker.py','exec'))";
        $setup = 'vendor/bin/phpolygon speech:setup --remote=' . $remote->url();
        $shell = 'PY=' . $remote->pythonWord(self::VERSION) . '; '
            . 'if [ ! -x "$PY" ]; then echo ' . SpeechRemote::quote('no speech environment on this host - run: ' . $setup) . ' "($PY)" >&2; exit 127; fi; '
            . 'PYTORCH_ENABLE_MPS_FALLBACK=1 exec "$PY" -c ' . SpeechRemote::quote($bootstrap)
            . ' --jobs - --inline --require ' . self::VERSION . ' --device ' . $remote->device;
        return [...$remote->sshCommand($this->ssh), $shell];
    }

    /**
     * Set up the speech environment on a remote host over SSH: uv (or Python
     * 3.10–3.12) creates ~/.phpolygon/speech/chatterbox-{version} and installs
     * chatterbox-tts from PyPI (macOS: MPS torch, Linux: CUDA torch). Safe to repeat.
     *
     * @param callable(string): void $log
     */
    public function setupRemote(SpeechRemote $remote, callable $log): void
    {
        $requirements = file($this->toolsDir . '/requirements.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($requirements === false) {
            throw new \RuntimeException("cannot read {$this->toolsDir}/requirements.txt");
        }
        $packages = implode(' ', array_map(
            static fn (string $r): string => SpeechRemote::quote(trim($r)),
            array_values(array_filter($requirements, static fn (string $r): bool => trim($r) !== '' && !str_starts_with(trim($r), '#'))),
        ));
        $dir = '"$HOME"/.phpolygon/speech/chatterbox-' . self::VERSION;
        $script = implode("\n", [
            'set -e',
            'export PATH="$HOME/.local/bin:$HOME/.cargo/bin:/opt/homebrew/bin:/usr/local/bin:$PATH"',
            "DIR={$dir}",
            'if command -v uv >/dev/null 2>&1; then',
            '  [ -x "$DIR/bin/python" ] || uv venv --python 3.11 "$DIR"',
            '  uv pip install --python "$DIR/bin/python" ' . $packages,
            'else',
            '  BASE=$(command -v python3.11 || command -v python3.12 || command -v python3.10 || true)',
            '  if [ -z "$BASE" ]; then echo "speech:setup --remote needs uv (https://docs.astral.sh/uv/) or Python 3.10-3.12 on $(hostname)" >&2; exit 1; fi',
            '  [ -x "$DIR/bin/python" ] || "$BASE" -m venv "$DIR"',
            '  "$DIR/bin/python" -m pip install ' . $packages,
            'fi',
            '"$DIR/bin/python" -c "import importlib.metadata as m, torch; print(\'chatterbox-tts\', m.version(\'chatterbox-tts\'), \'torch\', torch.__version__, \'cuda\', torch.cuda.is_available(), \'mps\', torch.backends.mps.is_available())"',
        ]);
        $log("Setting up ~/.phpolygon/speech/chatterbox-" . self::VERSION . " on {$remote->target()} (a few GB)...");
        self::run([...$remote->sshCommand($this->ssh), $script]);
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
     * The command of one worker that reads its jobs from stdin (`--jobs -`).
     * Docker runs every worker as a container of its own (`docker run -i`): the
     * simplest way to give each one its own stdin, device and exit code.
     *
     * @param ?int $threads torch threads when the worker runs on the CPU
     * @return list<string>
     */
    public function command(string $runner, string $device = SpeechConfig::DEVICE_AUTO, ?int $threads = null): array
    {
        $args = ['--jobs', '-', '--device', $device];
        if ($threads !== null) {
            array_push($args, '--threads', (string) $threads);
        }
        if ($runner === SpeechConfig::RUNNER_NATIVE) {
            return [$this->python(), $this->toolsDir . '/chatterbox_worker.py', ...$args];
        }
        $command = ['docker', 'run', '-i', '--rm', '-v', $this->projectRoot . ':' . self::CONTAINER_ROOT, '-v', self::MODEL_VOLUME . ':/models'];
        if (function_exists('posix_getuid') && function_exists('posix_getgid')) {
            // Generated files belong to the user, not to root.
            array_push($command, '--user', posix_getuid() . ':' . posix_getgid());
        }
        if ($this->usesCuda() && $device !== 'cpu') {
            array_push($command, '--gpus', 'all');
        }
        return [...$command, $this->image(), ...$args];
    }

    /** The Docker image: the CUDA one when `gpu` is set or a worker runs on CUDA. */
    public function image(): string
    {
        return 'phpolygon-speech:chatterbox-' . self::VERSION . ($this->usesCuda() ? '-cuda' : '');
    }

    private function usesCuda(): bool
    {
        if ($this->gpu) {
            return true;
        }
        $default = $this->device;
        foreach ($this->workers as $device) {
            if (($device === SpeechConfig::WORKER_DEFAULT ? $default : $device) === 'cuda') {
                return true;
            }
        }
        return false;
    }

    private static function onGpu(string $device): bool
    {
        return $device === 'cuda' || $device === 'mps';
    }

    /** @param list<string> $devices */
    private static function ramNeeded(array $devices): int
    {
        $sum = 0;
        foreach ($devices as $device) {
            $sum += self::onGpu($device) ? self::GPU_WORKER_RAM : self::CPU_WORKER_RAM;
        }
        return $sum;
    }

    /**
     * Free (available) system RAM in bytes, null when it cannot be read.
     * Linux MemAvailable, macOS free + inactive + speculative pages,
     * Windows FreePhysicalMemory.
     */
    public static function measureFreeMemory(): ?int
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $meminfo = @file_get_contents('/proc/meminfo');
            if (is_string($meminfo) && preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $meminfo, $m) === 1) {
                return (int) $m[1] * 1024;
            }
            return null;
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            $vmStat = self::capture(['vm_stat']);
            if ($vmStat === null || preg_match('/page size of (\d+) bytes/', $vmStat, $size) !== 1) {
                return null;
            }
            $pages = 0;
            foreach (['free', 'inactive', 'speculative'] as $kind) {
                if (preg_match('/^Pages ' . $kind . ':\s+(\d+)/m', $vmStat, $m) === 1) {
                    $pages += (int) $m[1];
                }
            }
            return $pages * (int) $size[1];
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $kb = self::capture(['powershell', '-NoProfile', '-NonInteractive', '-Command', '(Get-CimInstance Win32_OperatingSystem).FreePhysicalMemory']);
            return $kb !== null && ctype_digit(trim($kb)) ? (int) trim($kb) * 1024 : null;
        }
        return null;
    }

    private static function detectLogicalCores(): int
    {
        $count = match (PHP_OS_FAMILY) {
            'Windows' => (int) getenv('NUMBER_OF_PROCESSORS'),
            'Darwin' => (int) trim((string) self::capture(['sysctl', '-n', 'hw.logicalcpu'])),
            default => (int) trim((string) self::capture(['nproc'])),
        };
        return $count > 0 ? $count : 4;
    }

    /** @param list<string> $command */
    private static function capture(array $command): ?string
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @proc_open($command, [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $null, 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return proc_close($process) === 0 && is_string($out) ? $out : null;
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
        if ($this->usesCuda() && PHP_OS_FAMILY === 'Windows') {
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
            '--build-arg', 'TORCH_INDEX=' . ($this->usesCuda() ? self::CUDA_INDEX : self::CPU_INDEX),
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
