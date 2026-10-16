<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPolygon\Build\FileTree;
use PHPolygon\Runtime\BundleExtractor;
use PHPUnit\Framework\TestCase;

class BundleExtractorTest extends TestCase
{
    private string $dir;
    private string $source;
    private string $target;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpolygon-extract-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $this->source = $this->dir . '/archive/assets';
        $this->target = $this->dir . '/install/assets';
        $this->put($this->source . '/meshes/crate.mesh.json', '{"v":1}');
        $this->put($this->source . '/lang/en.json', '{"hi":"Hi"}');
    }

    protected function tearDown(): void
    {
        FileTree::remove($this->dir);
    }

    public function testFirstStartExtractsEverythingAndWritesTheMarker(): void
    {
        $this->assertTrue(BundleExtractor::sync($this->source, $this->target, 'build-a'));

        $this->assertSame('{"v":1}', file_get_contents($this->target . '/meshes/crate.mesh.json'));
        $this->assertSame('{"hi":"Hi"}', file_get_contents($this->target . '/lang/en.json'));
        $this->assertTrue(BundleExtractor::isCurrent($this->target, 'build-a'));
        $this->assertSame([], $this->leftovers(), 'no temporary files remain');
    }

    public function testSameBuildExtractsNothing(): void
    {
        BundleExtractor::sync($this->source, $this->target, 'build-a');
        file_put_contents($this->target . '/lang/en.json', 'touched by the player');

        $this->assertFalse(BundleExtractor::sync($this->source, $this->target, 'build-a'));
        $this->assertSame('touched by the player', file_get_contents($this->target . '/lang/en.json'));
    }

    public function testNewBuildOverwritesTheFiles(): void
    {
        BundleExtractor::sync($this->source, $this->target, 'build-a');
        file_put_contents($this->source . '/lang/en.json', '{"hi":"Hello"}');

        $this->assertTrue(BundleExtractor::sync($this->source, $this->target, 'build-b'));
        $this->assertSame('{"hi":"Hello"}', file_get_contents($this->target . '/lang/en.json'));
        $this->assertTrue(BundleExtractor::isCurrent($this->target, 'build-b'));
    }

    /**
     * An extraction that died half-way leaves no marker (it is removed first and
     * written last), so the next start extracts again.
     */
    public function testInterruptedExtractionIsRepeated(): void
    {
        BundleExtractor::sync($this->source, $this->target, 'build-a');
        // What an abort after the first files of build b leaves behind:
        unlink($this->target . '/' . BundleExtractor::MARKER);
        file_put_contents($this->target . '/lang/en.json', '{"hi":');
        file_put_contents($this->target . '/lang/en.json.part-1234', 'half');

        $this->assertTrue(BundleExtractor::sync($this->source, $this->target, 'build-a'));
        $this->assertSame('{"hi":"Hi"}', file_get_contents($this->target . '/lang/en.json'));
    }

    public function testInstallDirectoryNeverLosesFiles(): void
    {
        // A file the store installed next to the extracted ones (an external resource).
        $this->put($this->target . '/audio/theme.flac', 'loose');
        BundleExtractor::sync($this->source, $this->target, 'build-a');
        unlink($this->source . '/lang/en.json');

        BundleExtractor::sync($this->source, $this->target, 'build-b');

        $this->assertFileExists($this->target . '/audio/theme.flac');
        $this->assertFileExists($this->target . '/lang/en.json', 'stale names may be store files: kept');
    }

    public function testPrivateTargetLinksLooseFilesAndDropsStaleOnes(): void
    {
        $this->requireSymlinks();
        $loose = $this->dir . '/app/Resources/assets';
        $this->put($loose . '/audio/theme.flac', 'loose');
        $this->put($loose . '/meshes/extra.mesh.json', 'loose mesh');
        $cache = $this->dir . '/cache/assets';

        BundleExtractor::sync($this->source, $cache, 'build-a', $loose);

        $this->assertTrue(is_link($cache . '/audio'), 'a folder only the install has is linked whole');
        $this->assertSame('loose', file_get_contents($cache . '/audio/theme.flac'));
        $this->assertFalse(is_link($cache . '/meshes'), 'a folder both have is merged');
        $this->assertSame('loose mesh', file_get_contents($cache . '/meshes/extra.mesh.json'));
        $this->assertSame('{"v":1}', file_get_contents($cache . '/meshes/crate.mesh.json'));

        unlink($this->source . '/lang/en.json');
        BundleExtractor::sync($this->source, $cache, 'build-b', $loose);
        $this->assertFileDoesNotExist($cache . '/lang/en.json');
        $this->assertSame('loose', file_get_contents($loose . '/audio/theme.flac'), 'the install is never touched');
    }

    public function testMissingSourceStillMarksTheTarget(): void
    {
        $this->assertTrue(BundleExtractor::sync($this->dir . '/nothing', $this->target, 'build-a'));
        $this->assertFalse(BundleExtractor::sync($this->dir . '/nothing', $this->target, 'build-a'));
    }

    public function testUserCacheDirFollowsThePlatform(): void
    {
        $home = getenv('HOME');
        $local = getenv('LOCALAPPDATA');
        $xdg = getenv('XDG_CACHE_HOME');
        putenv('HOME=/home/player');
        putenv('LOCALAPPDATA=C:/Users/player/AppData/Local');
        putenv('XDG_CACHE_HOME');
        try {
            $this->assertSame('/home/player/Library/Caches/com.studio.game', BundleExtractor::userCacheDir('com.studio.game', 'Darwin'));
            $this->assertSame('C:/Users/player/AppData/Local/com.studio.game', BundleExtractor::userCacheDir('com.studio.game', 'Windows'));
            $this->assertSame('/home/player/.cache/com.studio.game', BundleExtractor::userCacheDir('com.studio.game', 'Linux'));
            putenv('XDG_CACHE_HOME=/xdg');
            $this->assertSame('/xdg/com.studio.game', BundleExtractor::userCacheDir('com.studio.game', 'Linux'));
        } finally {
            putenv($home === false ? 'HOME' : "HOME={$home}");
            putenv($local === false ? 'LOCALAPPDATA' : "LOCALAPPDATA={$local}");
            putenv($xdg === false ? 'XDG_CACHE_HOME' : "XDG_CACHE_HOME={$xdg}");
        }
    }

    private function requireSymlinks(): void
    {
        $probe = $this->dir . '/probe-link';
        if (!@symlink($this->source, $probe)) {
            $this->markTestSkipped('this system cannot create symlinks (Windows without developer mode)');
        }
        FileTree::remove($probe);
    }

    /** @return list<string> */
    private function leftovers(): array
    {
        $found = [];
        foreach (FileTree::walk($this->target) as $relative => $item) {
            if (str_contains($relative, '.part-')) {
                $found[] = $relative;
            }
        }
        return $found;
    }

    private function put(string $path, string $content): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $content);
    }
}
