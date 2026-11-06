<?php

declare(strict_types=1);

namespace PHPolygon\Build\Icon;

/**
 * Puts an icon into a Windows executable (PE32 / PE32+) as RT_ICON +
 * RT_GROUP_ICON resources, in plain PHP, so a Windows build gets its icon on any
 * build host (macOS, Linux, a container) without rcedit or the Windows SDK.
 *
 * The whole resource tree is read, the existing icons are dropped, the new ones
 * added, and the tree is written again - every other resource (version info,
 * manifest) is kept byte for byte. The new tree replaces the resource section
 * when that section is the last one of the file; otherwise it goes into a new
 * last section and the old one stays unreferenced (renamed `.rsrcold`).
 *
 * Made for the bare micro.sfx runtime, before the PHAR is appended: micro finds
 * its payload at the end of the last section (or at the size an RCDATA 12345
 * resource names, which is rewritten to the new size), so the payload offset
 * moves with the grown file and the PHAR is found as before. A file that already
 * carries data after its sections (a combined game) is refused; an Authenticode
 * signature is removed, since the change breaks it anyway - sign afterwards.
 */
final class PeIconWriter
{
    public const RT_ICON = 3;
    public const RT_RCDATA = 10;
    public const RT_GROUP_ICON = 14;
    public const RT_VERSION = 16;

    /** RCDATA id micro reads its own size from (PHP_MICRO_SFXSIZE_ID). */
    public const MICRO_SFXSIZE_ID = 12345;

    /** Language of the icon resources when the file has no version resource to copy it from (en-US). */
    private const DEFAULT_LANGUAGE = 1033;

    private const DIR_RESOURCE = 2;

    /**
     * $pe with $entries as its (only) icon: RT_ICON 1..n and RT_GROUP_ICON 1.
     *
     * @param list<IcoEntry> $entries
     */
    public static function withIcon(string $pe, array $entries): string
    {
        if ($entries === []) {
            throw new \InvalidArgumentException('An icon needs at least one image');
        }
        $image = PeImage::parse($pe);
        $pe = $image->withoutSignature($pe);
        $image = PeImage::parse($pe);
        if (strlen($pe) > $image->sectionsEnd()) {
            throw new \RuntimeException(sprintf(
                'The executable carries %d bytes after its last section (already combined with a payload?); patch the bare runtime',
                strlen($pe) - $image->sectionsEnd(),
            ));
        }

        $tree = self::readTree($pe, $image);
        $language = self::languageKey($tree);
        unset($tree['i:' . self::RT_ICON], $tree['i:' . self::RT_GROUP_ICON]);
        $group = pack('vvv', 0, 1, count($entries));
        foreach ($entries as $i => $entry) {
            $id = $i + 1;
            $tree['i:' . self::RT_ICON]['i:' . $id][$language] = ['data' => $entry->data, 'codepage' => 0];
            $group .= $entry->directoryHead() . pack('Vv', strlen($entry->data), $id);
        }
        $tree['i:' . self::RT_GROUP_ICON]['i:1'][$language] = ['data' => $group, 'codepage' => 0];

        // Where the new tree goes: in place of the resource section when nothing
        // follows it, else after the last section.
        $resourceSection = $image->sectionAt($image->directory(self::DIR_RESOURCE)[0]);
        $reuse = $resourceSection !== null && $resourceSection === $image->lastSection() ? $resourceSection : null;
        if ($reuse !== null) {
            $rva = $image->sections[$reuse]['va'];
            $rawPointer = $image->sections[$reuse]['raw'];
        } else {
            $rva = self::alignUp($image->imageEnd(), $image->sectionAlignment);
            $rawPointer = self::alignUp($image->sectionsEnd(), $image->fileAlignment);
            if (!$image->hasRoomForSection()) {
                throw new \RuntimeException('The executable has no room for another section header');
            }
        }

        // micro's own size record must name the grown runtime. Its length does
        // not change, so the tree's size is known before its value is.
        $sfxSize = $tree['i:' . self::RT_RCDATA]['i:' . self::MICRO_SFXSIZE_ID] ?? null;
        if ($sfxSize !== null) {
            $treeSize = strlen(self::writeTree($tree, $rva));
            $newFileSize = $rawPointer + self::alignUp($treeSize, $image->fileAlignment);
            foreach ($sfxSize as $lang => $leaf) {
                $tree['i:' . self::RT_RCDATA]['i:' . self::MICRO_SFXSIZE_ID][$lang] = [
                    'data' => self::sfxSizeRecord($leaf['data'], $newFileSize),
                    'codepage' => $leaf['codepage'],
                ];
            }
        }

        $section = self::writeTree($tree, $rva);
        $rawSize = self::alignUp(strlen($section), $image->fileAlignment);
        $out = substr($pe, 0, $rawPointer);
        $out = str_pad($out, $rawPointer, "\0") . str_pad($section, $rawSize, "\0");

        if ($reuse !== null) {
            $out = $image->withSectionSize($out, $reuse, strlen($section), $rawSize);
        } else {
            if ($resourceSection !== null) {
                $out = $image->withSectionName($out, $resourceSection, '.rsrcold');
            }
            $out = $image->withNewSection($out, '.rsrc', $rva, strlen($section), $rawPointer, $rawSize, 0x40000040);
        }
        $out = PeImage::parse($out)->withDirectory($out, self::DIR_RESOURCE, $rva, strlen($section));
        $out = PeImage::parse($out)->withUpdatedSizes($out);
        return PeImage::parse($out)->withChecksum($out);
    }

    /**
     * The icon of $pe: the images of its first RT_GROUP_ICON, in group order.
     *
     * @return list<IcoEntry>
     */
    public static function readIcon(string $pe): array
    {
        $image = PeImage::parse($pe);
        $tree = self::readTree($pe, $image);
        $groups = $tree['i:' . self::RT_GROUP_ICON] ?? [];
        if ($groups === []) {
            return [];
        }
        $languages = array_values($groups)[0];
        $group = array_values($languages)[0]['data'];
        /** @var array{reserved: int, type: int, count: int} $head */
        $head = unpack('vreserved/vtype/vcount', $group);
        $entries = [];
        for ($i = 0; $i < $head['count']; $i++) {
            /** @var array{w: int, h: int, colors: int, reserved: int, planes: int, bits: int, size: int, id: int} $e */
            $e = unpack('Cw/Ch/Ccolors/Creserved/vplanes/vbits/Vsize/vid', $group, 6 + 14 * $i);
            $icon = $tree['i:' . self::RT_ICON]['i:' . $e['id']] ?? [];
            $data = $icon === [] ? '' : array_values($icon)[0]['data'];
            $entries[] = new IcoEntry($e['w'] ?: 256, $e['h'] ?: 256, $e['colors'], $e['planes'], $e['bits'], $data);
        }
        return $entries;
    }

    /**
     * The resource tree: type => name => language => leaf. Keys are `i:<id>` for
     * numeric ids and `n:<UTF-16LE bytes>` for names.
     *
     * @return array<string, array<string, array<string, array{data: string, codepage: int}>>>
     */
    public static function readTree(string $pe, ?PeImage $image = null): array
    {
        $image ??= PeImage::parse($pe);
        [$rva, $size] = $image->directory(self::DIR_RESOURCE);
        if ($rva === 0 || $size === 0) {
            return [];
        }
        $base = $image->rvaToOffset($rva);
        if ($base === null) {
            throw new \RuntimeException('The resource directory lies outside every section');
        }
        $tree = [];
        foreach (self::readDirectory($pe, $base, 0) as $type => $typeOffset) {
            foreach (self::readDirectory($pe, $base, $typeOffset) as $name => $nameOffset) {
                foreach (self::readDirectory($pe, $base, $nameOffset) as $lang => $leafOffset) {
                    /** @var array{rva: int, size: int, codepage: int} $leaf */
                    $leaf = unpack('Vrva/Vsize/Vcodepage', $pe, $base + $leafOffset);
                    $offset = $image->rvaToOffset($leaf['rva']);
                    if ($offset === null) {
                        throw new \RuntimeException("Resource data at RVA {$leaf['rva']} lies outside every section");
                    }
                    $tree[$type][$name][$lang] = ['data' => substr($pe, $offset, $leaf['size']), 'codepage' => $leaf['codepage']];
                }
            }
        }
        return $tree;
    }

    /**
     * Entries of one resource directory: key => offset of the subdirectory or
     * data entry (relative to the resource base).
     *
     * @return array<string, int>
     */
    private static function readDirectory(string $pe, int $base, int $offset): array
    {
        /** @var array{named: int, ids: int} $head */
        $head = unpack('vnamed/vids', $pe, $base + $offset + 12);
        $entries = [];
        for ($i = 0; $i < $head['named'] + $head['ids']; $i++) {
            /** @var array{name: int, target: int} $e */
            $e = unpack('Vname/Vtarget', $pe, $base + $offset + 16 + 8 * $i);
            if (($e['name'] & 0x80000000) !== 0) {
                $at = $base + ($e['name'] & 0x7fffffff);
                /** @var array{1: int} $len */
                $len = unpack('v', $pe, $at);
                $key = 'n:' . substr($pe, $at + 2, 2 * $len[1]);
            } else {
                $key = 'i:' . $e['name'];
            }
            $entries[$key] = $e['target'] & 0x7fffffff;
        }
        return $entries;
    }

    /**
     * Serialise a tree as a resource section starting at $rva: all directory
     * tables (breadth first: root, one per type, one per type and name), the name
     * strings, the data entries, the data.
     *
     * @param array<string, array<string, array<string, array{data: string, codepage: int}>>> $tree
     */
    public static function writeTree(array $tree, int $rva): string
    {
        $tree = self::sortedTree($tree);

        /** @var list<list<string>> $tables the keys of every directory table, in file order */
        $tables = [array_keys($tree)];
        foreach ($tree as $names) {
            $tables[] = array_keys($names);
        }
        $leaves = [];
        foreach ($tree as $names) {
            foreach ($names as $languages) {
                $tables[] = array_keys($languages);
                foreach ($languages as $leaf) {
                    $leaves[] = $leaf;
                }
            }
        }

        // Pass 1: offsets.
        $tableOffsets = [];
        $offset = 0;
        foreach ($tables as $i => $keys) {
            $tableOffsets[$i] = $offset;
            $offset += 16 + 8 * count($keys);
        }
        $strings = [];
        foreach ($tables as $keys) {
            foreach ($keys as $key) {
                if (str_starts_with($key, 'n:') && !isset($strings[$key])) {
                    $strings[$key] = $offset;
                    $offset = self::alignUp($offset + 2 + strlen($key) - 2, 2); // u16 length + UTF-16LE
                }
            }
        }
        $entryOffset = self::alignUp($offset, 8);
        $offset = $entryOffset + 16 * count($leaves);
        $dataOffsets = [];
        foreach ($leaves as $i => $leaf) {
            $offset = self::alignUp($offset, 8);
            $dataOffsets[$i] = $offset;
            $offset += strlen($leaf['data']);
        }

        // Pass 2: bytes. The root points at the type tables, those at the name
        // tables (in the order they were listed), those at the data entries.
        $typeCount = count($tree);
        $out = '';
        $nextTable = 1;
        $leafIndex = 0;
        foreach ($tables as $i => $keys) {
            $named = count(array_filter($keys, static fn (string $k): bool => str_starts_with($k, 'n:')));
            $out .= pack('VVvvvv', 0, 0, 0, 0, $named, count($keys) - $named);
            foreach ($keys as $key) {
                $name = str_starts_with($key, 'n:') ? (0x80000000 | $strings[$key]) : (int) substr($key, 2);
                $target = $i > $typeCount
                    ? $entryOffset + 16 * $leafIndex++
                    : 0x80000000 | $tableOffsets[$nextTable++];
                $out .= pack('VV', $name, $target);
            }
        }
        foreach ($strings as $key => $at) {
            $utf16 = substr($key, 2);
            $out = str_pad($out, $at, "\0") . pack('v', intdiv(strlen($utf16), 2)) . $utf16;
        }
        $out = str_pad($out, $entryOffset, "\0");
        foreach ($leaves as $i => $leaf) {
            $out .= pack('VVVV', $rva + $dataOffsets[$i], strlen($leaf['data']), $leaf['codepage'], 0);
        }
        foreach ($leaves as $i => $leaf) {
            $out = str_pad($out, $dataOffsets[$i], "\0") . $leaf['data'];
        }
        return $out;
    }

    /**
     * @param array<string, array<string, array<string, array{data: string, codepage: int}>>> $tree
     * @return array<string, array<string, array<string, array{data: string, codepage: int}>>>
     */
    private static function sortedTree(array $tree): array
    {
        $out = [];
        foreach (self::sortedKeys(array_keys($tree)) as $type) {
            foreach (self::sortedKeys(array_keys($tree[$type])) as $name) {
                foreach (self::sortedKeys(array_keys($tree[$type][$name])) as $lang) {
                    $out[$type][$name][$lang] = $tree[$type][$name][$lang];
                }
            }
        }
        return $out;
    }

    /**
     * Named entries first (in their order: the producer sorted them), then ids
     * ascending - the order the loader's binary search expects.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    private static function sortedKeys(array $keys): array
    {
        $named = array_values(array_filter($keys, static fn (string $k): bool => str_starts_with($k, 'n:')));
        $ids = array_values(array_filter($keys, static fn (string $k): bool => !str_starts_with($k, 'n:')));
        usort($ids, static fn (string $a, string $b): int => (int) substr($a, 2) <=> (int) substr($b, 2));
        return [...$named, ...$ids];
    }

    /**
     * Language for the new icon: the one of an existing icon group, else of
     * the version resource, else en-US.
     *
     * @param array<string, array<string, array<string, mixed>>> $tree
     */
    private static function languageKey(array $tree): string
    {
        foreach ([self::RT_GROUP_ICON, self::RT_VERSION] as $type) {
            foreach ($tree['i:' . $type] ?? [] as $languages) {
                foreach (array_keys($languages) as $lang) {
                    return (string) $lang;
                }
            }
        }
        return 'i:' . self::DEFAULT_LANGUAGE;
    }

    /**
     * micro's size record (big-endian u64 size, optional u64 limit) with a new size.
     */
    private static function sfxSizeRecord(string $record, int $size): string
    {
        if (strlen($record) < 8) {
            throw new \RuntimeException('The runtime carries a malformed micro size record');
        }
        $out = pack('J', $size) . substr($record, 8);
        if (strlen($record) >= 16) {
            /** @var array{1: int} $limit */
            $limit = unpack('J', $record, 8);
            if ($limit[1] !== 0 && $limit[1] < $size) {
                throw new \RuntimeException('The runtime limits its own size below the size it grows to');
            }
        }
        return $out;
    }

    private static function alignUp(int $value, int $alignment): int
    {
        return $alignment <= 1 ? $value : intdiv($value + $alignment - 1, $alignment) * $alignment;
    }
}
