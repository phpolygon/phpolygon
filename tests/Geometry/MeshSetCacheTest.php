<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshSetCache;
use PHPUnit\Framework\TestCase;

final class MeshSetCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpolygon-meshset-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        putenv(MeshSetCache::ENV_SWITCH);
    }

    /** @return array<int|string, MeshData> */
    private static function sample(): array
    {
        return [
            'paving' => new MeshData([0.1, 1 / 3, -2.5e-9, 7.0, 8.0, 9.0], [0.0, 1.0, 0.0, 0.0, 1.0, 0.0], [0.25, 0.75, 1.0, 0.5], [0, 1, 0]),
            'paving.hem' => new MeshData([1.0, 2.0, 3.0], [0.0, 1.0, 0.0], [0.0, 0.0], [], [1.0, 0.0, 0.0, -1.0]),
            7 => new MeshData([M_PI, M_E, 1.0e300], [0.0, 0.0, 1.0], [0.5, 0.5], [2, 1, 0]),
        ];
    }

    public function testAHitReturnsExactlyWhatTheBuildReturned(): void
    {
        $cache = new MeshSetCache($this->dir);
        $built = 0;
        $first = $cache->remember('paths', 'k1', function () use (&$built): array {
            $built++;

            return self::sample();
        });
        $second = $cache->remember('paths', 'k1', function () use (&$built): array {
            $built++;

            return [];
        });
        self::assertSame(1, $built);
        self::assertSame(1, $cache->hits());
        self::assertSame(1, $cache->misses());
        self::assertEquals($first, $second);
        self::assertSame(array_keys($first), array_keys($second));
        foreach ($first as $k => $m) {
            self::assertSame($m->vertices, $second[$k]->vertices, "{$k} vertices bit for bit");
            self::assertSame($m->indices, $second[$k]->indices);
            self::assertSame($m->tangents, $second[$k]->tangents);
        }
    }

    public function testAnotherInstanceReadsTheFileAWarmStart(): void
    {
        (new MeshSetCache($this->dir))->remember('paths', 'k', static fn (): array => self::sample());
        $warm = new MeshSetCache($this->dir);
        $got = $warm->remember('paths', 'k', static function (): array {
            self::fail('a warm start must not build');
        });
        self::assertSame(1, $warm->hits());
        self::assertSame(self::sample()['paving']->vertices, $got['paving']->vertices);
    }

    public function testANewKeyRebuildsAndReplacesTheOldFile(): void
    {
        $cache = new MeshSetCache($this->dir);
        $cache->remember('paths', 'old', static fn (): array => self::sample());
        self::assertCount(1, glob($this->dir . '/paths.*.meshset') ?: []);
        $built = 0;
        $cache->remember('paths', 'new', function () use (&$built): array {
            $built++;

            return self::sample();
        });
        self::assertSame(1, $built, 'a changed key is a miss');
        self::assertCount(1, glob($this->dir . '/paths.*.meshset') ?: [], 'the old key leaves no file behind');
        self::assertSame([], glob($this->dir . '/*.tmp') ?: [], 'no temporary file is left');
    }

    public function testADamagedFileIsRebuiltNotTrusted(): void
    {
        $cache = new MeshSetCache($this->dir);
        $cache->remember('paths', 'k', static fn (): array => self::sample());
        $file = (glob($this->dir . '/paths.*.meshset') ?: [''])[0];
        $bytes = (string) file_get_contents($file);

        foreach ([
            'flipped payload byte' => substr_replace($bytes, chr(ord($bytes[60]) ^ 0xFF), 60, 1),
            'truncated' => substr($bytes, 0, intdiv(strlen($bytes), 2)),
            'header only' => substr($bytes, 0, 36),
            'garbage' => 'not a cache file',
            'empty' => '',
        ] as $what => $damaged) {
            file_put_contents($file, $damaged);
            $built = 0;
            $got = (new MeshSetCache($this->dir))->remember('paths', 'k', function () use (&$built): array {
                $built++;

                return self::sample();
            });
            self::assertSame(1, $built, $what);
            self::assertSame(self::sample()['paving']->vertices, $got['paving']->vertices, $what);
        }
    }

    public function testDisabledAndEnvironmentSwitchNeverTouchTheDisk(): void
    {
        $off = new MeshSetCache($this->dir, enabled: false);
        $off->remember('paths', 'k', static fn (): array => self::sample());
        self::assertFalse(is_dir($this->dir));
        self::assertFalse($off->isEnabled());

        self::assertFalse(MeshSetCache::disabled()->isEnabled());
        self::assertFalse((new MeshSetCache(null))->isEnabled());

        putenv(MeshSetCache::ENV_SWITCH . '=0');
        self::assertFalse(MeshSetCache::fromEnvironment($this->dir)->isEnabled());
        putenv(MeshSetCache::ENV_SWITCH . '=off');
        self::assertFalse(MeshSetCache::fromEnvironment($this->dir)->isEnabled());
        putenv(MeshSetCache::ENV_SWITCH);
        self::assertTrue(MeshSetCache::fromEnvironment($this->dir)->isEnabled());
        putenv(MeshSetCache::ENV_SWITCH . '=1');
        self::assertTrue(MeshSetCache::fromEnvironment($this->dir)->isEnabled());
    }

    public function testCompressedFilesRoundTripToo(): void
    {
        $cache = new MeshSetCache($this->dir, zlibLevel: 6);
        $cache->remember('z', 'k', static fn (): array => self::sample());
        $got = (new MeshSetCache($this->dir, zlibLevel: 6))->remember('z', 'k', static function (): array {
            self::fail('built');
        });
        self::assertSame(self::sample()[7]->vertices, $got[7]->vertices);
    }

    public function testAMeshLargerThanOneChunkSurvives(): void
    {
        $v = [];
        $i = [];
        for ($n = 0; $n < 30000; $n++) {
            $v[] = $n * 0.001;
            $i[] = $n;
        }
        $big = new MeshData($v, $v, array_slice($v, 0, 20000), $i);
        $cache = new MeshSetCache($this->dir);
        $cache->rememberMesh('big', 'k', static fn (): MeshData => $big);
        $got = (new MeshSetCache($this->dir))->rememberMesh('big', 'k', static function (): MeshData {
            self::fail('built');
        });
        self::assertSame($big->vertices, $got->vertices);
        self::assertSame($big->uvs, $got->uvs);
        self::assertSame($big->indices, $got->indices);
    }

    public function testClearRemovesTheFiles(): void
    {
        $cache = new MeshSetCache($this->dir);
        $cache->remember('a', 'k', static fn (): array => self::sample());
        $cache->remember('b', 'k', static fn (): array => self::sample());
        self::assertSame(2, $cache->clear());
        self::assertSame([], glob($this->dir . '/*.meshset') ?: []);
    }

    public function testFingerprintsSeparateTheirParts(): void
    {
        self::assertNotSame(MeshSetCache::fingerprint('ab', 'c'), MeshSetCache::fingerprint('a', 'bc'));
        self::assertSame(MeshSetCache::fingerprint('a', 'b'), MeshSetCache::fingerprint('a', 'b'));

        $file = $this->dir . '.txt';
        file_put_contents($file, 'one');
        $a = MeshSetCache::fingerprintFiles($file);
        file_put_contents($file, 'two');
        $b = MeshSetCache::fingerprintFiles($file);
        unlink($file);
        $gone = MeshSetCache::fingerprintFiles($file);
        self::assertCount(3, array_unique([$a, $b, $gone]));
    }

    public function testTheEngineStampCoversTheGeometrySources(): void
    {
        $files = MeshSetCache::engineStampFiles();
        self::assertContains('ConformingSurfaceSet.php', $files);
        self::assertContains('MeshSetCache.php', $files);
        self::assertNotContains('Generated', $files);
        self::assertSame($files, array_values(array_unique($files)));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', MeshSetCache::engineStamp());
    }

    public function testTheEngineStampListsAPharDirectory(): void
    {
        if (!in_array('phar', stream_get_wrappers(), true)) {
            self::markTestSkipped('phar wrapper unavailable');
        }
        $phar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpolygon-stamp-' . getmypid() . '.phar';
        try {
            $p = new \Phar($phar);
            $p->addFromString('src/A.php', '<?php');
            $p->addFromString('src/B.txt', 'x');
            // glob() finds nothing under phar://, scandir() lists the entries: the reason the stamp uses scandir().
            self::assertSame([], glob('phar://' . $phar . '/src/*.php') ?: []);
            self::assertContains('A.php', scandir('phar://' . $phar . '/src') ?: []);
        } catch (\UnexpectedValueException) {
            self::markTestSkipped('phar.readonly prevents building a test archive');
        } finally {
            @unlink($phar);
        }
    }

    public function testABuilderThatReturnsNoMeshIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MeshSetCache($this->dir))->remember('bad', 'k', static fn (): array => ['x' => 'not a mesh']);
    }
}
