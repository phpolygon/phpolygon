<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

/**
 * A speech worker host reached over SSH: `ssh://user@host[:port][?device=mps&python=/path&workers=2]`.
 *
 *   device   the worker's device on that host (auto, cuda, cpu, mps); default auto
 *   python   interpreter of the host's speech environment; default
 *            ~/.phpolygon/speech/chatterbox-{version}/bin/python (`speech:setup --remote=…`)
 *   workers  processes on that host, each one model; default 1
 *
 * The worker script is sent with the command (the host runs the engine's own copy),
 * reference WAVs and generated WAVs travel inline in the protocol, so the host needs
 * no shared paths. SSH must log in without a prompt (key or agent: BatchMode).
 */
final readonly class SpeechRemote
{
    public function __construct(
        public string $host,
        public ?string $user = null,
        public ?int $port = null,
        public string $device = SpeechConfig::DEVICE_AUTO,
        public ?string $python = null,
    ) {}

    public static function isRemote(string $worker): bool
    {
        return str_starts_with($worker, 'ssh://');
    }

    /**
     * @return array{SpeechRemote, int} the host and its worker count
     * @throws \InvalidArgumentException
     */
    public static function parse(string $url, string $source = 'speech worker'): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'ssh' || ($parts['host'] ?? '') === '' || isset($parts['pass'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
            throw new \InvalidArgumentException("{$source}: \"{$url}\" is not ssh://user@host[:port][?device=mps&python=/path&workers=2]");
        }
        parse_str($parts['query'] ?? '', $query);
        foreach (array_keys($query) as $key) {
            if (!in_array($key, ['device', 'python', 'workers'], true)) {
                throw new \InvalidArgumentException("{$source}: unknown option \"{$key}\" in {$url} (device, python, workers)");
            }
        }
        $device = $query['device'] ?? SpeechConfig::DEVICE_AUTO;
        if (!is_string($device) || !in_array($device, SpeechConfig::DEVICES, true)) {
            throw new \InvalidArgumentException("{$source}: device in {$url} must be one of " . implode(', ', SpeechConfig::DEVICES));
        }
        $python = $query['python'] ?? null;
        if ($python !== null && (!is_string($python) || $python === '')) {
            throw new \InvalidArgumentException("{$source}: python in {$url} must be a path");
        }
        $workers = $query['workers'] ?? '1';
        if (!is_string($workers) || !ctype_digit($workers) || (int) $workers < 1) {
            throw new \InvalidArgumentException("{$source}: workers in {$url} must be a positive integer");
        }
        $remote = new self(
            (string) $parts['host'],
            isset($parts['user']) ? rawurldecode($parts['user']) : null,
            $parts['port'] ?? null,
            $device,
            $python,
        );
        return [$remote, (int) $workers];
    }

    /** The URL of one worker on this host (no `workers`). */
    public function url(): string
    {
        $query = array_filter(['device' => $this->device !== SpeechConfig::DEVICE_AUTO ? $this->device : null, 'python' => $this->python]);
        return 'ssh://' . $this->target() . ($this->port !== null ? ":{$this->port}" : '') . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /** user@host as ssh takes it. */
    public function target(): string
    {
        return ($this->user !== null ? $this->user . '@' : '') . $this->host;
    }

    /**
     * The ssh invocation up to the remote command.
     *
     * @param list<string> $ssh the ssh client, e.g. ['ssh']
     * @return list<string>
     */
    public function sshCommand(array $ssh): array
    {
        $command = [...$ssh, '-o', 'BatchMode=yes', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=4'];
        if ($this->port !== null) {
            array_push($command, '-p', (string) $this->port);
        }
        $command[] = $this->target();
        $command[] = '--';
        return $command;
    }

    /** The interpreter as a shell word: $HOME-relative paths stay expandable. */
    public function pythonWord(string $version): string
    {
        $python = $this->python ?? "~/.phpolygon/speech/chatterbox-{$version}/bin/python";
        if (str_starts_with($python, '~/')) {
            return '"$HOME"/' . self::quote(substr($python, 2));
        }
        return self::quote($python);
    }

    /** POSIX shell single quoting. */
    public static function quote(string $word): string
    {
        return "'" . str_replace("'", "'\\''", $word) . "'";
    }
}
