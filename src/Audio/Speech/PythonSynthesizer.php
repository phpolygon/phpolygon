<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

/**
 * A speech model that runs as a Python worker (`tools/speech/<model>_worker.py` on
 * the shared protocol of `tools/speech/speech_worker.py`): what every such
 * synthesizer shares – the runners (native environment, Docker, SSH host), the
 * worker plan (devices, CPU threads, RAM check), the job encoding with inline
 * references for remote hosts, setup of environment, image and remote host.
 * A model adds its id, language table, package and its own job fields.
 *
 * Parallel workers: these models generate step by step with batch size 1, so one
 * model instance leaves most of a GPU idle. `workers` runs several processes, each
 * with its own model, on a device of its own: `["cuda", "cuda", "cpu"]`, or an int
 * as shorthand for that many workers on the default `device`. Jobs are handed out
 * one at a time from a shared queue ({@see SpeechWorkerPool}). Before the start the
 * free system RAM is checked ({@see static::cpuWorkerRam()} per CPU worker,
 * {@see static::gpuWorkerRam()} per GPU worker) and workers are dropped, CPU first,
 * with a warning when it is short.
 *
 * Environment overrides: PHPOLYGON_SPEECH_RUNNER (auto|native|docker),
 * PHPOLYGON_SPEECH_GPU (1/0), PHPOLYGON_SPEECH_PYTHON (interpreter of a ready environment),
 * PHPOLYGON_SPEECH_DEVICE (auto|cuda|cpu|mps), PHPOLYGON_SPEECH_WORKERS (`2` or
 * `cuda,cuda,cpu`), PHPOLYGON_SPEECH_CPU_THREADS (torch threads per CPU worker),
 * PHPOLYGON_SPEECH_REMOTE_RETRIES (reconnects of a dropped remote worker; 0 = none).
 *
 * @phpstan-consistent-constructor
 */
abstract class PythonSynthesizer implements SpeechSynthesizerInterface
{
    /** Named Docker volume of the model weights, shared by every model (HF cache). */
    public const string MODEL_VOLUME = 'phpolygon-speech-models';

    /** The module every worker imports (protocol, inline references, out-of-memory exit). */
    public const string WORKER_MODULE = 'speech_worker.py';

    protected const string CUDA_INDEX = 'https://download.pytorch.org/whl/cu124';
    protected const string CPU_INDEX = 'https://download.pytorch.org/whl/cpu';
    protected const string TORCH_VERSION = '2.6.0';
    private const string CONTAINER_ROOT = '/work';

    protected readonly string $toolsDir;

    /** @var list<string> device per worker; SpeechConfig::WORKER_DEFAULT = the default device */
    private readonly array $workers;

    /** @var \Closure(): ?int */
    private readonly \Closure $freeMemory;

    /** @var \Closure(string): void */
    protected readonly \Closure $log;

    /**
     * @param int|list<string> $workers worker count on the default device, or one device per worker
     * @param string $device default device: auto (cuda when `gpu`, else the worker's choice), cuda, cpu, mps
     * @param ?int $cpuThreads torch threads per CPU worker; null = CPU workers share half the
     *                         logical cores when GPU workers run beside them, else all of them
     * @param (callable(): ?int)|null $freeMemory free system RAM in bytes, null = unknown (no check)
     * @param ?int $logicalCores logical CPU cores; null = detected
     * @param (callable(string): void)|null $log warnings and worker output; default STDERR
     * @param list<string> $ssh the SSH client of remote workers
     * @param int $remoteRetries reconnects of a dropped remote worker in a row without a finished line; 0 = none
     * @param list<float> $remoteBackoff seconds before reconnect attempt 1, 2, …; the last value repeats
     */
    public function __construct(
        protected readonly string $projectRoot,
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
        private readonly int $remoteRetries = SpeechConfig::REMOTE_RETRIES,
        private readonly array $remoteBackoff = SpeechWorkerPool::BACKOFF,
    ) {
        if ($remoteRetries < 0) {
            throw new \InvalidArgumentException('speech remoteRetries must not be negative');
        }
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

    /** Short name: environment `~/.phpolygon/speech/{name}-{version}`, image tag, worker `{name}_worker.py`. */
    abstract public static function name(): string;

    /** The pinned version of the model package. */
    abstract public static function version(): string;

    /** The PyPI package the worker checks with `--require`. */
    abstract protected static function package(): string;

    /** The requirements file in the tools directory. */
    abstract protected static function requirements(): string;

    /** System RAM of a worker that runs the model on the CPU (or "auto" without GPU), in bytes. */
    abstract public static function cpuWorkerRam(): int;

    /** System RAM of a worker on CUDA or MPS, in bytes. */
    abstract public static function gpuWorkerRam(): int;

    /**
     * The model's own fields of a job line (besides id, text, language, reference,
     * out and seed), from the job and its params.
     *
     * @return array<string, int|float|bool|string>
     */
    abstract protected function jobFields(SpeechJob $job): array;

    /**
     * Arguments every worker of this model gets after the shared ones.
     *
     * @return list<string>
     */
    protected function workerArgs(): array
    {
        return [];
    }

    /** The option of `speech:setup` that picks this synthesizer (with a leading space). */
    protected static function setupOption(): string
    {
        return ' --synth=' . static::name();
    }

    /** Debian packages the Docker image needs besides the shared ones (space-separated). */
    protected static function systemPackages(): string
    {
        return '';
    }

    /** The worker script in the tools directory. */
    public static function workerScript(): string
    {
        return static::name() . '_worker.py';
    }

    /** @param (callable(): ?int)|null $freeMemory free system RAM in bytes; null = measured */
    public static function fromConfig(SpeechConfig $config, string $projectRoot, ?callable $freeMemory = null): static
    {
        return static::fromOptions($projectRoot, $config->runner, $config->gpu, $config->workers, $config->device, $config->cpuThreads, $freeMemory, $config->remoteRetries);
    }

    /**
     * For tools that run the synthesizer without build.json `speech`: the given
     * options, overridden by PHPOLYGON_SPEECH_RUNNER / _GPU / _WORKERS / _DEVICE / _CPU_THREADS
     * / _REMOTE_RETRIES.
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
        int $remoteRetries = SpeechConfig::REMOTE_RETRIES,
    ): static {
        $envGpu = self::env('PHPOLYGON_SPEECH_GPU');
        $envWorkers = self::env('PHPOLYGON_SPEECH_WORKERS');
        $envThreads = self::env('PHPOLYGON_SPEECH_CPU_THREADS');
        if ($envThreads !== null && (!ctype_digit($envThreads) || (int) $envThreads < 1)) {
            throw new \InvalidArgumentException("PHPOLYGON_SPEECH_CPU_THREADS must be a positive integer, got \"{$envThreads}\"");
        }
        $envRetries = self::env('PHPOLYGON_SPEECH_REMOTE_RETRIES');
        if ($envRetries !== null && !ctype_digit($envRetries)) {
            throw new \InvalidArgumentException("PHPOLYGON_SPEECH_REMOTE_RETRIES must be a non-negative integer, got \"{$envRetries}\"");
        }
        return new static(
            $projectRoot,
            self::env('PHPOLYGON_SPEECH_RUNNER') ?? $runner,
            $envGpu !== null ? $envGpu === '1' : $gpu,
            workers: $envWorkers !== null ? SpeechConfig::parseWorkers($envWorkers, 'PHPOLYGON_SPEECH_WORKERS') : $workers,
            device: self::env('PHPOLYGON_SPEECH_DEVICE') ?? $device,
            cpuThreads: $envThreads !== null ? (int) $envThreads : $cpuThreads,
            freeMemory: $freeMemory,
            remoteRetries: $envRetries !== null ? (int) $envRetries : $remoteRetries,
        );
    }

    /** A non-empty, trimmed environment variable, or null. */
    protected static function env(string $name): ?string
    {
        $value = getenv($name);
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** Reconnects of a dropped remote worker in a row without a finished line. */
    public function remoteRetries(): int
    {
        return $this->remoteRetries;
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
                        static::cpuWorkerRam() / 1e9,
                        static::gpuWorkerRam() / 1e9,
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

    public function synthesize(array $jobs, string $workDir, callable $onResult): void
    {
        if ($jobs === []) {
            return;
        }
        // A job this model cannot take fails the run before a worker loads a model.
        foreach ($jobs as $job) {
            $this->jobFields($job);
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
            ] + $this->jobFields($job);
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

        // A reconnected remote worker is a new process: it gets its references inline again.
        $onRestart = static function (int $worker) use (&$sent): void {
            unset($sent[$worker]);
        };
        (new SpeechWorkerPool($specs, $this->projectRoot, $this->log, $this->remoteRetries, $this->remoteBackoff))
            ->run($jobs, $encode, $onResult, $onRestart);
    }

    /**
     * The command of a worker on a remote host: ssh runs the engine's own worker
     * script and the shared worker module (sent inline with the command, so host and
     * engine never differ) in the host's speech environment, with inline WAVs, a
     * version check and PYTORCH_ENABLE_MPS_FALLBACK=1 (ops MPS lacks run on the CPU).
     * Both scripts travel zlib-compressed: a Windows command line ends at 32 767
     * characters.
     *
     * @return list<string>
     */
    public function remoteCommand(SpeechRemote $remote): array
    {
        $script = static::workerScript();
        $bootstrap = 'import base64,sys,types,zlib;d=lambda s:zlib.decompress(base64.b64decode(s));'
            . "m=types.ModuleType('speech_worker');m.__file__='" . self::WORKER_MODULE . "';"
            . "exec(compile(d('" . self::pack($this->readTool(self::WORKER_MODULE)) . "'),'" . self::WORKER_MODULE . "','exec'),m.__dict__);"
            . "sys.modules['speech_worker']=m;sys.argv[0]='{$script}';"
            . "exec(compile(d('" . self::pack($this->readTool($script)) . "'),'{$script}','exec'))";
        $setup = 'vendor/bin/phpolygon speech:setup' . static::setupOption() . ' --remote=' . $remote->url();
        $shell = 'PY=' . $remote->pythonWord(static::version(), static::name()) . '; '
            . 'if [ ! -x "$PY" ]; then echo ' . SpeechRemote::quote('no speech environment on this host - run: ' . $setup) . ' "($PY)" >&2; exit 127; fi; '
            . 'PYTORCH_ENABLE_MPS_FALLBACK=1 exec "$PY" -c ' . SpeechRemote::quote($bootstrap)
            . ' --jobs - --inline --require ' . static::version() . ' --device ' . $remote->device
            . implode('', array_map(static fn (string $a): string => ' ' . SpeechRemote::quote($a), $this->workerArgs()));
        return [...$remote->sshCommand($this->ssh), $shell];
    }

    /** A script as the remote bootstrap unpacks it: zlib, then base64. */
    public static function pack(string $source): string
    {
        $packed = gzcompress($source, 9);
        if ($packed === false) {
            throw new \RuntimeException('cannot compress a speech worker script');
        }
        return base64_encode($packed);
    }

    private function readTool(string $file): string
    {
        $content = file_get_contents($this->toolsDir . '/' . $file);
        if ($content === false) {
            throw new \RuntimeException("cannot read {$this->toolsDir}/{$file}");
        }
        return $content;
    }

    /** The environment directory relative to the home directory. */
    public static function environmentName(): string
    {
        return '.phpolygon/speech/' . static::name() . '-' . static::version();
    }

    /**
     * Set up the speech environment on a remote host over SSH: uv (or Python
     * 3.10–3.12) creates ~/.phpolygon/speech/{name}-{version} and installs the
     * requirements from PyPI (macOS: MPS torch, Linux: CUDA torch). Safe to repeat.
     *
     * @param callable(string): void $log
     */
    public function setupRemote(SpeechRemote $remote, callable $log): void
    {
        $requirements = file($this->toolsDir . '/' . static::requirements(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($requirements === false) {
            throw new \RuntimeException("cannot read {$this->toolsDir}/" . static::requirements());
        }
        $packages = implode(' ', array_map(
            static fn (string $r): string => SpeechRemote::quote(trim($r)),
            array_values(array_filter($requirements, static fn (string $r): bool => trim($r) !== '' && !str_starts_with(trim($r), '#'))),
        ));
        $dir = '"$HOME"/' . static::environmentName();
        $package = static::package();
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
            '"$DIR/bin/python" -c "import importlib.metadata as m, torch; print(\'' . $package . '\', m.version(\'' . $package . '\'), \'torch\', torch.__version__, \'cuda\', torch.cuda.is_available(), \'mps\', torch.backends.mps.is_available())"',
        ]);
        $log('Setting up ~/' . static::environmentName() . " on {$remote->target()} (a few GB)...");
        self::run([...$remote->sshCommand($this->ssh), $script]);
    }

    /**
     * Native when a Python environment is set up, else Docker when it is installed.
     */
    public function resolveRunner(): string
    {
        $setup = 'vendor/bin/phpolygon speech:setup' . static::setupOption();
        return match ($this->runner) {
            SpeechConfig::RUNNER_NATIVE => is_file($this->python())
                ? SpeechConfig::RUNNER_NATIVE
                : throw new \RuntimeException('no speech environment at ' . $this->python() . ' - run: ' . $setup),
            SpeechConfig::RUNNER_DOCKER => self::which('docker') !== null
                ? SpeechConfig::RUNNER_DOCKER
                : throw new \RuntimeException('speech runner "docker" needs the docker CLI on PATH'),
            SpeechConfig::RUNNER_AUTO => match (true) {
                is_file($this->python()) => SpeechConfig::RUNNER_NATIVE,
                self::which('docker') !== null => SpeechConfig::RUNNER_DOCKER,
                default => throw new \RuntimeException("no speech runner: run {$setup} (Python) or install Docker"),
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
        array_push($args, ...$this->workerArgs());
        if ($runner === SpeechConfig::RUNNER_NATIVE) {
            return [$this->python(), $this->toolsDir . '/' . static::workerScript(), ...$args];
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
        return 'phpolygon-speech:' . static::name() . '-' . static::version() . ($this->usesCuda() ? '-cuda' : '');
    }

    protected function usesCuda(): bool
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
            $sum += self::onGpu($device) ? static::gpuWorkerRam() : static::cpuWorkerRam();
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
        return $home . '/' . static::environmentName();
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
        $requirements = $this->toolsDir . '/' . static::requirements();
        $uv = self::which('uv');
        if ($uv !== null) {
            $log("Creating {$dir} with uv (Python 3.11)...");
            self::run([$uv, 'venv', '--allow-existing', '--python', '3.11', $dir]);
            $install = [$uv, 'pip', 'install', '--python', $python];
        } else {
            $base = self::which('python3.11') ?? self::which('python3.12') ?? self::which('python3.10')
                ?? throw new \RuntimeException('speech:setup needs uv or Python 3.10-3.12 on PATH (the speech workers pin torch ' . static::TORCH_VERSION . ')');
            $log("Creating {$dir} with {$base}...");
            self::run([$base, '-m', 'venv', $dir]);
            $install = [$python, '-m', 'pip', 'install'];
        }
        if ($this->usesCuda() && PHP_OS_FAMILY === 'Windows') {
            // PyPI ships CPU-only torch for Windows.
            $log('Installing CUDA torch...');
            self::run([...$install, 'torch==' . static::TORCH_VERSION, 'torchaudio==' . static::TORCH_VERSION, '--index-url', self::CUDA_INDEX]);
        }
        $log('Installing ' . static::package() . ' ' . static::version() . ' (a few GB)...');
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

    /**
     * The `docker build` of the image: one Dockerfile for every model, the worker and
     * requirements as build arguments.
     *
     * @return list<string>
     */
    public function buildCommand(): array
    {
        return [
            'docker', 'build',
            '-t', $this->image(),
            '--build-arg', 'TORCH_INDEX=' . ($this->usesCuda() ? self::CUDA_INDEX : self::CPU_INDEX),
            '--build-arg', 'TORCH_VERSION=' . static::TORCH_VERSION,
            '--build-arg', 'WORKER=' . static::workerScript(),
            '--build-arg', 'REQUIREMENTS=' . static::requirements(),
            ...(static::systemPackages() !== '' ? ['--build-arg', 'SYSTEM_PACKAGES=' . static::systemPackages()] : []),
            $this->toolsDir,
        ];
    }

    /** @param callable(string): void $log */
    private function buildImage(callable $log): void
    {
        $log('Building Docker image ' . $this->image() . ' (a few GB)...');
        self::run($this->buildCommand());
    }

    /**
     * A path as the worker sees it: unchanged natively, under /work in Docker.
     * Separators, `.`/`..` segments and symlinks (realpath where the path exists) are
     * normalised first; Windows paths (a drive letter) compare without case.
     */
    public function workerPath(string $path, string $runner): string
    {
        if ($runner !== SpeechConfig::RUNNER_DOCKER) {
            return $path;
        }
        $root = rtrim(self::normalizePath($this->projectRoot), '/') . '/';
        $file = self::normalizePath($path);
        $windows = preg_match('#^[A-Za-z]:/#', $root) === 1;
        $inside = $windows ? strncasecmp($file, $root, strlen($root)) === 0 : str_starts_with($file, $root);
        if (!$inside) {
            throw new \RuntimeException("{$path} is outside the project ({$this->projectRoot}): the Docker runner only sees the project directory");
        }
        return self::CONTAINER_ROOT . '/' . substr($file, strlen($root));
    }

    /**
     * Forward slashes, no `.`/`..`/empty segments; the longest existing prefix
     * resolved by realpath (so a file still to be written resolves through its directory).
     */
    public static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = '';
        if (preg_match('#^([A-Za-z]:)(/|$)#', $path, $m) === 1) {
            $prefix = $m[1];
            $path = substr($path, strlen($m[1]));
        } elseif (str_starts_with($path, '//')) {
            $prefix = '/';  // UNC: //server/share
        }
        $absolute = str_starts_with($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..' && $parts !== [] && end($parts) !== '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }
        $clean = $prefix . ($absolute ? '/' : '') . implode('/', $parts);

        // realpath of the longest existing prefix, the rest appended. Never the bare root:
        // on Windows realpath("/") is the current drive, which would turn a POSIX path into one.
        $rest = [];
        $probe = $parts;
        while ($probe !== []) {
            $candidate = $prefix . ($absolute ? '/' : '') . implode('/', $probe);
            if (file_exists($candidate)) {
                $real = realpath($candidate);
                if ($real !== false) {
                    $real = rtrim(str_replace('\\', '/', $real), '/');
                    return $rest === [] ? $real : $real . '/' . implode('/', array_reverse($rest));
                }
                break;
            }
            $rest[] = array_pop($probe);
        }
        return $clean;
    }

    /** @param list<string> $command */
    protected static function run(array $command, bool $quiet = false): int
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
