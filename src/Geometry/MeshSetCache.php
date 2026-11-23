<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Disk cache for procedural geometry that is dear to build and does not change
 * between runs – a ground-following path network, a baked set of meshes. The
 * second start reads the meshes back instead of building them.
 *
 *     $cache = MeshSetCache::fromEnvironment($cacheDir);
 *     $key = MeshSetCache::fingerprint('paths-v3', (string) $stage) . MeshSetCache::fingerprintFiles($terrainFile, __FILE__);
 *     $meshes = $cache->remember('w2_paths_s' . $stage, $key, fn () => $set->build());
 *
 * The CALLER supplies the content key: it must change whenever the result can
 * (the terrain the set follows, the parameters, the code that builds it –
 * {@see fingerprint()} and {@see fingerprintFiles()} fold strings and file
 * contents into one). The cache adds what it knows itself: a format number and a
 * stamp of the engine's own geometry sources ({@see engineStamp()}), so an engine
 * update never serves meshes the new code would build differently.
 *
 * Safe by construction:
 * - One file per name; the file carries a digest of the full key, so a stale or
 *   foreign file is never returned, and a new key replaces the old file (the
 *   directory does not grow with every change).
 * - A crc32 over the payload and every length is checked on read; a short,
 *   damaged or unreadable file counts as a miss and is rebuilt. Nothing here
 *   throws at the caller for disk trouble – the build result is always returned.
 * - Written to a temporary file and renamed, so a crash or a parallel run never
 *   leaves a half file under the real name.
 * - Floats are stored as doubles: a hit is bit for bit what the build returned.
 *
 * Switched off with `enabled: false`, without a directory, or – through
 * {@see fromEnvironment()} – with the environment variable PHPOLYGON_MESH_CACHE=0
 * (off, false, no): then {@see remember()} just calls the builder.
 */
final class MeshSetCache
{
    /** Bump when the file layout changes. */
    public const int FORMAT = 1;

    /** Environment variable that switches {@see fromEnvironment()} caches off. */
    public const string ENV_SWITCH = 'PHPOLYGON_MESH_CACHE';

    private const string MAGIC = 'PHMS';
    private const int HEADER = 36;
    private const int FLAG_ZLIB = 1;
    private const int CHUNK = 8192;

    private static ?string $engineStamp = null;

    private int $hits = 0;
    private int $misses = 0;

    /**
     * @param string|null $dir cache directory (created on the first write); null = no cache
     * @param bool $enabled false = never read or write
     * @param int $zlibLevel 0 = raw (fastest to read), 1-9 = compressed (smaller file)
     */
    public function __construct(
        private readonly ?string $dir,
        private readonly bool $enabled = true,
        private readonly int $zlibLevel = 0,
    ) {
        if ($zlibLevel < 0 || $zlibLevel > 9) {
            throw new \InvalidArgumentException('MeshSetCache: zlib level must be 0..9');
        }
    }

    /** A cache that is off unless the environment variable PHPOLYGON_MESH_CACHE says otherwise (0/off/false/no = off). */
    public static function fromEnvironment(?string $dir, int $zlibLevel = 0): self
    {
        $v = getenv(self::ENV_SWITCH);
        $off = $v !== false && in_array(strtolower(trim($v)), ['0', 'off', 'false', 'no'], true);

        return new self($dir, !$off, $zlibLevel);
    }

    /** A cache that never stores anything. */
    public static function disabled(): self
    {
        return new self(null, false);
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->dir !== null && $this->dir !== '';
    }

    /** Reads served from disk so far. */
    public function hits(): int
    {
        return $this->hits;
    }

    /** Builds run so far (disabled, absent, stale or damaged file). */
    public function misses(): int
    {
        return $this->misses;
    }

    /**
     * The cached meshes of $name for $key, or $build()'s result (stored for the next run).
     *
     * @param string $name file stem: letters, digits, `_ . -` (anything else becomes `_`)
     * @param string $key content key of the caller, see the class comment
     * @param callable(): array<int|string, MeshData> $build
     * @return array<int|string, MeshData>
     */
    public function remember(string $name, string $key, callable $build): array
    {
        if (!$this->isEnabled()) {
            $this->misses++;

            return self::checked($build());
        }
        $digest = self::digest($key);
        $path = $this->pathOf($name, $digest);
        $hit = $this->read($path, $digest);
        if ($hit !== null) {
            $this->hits++;

            return $hit;
        }
        $this->misses++;
        $meshes = self::checked($build());
        $this->write($name, $path, $digest, $meshes);

        return $meshes;
    }

    /**
     * One mesh through {@see remember()}.
     *
     * @param callable(): MeshData $build
     */
    public function rememberMesh(string $name, string $key, callable $build): MeshData
    {
        $meshes = $this->remember($name, $key, static fn (): array => [0 => $build()]);

        return $meshes[0];
    }

    /** Delete every cache file of this directory; returns how many. */
    public function clear(): int
    {
        if ($this->dir === null || !is_dir($this->dir)) {
            return 0;
        }
        $n = 0;
        foreach (glob(rtrim($this->dir, '/\\') . '/*.meshset') ?: [] as $file) {
            if (@unlink($file)) {
                $n++;
            }
        }

        return $n;
    }

    /** One content key from several strings (order matters). */
    public static function fingerprint(string ...$parts): string
    {
        $h = hash_init('xxh128');
        foreach ($parts as $p) {
            hash_update($h, pack('V', strlen($p)));
            hash_update($h, $p);
        }

        return hash_final($h);
    }

    /**
     * One content key from the CONTENTS of files (a missing file counts as
     * missing, not as an error: its absence is part of the key).
     */
    public static function fingerprintFiles(string ...$paths): string
    {
        $parts = [];
        foreach ($paths as $path) {
            $parts[] = $path . ':' . (is_file($path) ? (string) hash_file('xxh128', $path) : 'missing');
        }

        return self::fingerprint(...$parts);
    }

    /**
     * Stamp of the engine's own geometry sources (contents of src/Geometry/*.php):
     * part of every key, so an engine update invalidates what it may build
     * differently.
     */
    public static function engineStamp(): string
    {
        if (self::$engineStamp === null) {
            $names = self::engineStampFiles();
            $files = array_map(static fn (string $n): string => __DIR__ . '/' . $n, $names);
            self::$engineStamp = self::fingerprint(implode(',', $names), self::fingerprintFiles(...$files), self::packageReference());
        }

        return self::$engineStamp;
    }

    /** The installed engine's version reference (a git hash or tag), '' when Composer cannot tell. */
    private static function packageReference(): string
    {
        try {
            if (class_exists(\Composer\InstalledVersions::class)) {
                return (string) \Composer\InstalledVersions::getReference('phpolygon/phpolygon');
            }
        } catch (\Throwable) {
            // not installed through Composer (a PHAR, a checkout): the file contents above still count
        }

        return '';
    }

    /**
     * File names the engine stamp covers: the sources of this directory. Listed with
     * scandir(), not glob(): inside a PHAR they sit under phar://, which glob() does not list.
     *
     * @return list<string>
     */
    public static function engineStampFiles(): array
    {
        $names = [];
        foreach (scandir(__DIR__) ?: [] as $entry) {
            if (str_ends_with($entry, '.php')) {
                $names[] = $entry;
            }
        }
        sort($names);

        return $names;
    }

    private static function digest(string $key): string
    {
        return hash('xxh128', self::FORMAT . '|' . self::engineStamp() . '|' . PHP_INT_SIZE . '|' . $key, true);
    }

    /**
     * @param array<int|string, mixed> $meshes
     * @return array<int|string, MeshData>
     */
    private static function checked(array $meshes): array
    {
        foreach ($meshes as $name => $mesh) {
            if (!$mesh instanceof MeshData) {
                throw new \InvalidArgumentException("MeshSetCache: entry '{$name}' is not a MeshData");
            }
        }

        /** @var array<int|string, MeshData> $meshes */
        return $meshes;
    }

    private function pathOf(string $name, string $digest): string
    {
        $stem = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $name);

        return rtrim((string) $this->dir, '/\\') . '/' . $stem . '.' . substr(bin2hex($digest), 0, 16) . '.meshset';
    }

    /** @return array<int|string, MeshData>|null */
    private function read(string $path, string $digest): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        try {
            $data = @file_get_contents($path);
            if ($data === false || strlen($data) < self::HEADER || !str_starts_with($data, self::MAGIC)) {
                return null;
            }
            // (not unpack 'a16': it strips trailing NUL bytes, which a binary digest may end in)
            $h = self::ints('vformat/vflags', $data, 4);
            $t = self::ints('Vcount/Vlength/Vcrc', $data, 24);
            if ($h === null || $t === null || $h['format'] !== self::FORMAT || substr($data, 8, 16) !== $digest) {
                return null;
            }
            if (strlen($data) !== self::HEADER + $t['length']) {
                return null;
            }
            $payload = substr($data, self::HEADER);
            if (crc32($payload) !== $t['crc']) {
                return null;
            }
            if (($h['flags'] & self::FLAG_ZLIB) !== 0) {
                $payload = @gzuncompress($payload);
                if ($payload === false) {
                    return null;
                }
            }

            return self::decode($payload, $t['count']);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<int|string, MeshData> $meshes */
    private function write(string $name, string $path, string $digest, array $meshes): void
    {
        try {
            $payload = '';
            foreach ($meshes as $key => $mesh) {
                $payload .= self::encodeEntry($key, $mesh);
            }
            $flags = 0;
            if ($this->zlibLevel > 0) {
                $z = gzcompress($payload, $this->zlibLevel);
                if ($z !== false) {
                    $payload = $z;
                    $flags |= self::FLAG_ZLIB;
                }
            }
            $file = pack('a4vva16VVV', self::MAGIC, self::FORMAT, $flags, $digest, count($meshes), strlen($payload), crc32($payload)) . $payload;

            $dir = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
                return;
            }
            $tmp = $path . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, $file) === false) {
                @unlink($tmp);

                return;
            }
            if (!@rename($tmp, $path)) {
                @unlink($tmp);

                return;
            }
            // Retire the files of older keys of the same name.
            $stem = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $name);
            foreach (glob($dir . '/' . $stem . '.*.meshset') ?: [] as $old) {
                if ($old !== $path && preg_match('/\.[0-9a-f]{16}\.meshset$/', $old) === 1) {
                    @unlink($old);
                }
            }
        } catch (\Throwable) {
            // A cache that cannot be written is only a slower start.
        }
    }

    private static function encodeEntry(int|string $key, MeshData $mesh): string
    {
        $tangents = $mesh->tangents;
        $out = is_int($key) ? pack('Cq', 0, $key) : pack('CV', 1, strlen($key)) . $key;
        $out .= pack('CVVVVV', $tangents === null ? 0 : 1, count($mesh->vertices), count($mesh->normals), count($mesh->uvs), count($mesh->indices), $tangents === null ? 0 : count($tangents));
        $out .= self::packFloats($mesh->vertices) . self::packFloats($mesh->normals) . self::packFloats($mesh->uvs);
        if ($tangents !== null) {
            $out .= self::packFloats($tangents);
        }
        for ($i = 0, $n = count($mesh->indices); $i < $n; $i += self::CHUNK) {
            $out .= pack('V*', ...array_slice($mesh->indices, $i, self::CHUNK));
        }

        return $out;
    }

    /** @return array<int|string, MeshData>|null */
    private static function decode(string $p, int $count): ?array
    {
        $o = 0;
        $len = strlen($p);
        $out = [];
        for ($e = 0; $e < $count; $e++) {
            if ($o + 9 > $len) {
                return null;
            }
            $kind = ord($p[$o]);
            if ($kind === 0) {
                $q = self::ints('qkey', $p, $o + 1);
                if ($q === null) {
                    return null;
                }
                $key = $q['key'];
                $o += 9;
            } else {
                $n = self::ints('Vlen', $p, $o + 1);
                if ($n === null || $o + 5 + $n['len'] > $len) {
                    return null;
                }
                $key = substr($p, $o + 5, $n['len']);
                $o += 5 + $n['len'];
            }
            if ($o + 21 > $len) {
                return null;
            }
            $c = self::ints('Ctan/Vv/Vn/Vuv/Vidx/Vtn', $p, $o);
            if ($c === null) {
                return null;
            }
            $o += 21;
            $need = 8 * ($c['v'] + $c['n'] + $c['uv'] + $c['tn']) + 4 * $c['idx'];
            if ($o + $need > $len) {
                return null;
            }
            $vertices = self::unpackFloats($p, $o, $c['v']);
            $normals = self::unpackFloats($p, $o, $c['n']);
            $uvs = self::unpackFloats($p, $o, $c['uv']);
            $tangents = $c['tan'] === 1 ? self::unpackFloats($p, $o, $c['tn']) : null;
            $indices = self::unpackInts($p, $o, $c['idx']);
            $out[$key] = new MeshData($vertices, $normals, $uvs, $indices, $tangents);
        }

        return $o === $len ? $out : null;
    }

    /**
     * unpack() of integer fields only: every value checked, so the caller does arithmetic on ints.
     *
     * @return array<string, int>|null null when the bytes are too short or a field is not an integer
     */
    private static function ints(string $format, string $data, int $offset): ?array
    {
        $u = @unpack($format, $data, $offset);
        if ($u === false) {
            return null;
        }
        $out = [];
        foreach ($u as $name => $v) {
            if (!is_int($v)) {
                return null;
            }
            $out[(string) $name] = $v;
        }

        return $out;
    }

    /** @param array<float|int> $values */
    private static function packFloats(array $values): string
    {
        $out = '';
        for ($i = 0, $n = count($values); $i < $n; $i += self::CHUNK) {
            $out .= pack('e*', ...array_slice($values, $i, self::CHUNK));
        }

        return $out;
    }

    /**
     * $count doubles at $o (the caller checked the bytes are there); advances $o.
     *
     * @return float[]
     */
    private static function unpackFloats(string $p, int &$o, int $count): array
    {
        if ($count === 0) {
            return [];
        }
        $unpacked = unpack('e' . $count, $p, $o);
        if ($unpacked === false) {
            throw new \RuntimeException('truncated mesh cache');
        }
        $o += 8 * $count;

        /** @var float[] */
        return array_values($unpacked);
    }

    /**
     * $count uint32 at $o (the caller checked the bytes are there); advances $o.
     *
     * @return int[]
     */
    private static function unpackInts(string $p, int &$o, int $count): array
    {
        if ($count === 0) {
            return [];
        }
        $unpacked = unpack('V' . $count, $p, $o);
        if ($unpacked === false) {
            throw new \RuntimeException('truncated mesh cache');
        }
        $o += 4 * $count;

        /** @var int[] */
        return array_values($unpacked);
    }
}
