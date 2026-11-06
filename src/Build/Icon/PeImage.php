<?php

declare(strict_types=1);

namespace PHPolygon\Build\Icon;

/**
 * The headers of a PE32 / PE32+ file that a resource edit has to read and
 * update: section table, data directories, image size, checksum. Every
 * `with*()` returns the edited bytes; parse them again before the next edit.
 */
final class PeImage
{
    /**
     * @param list<array{name: string, vsize: int, va: int, rawsize: int, raw: int, header: int}> $sections
     * @param list<array{int, int}> $directoryEntries RVA (file offset for the security directory) and size
     */
    private function __construct(
        public readonly int $optionalHeader,
        public readonly bool $pe32Plus,
        public readonly int $sectionTable,
        public readonly int $sizeOfHeaders,
        public readonly int $sectionAlignment,
        public readonly int $fileAlignment,
        public readonly int $directories,
        public readonly int $directoryCount,
        public readonly array $sections,
        private readonly array $directoryEntries,
    ) {}

    public static function parse(string $pe): self
    {
        if (strlen($pe) < 0x40 || substr($pe, 0, 2) !== 'MZ') {
            throw new \InvalidArgumentException('Not a Windows executable (no MZ header)');
        }
        /** @var array{1: int} $lfanew */
        $lfanew = unpack('V', $pe, 0x3c);
        $peHeader = $lfanew[1];
        if ($peHeader + 24 > strlen($pe) || substr($pe, $peHeader, 4) !== "PE\0\0") {
            throw new \InvalidArgumentException('Not a PE executable (no PE signature)');
        }
        /** @var array{sections: int, optional: int} $coff */
        $coff = unpack('vsections', $pe, $peHeader + 6) + unpack('voptional', $pe, $peHeader + 20);
        $optional = $peHeader + 24;
        /** @var array{1: int} $magic */
        $magic = unpack('v', $pe, $optional);
        if ($magic[1] !== 0x10b && $magic[1] !== 0x20b) {
            throw new \InvalidArgumentException(sprintf('Unknown PE optional header magic 0x%x', $magic[1]));
        }
        $plus = $magic[1] === 0x20b;
        /** @var array{1: int, 2: int} $alignment */
        $alignment = unpack('V2', $pe, $optional + 32);
        /** @var array{1: int} $headers */
        $headers = unpack('V', $pe, $optional + 60);
        /** @var array{1: int} $count */
        $count = unpack('V', $pe, $optional + ($plus ? 108 : 92));
        $table = $optional + $coff['optional'];
        $sections = [];
        for ($i = 0; $i < $coff['sections']; $i++) {
            $at = $table + 40 * $i;
            /** @var array{vsize: int, va: int, rawsize: int, raw: int} $s */
            $s = unpack('Vvsize/Vva/Vrawsize/Vraw', $pe, $at + 8);
            $sections[] = ['name' => rtrim(substr($pe, $at, 8), "\0"), ...$s, 'header' => $at];
        }
        $directories = $optional + ($plus ? 112 : 96);
        $entries = [];
        for ($i = 0; $i < min($count[1], 16); $i++) {
            /** @var array{1: int, 2: int} $d */
            $d = unpack('V2', $pe, $directories + 8 * $i);
            $entries[] = [$d[1], $d[2]];
        }
        return new self($optional, $plus, $table, $headers[1], $alignment[1], $alignment[2], $directories, $count[1], $sections, $entries);
    }

    /**
     * RVA and size of data directory $index ([0, 0] when absent).
     *
     * @return array{int, int}
     */
    public function directory(int $index): array
    {
        return $this->directoryEntries[$index] ?? [0, 0];
    }

    /** Index of the section holding $rva, or null. */
    public function sectionAt(int $rva): ?int
    {
        if ($rva === 0) {
            return null;
        }
        foreach ($this->sections as $i => $s) {
            if ($rva >= $s['va'] && $rva < $s['va'] + max($s['vsize'], $s['rawsize'])) {
                return $i;
            }
        }
        return null;
    }

    public function rvaToOffset(int $rva): ?int
    {
        $i = $this->sectionAt($rva);
        return $i === null ? null : $this->sections[$i]['raw'] + $rva - $this->sections[$i]['va'];
    }

    /** Index of the section that is last both in the file and in memory, or null when those differ. */
    public function lastSection(): ?int
    {
        if ($this->sections === []) {
            return null;
        }
        $byRaw = $byVa = 0;
        foreach ($this->sections as $i => $s) {
            if ($s['raw'] + $s['rawsize'] > $this->sections[$byRaw]['raw'] + $this->sections[$byRaw]['rawsize']) {
                $byRaw = $i;
            }
            if ($s['va'] > $this->sections[$byVa]['va']) {
                $byVa = $i;
            }
        }
        return $byRaw === $byVa ? $byRaw : null;
    }

    /** End of the last section's raw data in the file. */
    public function sectionsEnd(): int
    {
        $end = $this->sizeOfHeaders;
        foreach ($this->sections as $s) {
            $end = max($end, $s['raw'] + $s['rawsize']);
        }
        return $end;
    }

    /** End of the last section in memory (not aligned). */
    public function imageEnd(): int
    {
        $end = $this->sizeOfHeaders;
        foreach ($this->sections as $s) {
            $end = max($end, $s['va'] + max($s['vsize'], $s['rawsize']));
        }
        return $end;
    }

    public function hasRoomForSection(): bool
    {
        $tableEnd = $this->sectionTable + 40 * (count($this->sections) + 1);
        $firstRaw = PHP_INT_MAX;
        foreach ($this->sections as $s) {
            if ($s['rawsize'] > 0) {
                $firstRaw = min($firstRaw, $s['raw']);
            }
        }
        return $tableEnd <= $this->sizeOfHeaders && $tableEnd <= $firstRaw;
    }

    /**
     * $pe without its Authenticode signature (security directory cleared, the
     * certificate table cut off the end). Unchanged when there is none.
     */
    public function withoutSignature(string $pe): string
    {
        [$offset, $size] = $this->directory(4);
        if ($offset === 0 || $size === 0) {
            return $pe;
        }
        if ($offset + $size !== strlen($pe) && self::alignUp($offset + $size, 8) !== strlen($pe)) {
            throw new \RuntimeException('The executable has data after its signature; refusing to edit it');
        }
        $pe = substr($pe, 0, $offset);
        return $this->withDirectory($pe, 4, 0, 0);
    }

    public function withDirectory(string $pe, int $index, int $rva, int $size): string
    {
        if ($index >= $this->directoryCount) {
            throw new \RuntimeException("The executable has no data directory {$index}");
        }
        return substr_replace($pe, pack('VV', $rva, $size), $this->directories + 8 * $index, 8);
    }

    public function withSectionSize(string $pe, int $section, int $virtualSize, int $rawSize): string
    {
        $at = $this->sections[$section]['header'];
        $pe = substr_replace($pe, pack('V', $virtualSize), $at + 8, 4);
        return substr_replace($pe, pack('V', $rawSize), $at + 16, 4);
    }

    public function withSectionName(string $pe, int $section, string $name): string
    {
        return substr_replace($pe, str_pad(substr($name, 0, 8), 8, "\0"), $this->sections[$section]['header'], 8);
    }

    public function withNewSection(string $pe, string $name, int $rva, int $virtualSize, int $raw, int $rawSize, int $characteristics): string
    {
        if (!$this->hasRoomForSection()) {
            throw new \RuntimeException('The executable has no room for another section header');
        }
        $header = str_pad(substr($name, 0, 8), 8, "\0")
            . pack('VVVVVVvvV', $virtualSize, $rva, $rawSize, $raw, 0, 0, 0, 0, $characteristics);
        $pe = substr_replace($pe, $header, $this->sectionTable + 40 * count($this->sections), 40);
        /** @var array{1: int} $coffAt */
        $coffAt = unpack('V', $pe, 0x3c);
        return substr_replace($pe, pack('v', count($this->sections) + 1), $coffAt[1] + 6, 2);
    }

    /**
     * SizeOfImage and SizeOfInitializedData recomputed from the section table.
     */
    public function withUpdatedSizes(string $pe): string
    {
        $initialized = 0;
        foreach ($this->sections as $s) {
            $characteristics = self::readU32Of($pe, $s['header'] + 36);
            if (($characteristics & 0x40) !== 0) { // IMAGE_SCN_CNT_INITIALIZED_DATA
                $initialized += $s['rawsize'];
            }
        }
        $pe = substr_replace($pe, pack('V', $initialized), $this->optionalHeader + 8, 4);
        return substr_replace($pe, pack('V', self::alignUp($this->imageEnd(), $this->sectionAlignment)), $this->optionalHeader + 56, 4);
    }

    /** $pe with a correct optional-header CheckSum (the algorithm of MapFileAndCheckSum). */
    public function withChecksum(string $pe): string
    {
        $at = $this->optionalHeader + 64;
        $pe = substr_replace($pe, "\0\0\0\0", $at, 4);
        return substr_replace($pe, pack('V', self::checksum($pe)), $at, 4);
    }

    /** PE checksum of $pe as it is (the CheckSum field must already be zero). */
    public static function checksum(string $pe): int
    {
        $length = strlen($pe);
        $data = $length % 2 === 1 ? $pe . "\0" : $pe;
        $sum = 0;
        $chunk = 1 << 20;
        for ($i = 0, $n = strlen($data); $i < $n; $i += $chunk) {
            /** @var array<int, int> $words */
            $words = unpack('v*', substr($data, $i, $chunk));
            $sum += array_sum($words);
        }
        // End-around carry: folding at the end equals folding after every add.
        while ($sum > 0xffff) {
            $sum = ($sum & 0xffff) + ($sum >> 16);
        }
        return ($sum + $length) & 0xffffffff;
    }


    private static function readU32Of(string $pe, int $offset): int
    {
        /** @var array{1: int} $v */
        $v = unpack('V', $pe, $offset);
        return $v[1];
    }

    private static function alignUp(int $value, int $alignment): int
    {
        return $alignment <= 1 ? $value : intdiv($value + $alignment - 1, $alignment) * $alignment;
    }
}
