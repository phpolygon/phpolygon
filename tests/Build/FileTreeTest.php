<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\FileTree;
use PHPUnit\Framework\TestCase;

final class FileTreeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpolygon-tree-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        FileTree::remove($this->dir);
    }

    public function testWalkListsEveryEntryWithRelativeKeysInEitherOrder(): void
    {
        $src = $this->makeTree('src', 800);

        $parentsFirst = array_keys(iterator_to_array(FileTree::walk($src)));
        $childrenFirst = array_keys(iterator_to_array(FileTree::walk($src, childFirst: true)));

        // assets, assets/meshes, assets/empty, .hidden and 800 meshes
        self::assertCount(804, $parentsFirst);
        self::assertEqualsCanonicalizing($parentsFirst, $childrenFirst);
        self::assertContains('assets/meshes/part_799.mesh.json', $parentsFirst);
        self::assertLessThan(array_search('assets/meshes/part_000.mesh.json', $parentsFirst, true), array_search('assets/meshes', $parentsFirst, true));
        self::assertGreaterThan(array_search('assets/meshes/part_000.mesh.json', $childrenFirst, true), array_search('assets/meshes', $childrenFirst, true));
    }

    public function testCopyRecreatesTheTreeInAMissingDestination(): void
    {
        $src = $this->makeTree('src', 20);
        $dst = $this->dir . '/out/staging';

        FileTree::copy($src, $dst);

        self::assertSame(
            array_keys(iterator_to_array(FileTree::walk($src))),
            array_keys(iterator_to_array(FileTree::walk($dst))),
        );
        self::assertSame('{"part":7}', file_get_contents($dst . '/assets/meshes/part_007.mesh.json'));
        self::assertDirectoryExists($dst . '/assets/empty');
    }

    public function testRemoveDeletesTheTreeOnly(): void
    {
        $src = $this->makeTree('build', 500);

        FileTree::remove($src);

        self::assertDirectoryDoesNotExist($src);
        self::assertDirectoryExists($this->dir);
    }

    public function testRemoveUnlinksAFileAndLeavesAMissingPathAlone(): void
    {
        file_put_contents($this->dir . '/file.bin', 'x');

        FileTree::remove($this->dir . '/file.bin');
        FileTree::remove($this->dir . '/missing');

        self::assertFileDoesNotExist($this->dir . '/file.bin');
    }

    public function testALinkedDirectoryIsListedButNeitherEnteredNorEmptied(): void
    {
        mkdir($this->dir . '/target');
        file_put_contents($this->dir . '/target/keep.txt', 'keep');
        mkdir($this->dir . '/tree');
        $this->linkDirectory($this->dir . '/target', $this->dir . '/tree/link');

        self::assertSame(['link'], array_keys(iterator_to_array(FileTree::walk($this->dir . '/tree'))));

        FileTree::remove($this->dir . '/tree');

        self::assertDirectoryDoesNotExist($this->dir . '/tree');
        self::assertFileExists($this->dir . '/target/keep.txt');
    }

    public function testALinkedDirectoryIsToldApartFromARealOne(): void
    {
        mkdir($this->dir . '/target');
        file_put_contents($this->dir . '/file.txt', 'x');
        $this->linkDirectory($this->dir . '/target', $this->dir . '/link');

        self::assertTrue(FileTree::isLinkedDirectory($this->dir . '/link'));
        self::assertFalse(FileTree::isLinkedDirectory($this->dir . '/target'));
        self::assertFalse(FileTree::isLinkedDirectory($this->dir . '/file.txt'));
        self::assertFalse(FileTree::isLinkedDirectory($this->dir . '/missing'));
    }

    public function testRemovingALinkedDirectoryLeavesItsTarget(): void
    {
        mkdir($this->dir . '/target');
        file_put_contents($this->dir . '/target/keep.txt', 'keep');
        $this->linkDirectory($this->dir . '/target', $this->dir . '/link');

        FileTree::remove($this->dir . '/link');

        self::assertFalse(file_exists($this->dir . '/link') || is_link($this->dir . '/link'));
        self::assertFileExists($this->dir . '/target/keep.txt');
    }

    /** A tree with a hidden file, an empty directory and $meshes files in one directory. */
    private function makeTree(string $name, int $meshes): string
    {
        $root = $this->dir . '/' . $name;
        mkdir($root . '/assets/meshes', 0755, true);
        mkdir($root . '/assets/empty');
        file_put_contents($root . '/.hidden', 'x');
        for ($i = 0; $i < $meshes; $i++) {
            file_put_contents(sprintf('%s/assets/meshes/part_%03d.mesh.json', $root, $i), sprintf('{"part":%d}', $i));
        }

        return $root;
    }

    /** A symlink, or a junction on Windows, where symlinks need extra rights. */
    private function linkDirectory(string $target, string $link): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            exec(sprintf('mklink /J %s %s', escapeshellarg(str_replace('/', '\\', $link)), escapeshellarg(str_replace('/', '\\', $target))), $output, $code);
            $linked = $code === 0;
        } else {
            $linked = @symlink($target, $link);
        }
        if (!$linked) {
            self::markTestSkipped('cannot link a directory here');
        }
    }
}
