<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Codec;

/**
 * A FLAC decoder for exactly the subset {@see \PHPolygon\Audio\Codec\FlacWriter}
 * writes (16-bit, one or two channels – mono returns the samples as both
 * left and right –, fixed block size, CONSTANT / VERBATIM / FIXED
 * subframes, Rice and Rice2 residuals incl. escape partitions). It checks every
 * CRC and the STREAMINFO MD5, so a round trip proves the stream, not just the
 * samples.
 *
 * Written independently of the encoder, straight from the format description,
 * so a shared misunderstanding is less likely – php-vio's miniaudio decoder is
 * the second opinion where the extension is loaded.
 */
final class FlacTestDecoder
{
    private string $data;
    private int $pos = 0; // bit position

    private function __construct(string $data)
    {
        $this->data = $data;
    }

    /**
     * @return array{channels: int, left: list<int>, right: list<int>, sampleRate: int, totalSamples: int, minBlock: int, maxBlock: int, minFrame: int, maxFrame: int, frames: int}
     */
    public static function decode(string $flac): array
    {
        $d = new self($flac);
        if (substr($flac, 0, 4) !== 'fLaC') {
            throw new \RuntimeException('no fLaC marker');
        }
        $d->pos = 32;
        $info = null;
        do {
            $last = $d->bits(1);
            $type = $d->bits(7);
            $length = $d->bits(24);
            if ($type === 0) {
                $info = [
                    'minBlock' => $d->bits(16),
                    'maxBlock' => $d->bits(16),
                    'minFrame' => $d->bits(24),
                    'maxFrame' => $d->bits(24),
                    'sampleRate' => $d->bits(20),
                    'channels' => $d->bits(3) + 1,
                    'bps' => $d->bits(5) + 1,
                    'totalSamples' => ($d->bits(4) << 32) | $d->bits(32),
                ];
                $md5 = substr($flac, intdiv($d->pos, 8), 16);
                $d->pos += 128;
                $info['md5'] = $md5;
            } else {
                $d->pos += $length * 8;
            }
        } while ($last === 0);
        if ($info === null || ($info['channels'] !== 2 && $info['channels'] !== 1) || $info['bps'] !== 16) {
            throw new \RuntimeException('expected 16-bit stereo or mono STREAMINFO');
        }
        $mono = $info['channels'] === 1;

        $left = [];
        $right = [];
        $frames = 0;
        $end = strlen($flac) * 8;
        while ($d->pos < $end) {
            $frameStart = intdiv($d->pos, 8);
            if ($d->bits(15) !== 0x7FFC || $d->bits(1) !== 0) {
                throw new \RuntimeException("lost sync at byte $frameStart");
            }
            $blockCode = $d->bits(4);
            $rateCode = $d->bits(4);
            $assignment = $d->bits(4);
            $sizeCode = $d->bits(3);
            if ($d->bits(1) !== 0 || $sizeCode !== 4) {
                throw new \RuntimeException('unexpected sample size');
            }
            $number = $d->utf8();
            if ($number !== $frames) {
                throw new \RuntimeException("frame number $number, expected $frames");
            }
            $n = match (true) {
                $blockCode === 1 => 192,
                $blockCode >= 2 && $blockCode <= 5 => 576 << ($blockCode - 2),
                $blockCode === 6 => $d->bits(8) + 1,
                $blockCode === 7 => $d->bits(16) + 1,
                $blockCode >= 8 => 256 << ($blockCode - 8),
                default => throw new \RuntimeException('reserved block size'),
            };
            if ($rateCode !== 9 && $rateCode !== 0) {
                throw new \RuntimeException('unexpected sample rate code');
            }
            $headerEnd = intdiv($d->pos, 8);
            $crc8 = $d->bits(8);
            if ($crc8 !== self::crc8(substr($flac, $frameStart, $headerEnd - $frameStart))) {
                throw new \RuntimeException("header CRC mismatch in frame $frames");
            }

            [$bps0, $bps1] = match (true) {
                $mono && $assignment === 0 => [16, 0],
                !$mono && $assignment === 1 => [16, 16],
                !$mono && ($assignment === 8 || $assignment === 10) => [16, 17],
                !$mono && $assignment === 9 => [17, 16],
                default => throw new \RuntimeException("channel assignment $assignment"),
            };
            $a = $d->subframe($n, $bps0);
            $b = $mono ? $a : $d->subframe($n, $bps1);
            if ($d->pos % 8 !== 0) {
                if ($d->bits(8 - $d->pos % 8) !== 0) {
                    throw new \RuntimeException('non-zero padding');
                }
            }
            $crcEnd = intdiv($d->pos, 8);
            $crc16 = $d->bits(16);
            if ($crc16 !== self::crc16(substr($flac, $frameStart, $crcEnd - $frameStart))) {
                throw new \RuntimeException("frame CRC mismatch in frame $frames");
            }

            for ($i = 0; $i < $n; $i++) {
                switch ($assignment) {
                    case 8:
                        $left[] = $a[$i];
                        $right[] = $a[$i] - $b[$i];
                        break;
                    case 9:
                        $left[] = $a[$i] + $b[$i];
                        $right[] = $b[$i];
                        break;
                    case 10:
                        $mid = ($a[$i] << 1) | ($b[$i] & 1);
                        $left[] = ($mid + $b[$i]) >> 1;
                        $right[] = ($mid - $b[$i]) >> 1;
                        break;
                    default:
                        $left[] = $a[$i];
                        $right[] = $b[$i];
                }
            }
            $frames++;
        }

        if (count($left) !== $info['totalSamples']) {
            throw new \RuntimeException('total samples mismatch');
        }
        $pcm = '';
        foreach ($left as $i => $l) {
            $pcm .= $mono ? pack('v', $l & 0xFFFF) : pack('v', $l & 0xFFFF) . pack('v', $right[$i] & 0xFFFF);
        }
        if (md5($pcm, true) !== $info['md5']) {
            throw new \RuntimeException('MD5 mismatch');
        }

        return [
            'channels' => $info['channels'],
            'left' => $left,
            'right' => $right,
            'sampleRate' => $info['sampleRate'],
            'totalSamples' => $info['totalSamples'],
            'minBlock' => $info['minBlock'],
            'maxBlock' => $info['maxBlock'],
            'minFrame' => $info['minFrame'],
            'maxFrame' => $info['maxFrame'],
            'frames' => $frames,
        ];
    }

    /**
     * @return list<int>
     * @phpstan-impure reads the stream
     */
    private function subframe(int $n, int $bps): array
    {
        if ($this->bits(1) !== 0) {
            throw new \RuntimeException('subframe padding bit set');
        }
        $type = $this->bits(6);
        if ($this->bits(1) !== 0) {
            throw new \RuntimeException('wasted bits not expected');
        }
        if ($type === 0) {
            return array_fill(0, $n, $this->signed($bps));
        }
        if ($type === 1) {
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                $out[] = $this->signed($bps);
            }
            return $out;
        }
        if ($type < 8 || $type > 12) {
            throw new \RuntimeException("subframe type $type not in the subset");
        }
        $order = $type - 8;
        $x = [];
        for ($i = 0; $i < $order; $i++) {
            $x[] = $this->signed($bps);
        }
        $method = $this->bits(2);
        if ($method > 1) {
            throw new \RuntimeException('reserved residual coding');
        }
        $paramBits = $method === 0 ? 4 : 5;
        $escape = $method === 0 ? 15 : 31;
        $partitionOrder = $this->bits(4);
        $partitions = 1 << $partitionOrder;
        $residual = [];
        for ($p = 0; $p < $partitions; $p++) {
            $count = ($n >> $partitionOrder) - ($p === 0 ? $order : 0);
            $k = $this->bits($paramBits);
            if ($k === $escape) {
                $raw = $this->bits(5);
                for ($i = 0; $i < $count; $i++) {
                    $residual[] = $raw === 0 ? 0 : $this->signed($raw);
                }
                continue;
            }
            for ($i = 0; $i < $count; $i++) {
                $q = 0;
                while ($this->bits(1) === 0) {
                    $q++;
                }
                $u = ($q << $k) | ($k > 0 ? $this->bits($k) : 0);
                $residual[] = ($u & 1) === 1 ? -(($u + 1) >> 1) : $u >> 1;
            }
        }
        foreach ($residual as $j => $r) {
            $i = $j + $order;
            $x[] = $r + match ($order) {
                0 => 0,
                1 => $x[$i - 1],
                2 => 2 * $x[$i - 1] - $x[$i - 2],
                3 => 3 * $x[$i - 1] - 3 * $x[$i - 2] + $x[$i - 3],
                default => 4 * $x[$i - 1] - 6 * $x[$i - 2] + 4 * $x[$i - 3] - $x[$i - 4],
            };
        }
        return $x;
    }

    /** @phpstan-impure reads the stream */
    private function utf8(): int
    {
        $first = $this->bits(8);
        if ($first < 0x80) {
            return $first;
        }
        $extra = 0;
        $mask = 0x40;
        while (($first & $mask) !== 0) {
            $extra++;
            $mask >>= 1;
        }
        $value = $first & ($mask - 1);
        for ($i = 0; $i < $extra; $i++) {
            $byte = $this->bits(8);
            if (($byte & 0xC0) !== 0x80) {
                throw new \RuntimeException('bad UTF-8 continuation');
            }
            $value = ($value << 6) | ($byte & 0x3F);
        }
        return $value;
    }

    /** @phpstan-impure reads the stream */
    private function signed(int $n): int
    {
        $v = $this->bits($n);
        return $v >= (1 << ($n - 1)) ? $v - (1 << $n) : $v;
    }

    /** @phpstan-impure reads the stream */
    private function bits(int $n): int
    {
        $v = 0;
        for ($i = 0; $i < $n; $i++) {
            $byte = ord($this->data[$this->pos >> 3] ?? throw new \RuntimeException('read past end'));
            $v = ($v << 1) | (($byte >> (7 - ($this->pos & 7))) & 1);
            $this->pos++;
        }
        return $v;
    }

    private static function crc8(string $data): int
    {
        $crc = 0;
        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $crc ^= ord($data[$i]);
            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x80) !== 0 ? (($crc << 1) ^ 0x07) & 0xFF : ($crc << 1) & 0xFF;
            }
        }
        return $crc;
    }

    private static function crc16(string $data): int
    {
        $crc = 0;
        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x8005) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }
        return $crc;
    }
}
