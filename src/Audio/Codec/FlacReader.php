<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Codec;

use PHPolygon\Audio\Dsp\Pcm;
use PHPolygon\Audio\Dsp\Resampler;

/**
 * A complete FLAC decoder (RFC 9639) in plain PHP – lossless sources for build
 * tools without any extension, deterministic to the bit.
 *
 * It reads every stream the format allows a single-format file to carry:
 *   - an optional leading ID3v2 tag, STREAMINFO, every other metadata block
 *     skipped;
 *   - fixed and variable block size frames (UTF-8 coded frame / sample
 *     numbers), all block size and sample rate codes incl. the ones stored at
 *     the end of the frame header;
 *   - 4–32 bits per sample (8/12/16/20/24/32 coded in the frame), 1–8
 *     channels, independent / left-side / right-side / mid-side stereo;
 *   - CONSTANT, VERBATIM, FIXED (order 0–4) and LPC (order 1–32) subframes,
 *     wasted bits, Rice and Rice2 residuals with partitions and escape codes.
 *
 * Every frame header CRC-8 and frame CRC-16 is checked; the STREAMINFO MD5 on
 * request. Malformed, truncated or corrupt input throws
 * {@see \InvalidArgumentException} naming the source and the frame; an
 * unreadable file throws {@see \RuntimeException}.
 *
 * {@see read()} mirrors {@see WavReader::read()}: channels averaged to mono,
 * scaled to [-1, 1), resampled with {@see Resampler} to the target rate.
 * {@see readSamples()} returns the exact integers per channel.
 *
 * Speed: the bits of a window of the file are expanded into a string of '0'
 * and '1' characters once, so a Rice code is one `strspn()` (the unary part)
 * plus one `bindec()` – the per-bit work happens in C.
 */
final class FlacReader
{
    /** Bytes expanded into the bit window at a time. */
    private const int WINDOW = 1 << 18;

    /** Bits per sample of the frame header's sample size code (-1 = reserved). */
    private const array SAMPLE_SIZES = [0, 8, 12, -1, 16, 20, 24, 32];

    /** Sample rates of the frame header's codes 1–11. */
    private const array RATES = [0, 88200, 176400, 192000, 8000, 16000, 22050, 24000, 32000, 44100, 48000, 96000];

    private const int CH_LEFT_SIDE = 8;
    private const int CH_RIGHT_SIDE = 9;
    private const int CH_MID_SIDE = 10;

    /** @var array<string, string>|null byte → its eight bits as '0'/'1' */
    private static ?array $bitTable = null;
    /** @var list<int>|null */
    private static ?array $crc8 = null;
    /** @var list<int>|null CRC-16 per byte */
    private static ?array $crc16 = null;
    /** @var list<int>|null CRC-16 per big-endian 16-bit word */
    private static ?array $crc16w = null;

    private string $bits = '';
    private int $bitLen = 0;
    /** Byte offset of the bit window in the data. */
    private int $base = 0;
    /** Bit position inside the window. */
    private int $pos = 0;
    private int $frame = 0;

    private function __construct(
        private readonly string $data,
        private readonly string $source,
    ) {}

    /**
     * Mono floats at $targetRate, like {@see WavReader::read()}.
     *
     * @return list<float>
     */
    public static function read(string $path, int $targetRate = Pcm::SAMPLE_RATE, bool $verifyMd5 = false): array
    {
        $r = self::run(self::load($path), $path, $verifyMd5);
        return self::mono($r['channels'], $r['info'], $targetRate);
    }

    /** @return list<float> */
    public static function decode(string $bytes, int $targetRate = Pcm::SAMPLE_RATE, bool $verifyMd5 = false): array
    {
        $r = self::run($bytes, 'FLAC data', $verifyMd5);
        return self::mono($r['channels'], $r['info'], $targetRate);
    }

    /**
     * The exact signed integer samples per channel (bit-identical to the
     * encoder's input) and the STREAMINFO.
     *
     * @return array{info: FlacInfo, channels: list<list<int>>}
     */
    public static function readSamples(string $path, bool $verifyMd5 = false): array
    {
        return self::run(self::load($path), $path, $verifyMd5);
    }

    /** @return array{info: FlacInfo, channels: list<list<int>>} */
    public static function decodeSamples(string $bytes, bool $verifyMd5 = false): array
    {
        return self::run($bytes, 'FLAC data', $verifyMd5);
    }

    /** STREAMINFO of a file; reads only the head of the file. */
    public static function readInfo(string $path): FlacInfo
    {
        $head = is_file($path) ? @file_get_contents($path, false, null, 0, 10) : false;
        if ($head === false) {
            throw new \RuntimeException("cannot read FLAC file $path");
        }
        $skip = self::id3Size($head);
        $bytes = @file_get_contents($path, false, null, $skip, 42);
        if ($bytes === false) {
            throw new \RuntimeException("cannot read FLAC file $path");
        }
        return self::header($bytes, $path, false)[0];
    }

    /** STREAMINFO of FLAC bytes. */
    public static function info(string $bytes): FlacInfo
    {
        return self::header($bytes, 'FLAC data', false)[0];
    }

    private static function load(string $path): string
    {
        $bytes = is_file($path) ? @file_get_contents($path) : false;
        if ($bytes === false) {
            throw new \RuntimeException("cannot read FLAC file $path");
        }
        return $bytes;
    }

    /**
     * @param list<list<int>> $channels
     * @return list<float>
     */
    private static function mono(array $channels, FlacInfo $info, int $targetRate): array
    {
        // Same arithmetic as WavReader (divide, sum, scale by 1/n), so a FLAC
        // and a WAV of the same PCM read to identical floats.
        $count = count($channels);
        $full = (float) (1 << ($info->bitsPerSample - 1));
        $mono = [];
        if ($count === 1) {
            foreach ($channels[0] as $v) {
                $mono[] = $v / $full;
            }
        } else {
            $inv = 1.0 / $count;
            $n = count($channels[0]);
            for ($i = 0; $i < $n; $i++) {
                $s = 0.0;
                foreach ($channels as $ch) {
                    $s += $ch[$i] / $full;
                }
                $mono[] = $s * $inv;
            }
        }
        return Resampler::convert($mono, $info->sampleRate, $targetRate);
    }

    /** @return array{info: FlacInfo, channels: list<list<int>>} */
    private static function run(string $bytes, string $source, bool $verifyMd5): array
    {
        [$info, $offset] = self::header($bytes, $source, true);
        $d = new self($bytes, $source);
        return ['info' => $info, 'channels' => $d->frames($info, $offset, $verifyMd5)];
    }

    /** Size of a leading ID3v2 tag (0 without one). */
    private static function id3Size(string $head): int
    {
        if (strlen($head) < 10 || substr($head, 0, 3) !== 'ID3') {
            return 0;
        }
        $size = 0;
        for ($i = 6; $i < 10; $i++) {
            $size = ($size << 7) | (ord($head[$i]) & 0x7F);
        }
        return 10 + $size + ((ord($head[5]) & 0x10) !== 0 ? 10 : 0);
    }

    /**
     * Parse the marker and the metadata blocks.
     *
     * @return array{FlacInfo, int} STREAMINFO and the offset of the first frame
     */
    private static function header(string $bytes, string $source, bool $all): array
    {
        $len = strlen($bytes);
        $p = self::id3Size($bytes);
        if (substr($bytes, $p, 4) !== 'fLaC') {
            throw new \InvalidArgumentException("$source: not a FLAC stream (no fLaC marker)");
        }
        $p += 4;
        $info = null;
        do {
            if ($p + 4 > $len) {
                throw new \InvalidArgumentException("$source: truncated metadata block header at byte $p");
            }
            $h = self::u32be($bytes, $p);
            $last = ($h >> 31) & 1;
            $type = ($h >> 24) & 0x7F;
            $size = $h & 0xFFFFFF;
            $body = $p + 4;
            if ($info === null) {
                if ($type !== 0 || $size < 34) {
                    throw new \InvalidArgumentException("$source: the first metadata block is not STREAMINFO");
                }
                if ($body + 34 > $len) {
                    throw new \InvalidArgumentException("$source: truncated STREAMINFO");
                }
                $info = self::streamInfo($bytes, $body, $source);
                if (!$all) {
                    return [$info, 0];
                }
            } elseif ($type === 127) {
                throw new \InvalidArgumentException("$source: invalid metadata block type 127 at byte $p");
            } elseif ($type === 0) {
                throw new \InvalidArgumentException("$source: second STREAMINFO block at byte $p");
            }
            $p = $body + $size;
            if ($p > $len) {
                throw new \InvalidArgumentException("$source: truncated metadata block (type $type) at byte $body");
            }
        } while ($last === 0);
        return [$info, $p];
    }

    private static function streamInfo(string $b, int $at, string $source): FlacInfo
    {
        $v = (unpack('J', $b, $at + 10) ?: [])[1] ?? 0;
        $v = is_int($v) ? $v : 0;
        $rate = ($v >> 44) & 0xFFFFF;
        $channels = (($v >> 41) & 7) + 1;
        $bps = (($v >> 36) & 31) + 1;
        $total = $v & 0xFFFFFFFFF;
        $md5 = bin2hex(substr($b, $at + 18, 16));
        if ($bps < 4) {
            throw new \InvalidArgumentException("$source: invalid STREAMINFO ($bps bits per sample)");
        }
        if ($rate === 0) {
            throw new \InvalidArgumentException("$source: invalid STREAMINFO (sample rate 0)");
        }
        return new FlacInfo(
            sampleRate: $rate,
            channels: $channels,
            bitsPerSample: $bps,
            totalSamples: $total,
            md5: $md5 === str_repeat('0', 32) ? null : $md5,
            minBlockSize: self::u16be($b, $at),
            maxBlockSize: self::u16be($b, $at + 2),
            minFrameSize: self::u32be($b, $at + 3) & 0xFFFFFF,
            maxFrameSize: self::u32be($b, $at + 6) & 0xFFFFFF,
        );
    }

    /** @return list<list<int>> */
    private function frames(FlacInfo $info, int $offset, bool $verifyMd5): array
    {
        self::tables();
        $len = strlen($this->data);
        $total = $info->totalSamples;
        $hash = $verifyMd5 && $info->md5 !== null ? hash_init('md5') : null;
        $width = intdiv($info->bitsPerSample + 7, 8);
        /** @var list<list<list<int>>> $parts per channel, the blocks */
        $parts = array_fill(0, $info->channels, []);
        $decoded = 0;
        $this->window($offset, self::WINDOW);

        while ($offset < $len && ($total === 0 || $decoded < $total)) {
            [$block, $end] = $this->frameAt($offset, $info, $decoded);
            foreach ($block as $c => $samples) {
                $parts[$c][] = $samples;
            }
            if ($hash !== null) {
                hash_update($hash, self::pcm($block, $width));
            }
            $decoded += count($block[0]);
            $offset = $end;
            $this->frame++;
        }
        if ($total !== 0 && $decoded !== $total) {
            throw new \InvalidArgumentException($decoded < $total
                ? "$this->source: truncated – the stream ends after $decoded of $total samples (frame $this->frame)"
                : "$this->source: $decoded samples decoded, STREAMINFO declares $total");
        }
        if ($hash !== null && hash_final($hash) !== $info->md5) {
            throw new \InvalidArgumentException("$this->source: MD5 of the decoded samples does not match STREAMINFO");
        }
        $out = [];
        foreach ($parts as $blocks) {
            $out[] = $blocks === [] ? [] : array_merge(...$blocks);
        }
        return $out;
    }

    /** Expand $bytes bytes from $offset into the bit window. */
    private function window(int $offset, int $bytes): void
    {
        $this->base = $offset;
        $this->bits = strtr(substr($this->data, $offset, $bytes), self::$bitTable ?? []);
        $this->bitLen = strlen($this->bits);
    }

    /**
     * Decode the frame at byte $offset; grows the window when the frame does
     * not fit in it.
     *
     * @return array{list<list<int>>, int} channels of the block, end offset
     */
    private function frameAt(int $offset, FlacInfo $info, int $decoded): array
    {
        $len = strlen($this->data);
        $want = max($info->maxFrameSize, 16384) + 32;
        $windowBytes = $this->bitLen >> 3;
        if ($offset - $this->base + $want > $windowBytes && $this->base + $windowBytes < $len) {
            $this->window($offset, max(self::WINDOW, 2 * $want));
        }
        for (;;) {
            $this->pos = ($offset - $this->base) << 3;
            try {
                return $this->frameBody($offset, $info, $decoded);
            } catch (\UnderflowException | \InvalidArgumentException $e) {
                $windowBytes = $this->bitLen >> 3;
                $atEnd = $this->base + $windowBytes >= $len;
                // A format error far from the window's end is genuine; near it,
                // a field straddled the cut and the frame is retried in a wider window.
                if ($e instanceof \InvalidArgumentException && ($atEnd || $this->pos + 4096 < $this->bitLen)) {
                    throw $e;
                }
                if ($atEnd) {
                    throw new \InvalidArgumentException(
                        "$this->source: truncated – frame $this->frame at byte $offset runs past the end of the data",
                    );
                }
                $this->window($offset, max(self::WINDOW, 2 * $windowBytes));
            }
        }
    }

    /** @return array{list<list<int>>, int} */
    private function frameBody(int $offset, FlacInfo $info, int $decoded): array
    {
        $start = $this->pos;
        if ($this->bitLen - $start < 32) {
            throw new \UnderflowException();
        }
        if ($this->take(15) !== 0x7FFC) {
            throw $this->error($offset, 'no frame sync code (or reserved bit set)');
        }
        $variable = $this->take(1);
        $bsCode = $this->take(4);
        $rateCode = $this->take(4);
        $chCode = $this->take(4);
        $sizeCode = $this->take(3);
        if ($this->take(1) !== 0) {
            throw $this->error($offset, 'reserved header bit set');
        }
        $number = $this->utf8($offset);
        $blockSize = match (true) {
            $bsCode === 0 => throw $this->error($offset, 'reserved block size code 0'),
            $bsCode === 1 => 192,
            $bsCode <= 5 => 576 << ($bsCode - 2),
            $bsCode === 6 => $this->take(8) + 1,
            $bsCode === 7 => $this->take(16) + 1,
            default => 256 << ($bsCode - 8),
        };
        $rate = match (true) {
            $rateCode === 0 => $info->sampleRate,
            $rateCode <= 11 => self::RATES[$rateCode] ?? 0,
            $rateCode === 12 => $this->take(8) * 1000,
            $rateCode === 13 => $this->take(16),
            $rateCode === 14 => $this->take(16) * 10,
            default => throw $this->error($offset, 'invalid sample rate code 15'),
        };
        $bps = $sizeCode === 0 ? $info->bitsPerSample : self::SAMPLE_SIZES[$sizeCode] ?? -1;
        if ($bps < 0) {
            throw $this->error($offset, 'reserved sample size code 3');
        }
        if ($chCode > self::CH_MID_SIDE) {
            throw $this->error($offset, "reserved channel assignment $chCode");
        }
        $channels = $chCode < self::CH_LEFT_SIDE ? $chCode + 1 : 2;
        $crc = $this->take(8);
        if ($this->pos > $this->bitLen) {
            throw new \UnderflowException();
        }
        $headerBytes = ($this->pos - $start) >> 3;
        if (self::crc8($this->data, $offset, $headerBytes - 1) !== $crc) {
            throw $this->error($offset, 'header CRC-8 mismatch');
        }
        if ($channels !== $info->channels || $bps !== $info->bitsPerSample || $rate !== $info->sampleRate) {
            throw $this->error($offset, "format change mid-stream ($channels ch, $bps bit, $rate Hz)");
        }
        $expected = $variable === 1 ? $decoded : $this->frame;
        if ($number !== $expected) {
            throw $this->error($offset, ($variable === 1 ? 'sample' : 'frame') . " number $number, expected $expected");
        }

        $block = [];
        for ($c = 0; $c < $channels; $c++) {
            $side = ($chCode === self::CH_LEFT_SIDE && $c === 1)
                || ($chCode === self::CH_RIGHT_SIDE && $c === 0)
                || ($chCode === self::CH_MID_SIDE && $c === 1);
            $block[] = $this->subframe($offset, $blockSize, $bps + ($side ? 1 : 0));
        }

        $this->pos = ($this->pos + 7) & ~7;
        $crc16 = $this->take(16);
        if ($this->pos > $this->bitLen) {
            throw new \UnderflowException();
        }
        $frameBytes = ($this->pos - $start) >> 3;
        if (self::crc16($this->data, $offset, $frameBytes - 2) !== $crc16) {
            throw $this->error($offset, 'frame CRC-16 mismatch');
        }

        if ($chCode >= self::CH_LEFT_SIDE) {
            $block = self::decorrelate($chCode, $block[0], $block[1]);
        }
        return [$block, $offset + $frameBytes];
    }

    /** UTF-8 style coded frame / sample number (up to 36 bits). */
    private function utf8(int $offset): int
    {
        $b = $this->take(8);
        [$extra, $v] = match (true) {
            $b < 0x80 => [0, $b],
            $b >= 0xC0 && $b < 0xE0 => [1, $b & 0x1F],
            $b >= 0xE0 && $b < 0xF0 => [2, $b & 0x0F],
            $b >= 0xF0 && $b < 0xF8 => [3, $b & 0x07],
            $b >= 0xF8 && $b < 0xFC => [4, $b & 0x03],
            $b >= 0xFC && $b < 0xFE => [5, $b & 0x01],
            $b === 0xFE => [6, 0],
            default => throw $this->error($offset, 'invalid coded frame number'),
        };
        for ($i = 0; $i < $extra; $i++) {
            $x = $this->take(8);
            if (($x & 0xC0) !== 0x80) {
                throw $this->error($offset, 'invalid coded frame number');
            }
            $v = ($v << 6) | ($x & 0x3F);
        }
        return $v;
    }

    /** @return list<int> */
    private function subframe(int $offset, int $blockSize, int $bps): array
    {
        if ($this->take(1) !== 0) {
            throw $this->error($offset, 'subframe padding bit set');
        }
        $type = $this->take(6);
        $wasted = 0;
        if ($this->take(1) === 1) {
            $zeros = strspn($this->bits, '0', $this->pos);
            $this->pos += $zeros + 1;
            $wasted = $zeros + 1;
            $bps -= $wasted;
            if ($bps < 1) {
                if ($this->pos > $this->bitLen) {
                    throw new \UnderflowException();
                }
                throw $this->error($offset, "$wasted wasted bits leave no sample bits");
            }
        }

        if ($type === 0) {
            $samples = array_fill(0, $blockSize, $this->takeSigned($bps));
        } elseif ($type === 1) {
            $samples = $this->verbatim($blockSize, $bps);
        } elseif ($type >= 8 && $type <= 12) {
            $order = $type - 8;
            if ($order > $blockSize) {
                throw $this->error($offset, "FIXED order $order exceeds block size $blockSize");
            }
            $warm = $this->verbatim($order, $bps);
            $samples = self::fixed($order, $warm, $this->residual($offset, $blockSize, $order));
        } elseif ($type >= 32) {
            $order = $type - 31;
            if ($order > $blockSize) {
                throw $this->error($offset, "LPC order $order exceeds block size $blockSize");
            }
            $warm = $this->verbatim($order, $bps);
            $precision = $this->take(4) + 1;
            if ($precision === 16) {
                throw $this->error($offset, 'invalid LPC coefficient precision');
            }
            $shift = $this->takeSigned(5);
            if ($shift < 0) {
                if ($this->pos > $this->bitLen) {
                    throw new \UnderflowException();
                }
                throw $this->error($offset, "negative LPC shift $shift");
            }
            $coefs = $this->verbatim($order, $precision);
            $samples = self::lpc($coefs, $shift, $warm, $this->residual($offset, $blockSize, $order));
        } else {
            throw $this->error($offset, "reserved subframe type $type");
        }
        if ($this->pos > $this->bitLen) {
            throw new \UnderflowException();
        }
        if ($wasted > 0) {
            foreach ($samples as $i => $v) {
                $samples[$i] = $v << $wasted;
            }
        }
        return $samples;
    }

    /** @return list<int> $n signed values of $bps bits */
    private function verbatim(int $n, int $bps): array
    {
        if ($n === 0) {
            return [];
        }
        $b = $this->bits;
        $p = $this->pos;
        $sign = 1 << ($bps - 1);
        $range = 1 << $bps;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $v = (int) bindec(substr($b, $p, $bps));
            $p += $bps;
            $out[] = $v >= $sign ? $v - $range : $v;
        }
        $this->pos = $p;
        return $out;
    }

    /** @return list<int> the residual of one subframe ($blockSize - $order values) */
    private function residual(int $offset, int $blockSize, int $order): array
    {
        $method = $this->take(2);
        if ($method > 1) {
            throw $this->error($offset, "reserved residual coding method $method");
        }
        $paramBits = $method === 1 ? 5 : 4;
        $escape = $method === 1 ? 31 : 15;
        $partitionOrder = $this->take(4);
        $partitions = 1 << $partitionOrder;
        $size = $blockSize >> $partitionOrder;
        if (($blockSize & ($partitions - 1)) !== 0 || $size < $order) {
            if ($this->pos > $this->bitLen) {
                throw new \UnderflowException();
            }
            throw $this->error($offset, "partition order $partitionOrder does not fit block size $blockSize");
        }

        $res = [];
        $b = $this->bits;
        for ($part = 0; $part < $partitions; $part++) {
            $n = $part === 0 ? $size - $order : $size;
            $p = $this->pos;
            $k = (int) bindec(substr($b, $p, $paramBits));
            $p += $paramBits;
            if ($k === $escape) {
                $width = (int) bindec(substr($b, $p, 5));
                $this->pos = $p + 5;
                if ($width === 0) {
                    for ($i = 0; $i < $n; $i++) {
                        $res[] = 0;
                    }
                } else {
                    foreach ($this->verbatim($n, $width) as $v) {
                        $res[] = $v;
                    }
                }
                continue;
            }
            if ($k === 0) {
                for ($i = 0; $i < $n; $i++) {
                    $q = strspn($b, '0', $p);
                    $p += $q + 1;
                    $res[] = ($q >> 1) ^ -($q & 1);
                }
            } else {
                for ($i = 0; $i < $n; $i++) {
                    $q = strspn($b, '0', $p);
                    $p += $q + 1;
                    $v = ($q << $k) | (int) bindec(substr($b, $p, $k));
                    $p += $k;
                    $res[] = ($v >> 1) ^ -($v & 1);
                }
            }
            $this->pos = $p;
            if ($p > $this->bitLen) {
                throw new \UnderflowException();
            }
        }
        return $res;
    }

    /**
     * @param list<int> $warm
     * @param list<int> $res
     * @return list<int>
     */
    private static function fixed(int $order, array $warm, array $res): array
    {
        $s = $warm;
        switch ($order) {
            case 0:
                return $res;
            case 1:
                $a = $warm[0];
                foreach ($res as $r) {
                    $a += $r;
                    $s[] = $a;
                }
                return $s;
            case 2:
                [$a, $b] = $warm;
                foreach ($res as $r) {
                    $c = $r + 2 * $b - $a;
                    $s[] = $c;
                    $a = $b;
                    $b = $c;
                }
                return $s;
            case 3:
                [$a, $b, $c] = $warm;
                foreach ($res as $r) {
                    $d = $r + 3 * ($c - $b) + $a;
                    $s[] = $d;
                    $a = $b;
                    $b = $c;
                    $c = $d;
                }
                return $s;
            default:
                [$a, $b, $c, $d] = $warm;
                foreach ($res as $r) {
                    $e = $r + 4 * ($d + $b) - 6 * $c - $a;
                    $s[] = $e;
                    $a = $b;
                    $b = $c;
                    $c = $d;
                    $d = $e;
                }
                return $s;
        }
    }

    /**
     * Restore an LPC subframe: s[i] = r[i] + (Σ c[j]·s[i-1-j]) >> shift.
     *
     * @param list<int> $coefs
     * @param list<int> $warm
     * @param list<int> $res
     * @return list<int>
     */
    private static function lpc(array $coefs, int $shift, array $warm, array $res): array
    {
        // The history lives in locals; the variant with the fewest taps that
        // holds the order runs, its extra taps multiply zero coefficients.
        return match (true) {
            count($coefs) <= 8 => self::lpc8($coefs, $shift, $warm, $res),
            count($coefs) <= 12 => self::lpc12($coefs, $shift, $warm, $res),
            count($coefs) <= 16 => self::lpc16($coefs, $shift, $warm, $res),
            count($coefs) <= 20 => self::lpc20($coefs, $shift, $warm, $res),
            count($coefs) <= 24 => self::lpc24($coefs, $shift, $warm, $res),
            default => self::lpc32($coefs, $shift, $warm, $res),
        };
    }
    /**
     * @param list<int> $coefs at most 8
     * @param list<int> $warm
     * @param list<int> $res
     * @return list<int>
     */
    private static function lpc8(array $coefs, int $shift, array $warm, array $res): array
    {
        [$c0, $c1, $c2, $c3, $c4, $c5, $c6, $c7] = array_pad($coefs, 8, 0);
        [$h1, $h2, $h3, $h4, $h5, $h6, $h7, $h8] = array_pad(array_reverse($warm), 8, 0);
        $s = $warm;
        foreach ($res as $r) {
            $v = $r + ((
                $c0 * $h1 + $c1 * $h2 + $c2 * $h3 + $c3 * $h4
                + $c4 * $h5 + $c5 * $h6 + $c6 * $h7 + $c7 * $h8
            ) >> $shift);
            $s[] = $v;
            $h8 = $h7;
            $h7 = $h6;
            $h6 = $h5;
            $h5 = $h4;
            $h4 = $h3;
            $h3 = $h2;
            $h2 = $h1;
            $h1 = $v;
        }
        return $s;
    }

    /**
     * @param list<int> $coefs at most 12
     * @param list<int> $warm
     * @param list<int> $res
     * @return list<int>
     */
    private static function lpc12(array $coefs, int $shift, array $warm, array $res): array
    {
        [$c0, $c1, $c2, $c3, $c4, $c5, $c6, $c7, $c8, $c9, $c10, $c11] = array_pad($coefs, 12, 0);
        [$h1, $h2, $h3, $h4, $h5, $h6, $h7, $h8, $h9, $h10, $h11, $h12] = array_pad(array_reverse($warm), 12, 0);
        $s = $warm;
        foreach ($res as $r) {
            $v = $r + ((
                $c0 * $h1 + $c1 * $h2 + $c2 * $h3 + $c3 * $h4
                + $c4 * $h5 + $c5 * $h6 + $c6 * $h7 + $c7 * $h8
                + $c8 * $h9 + $c9 * $h10 + $c10 * $h11 + $c11 * $h12
            ) >> $shift);
            $s[] = $v;
            $h12 = $h11;
            $h11 = $h10;
            $h10 = $h9;
            $h9 = $h8;
            $h8 = $h7;
            $h7 = $h6;
            $h6 = $h5;
            $h5 = $h4;
            $h4 = $h3;
            $h3 = $h2;
            $h2 = $h1;
            $h1 = $v;
        }
        return $s;
    }

    /**
     * @param list<int> $coefs at most 16
     * @param list<int> $warm
     * @param list<int> $res
     * @return list<int>
     */
    private static function lpc16(array $coefs, int $shift, array $warm, array $res): array
    {
        [$c0, $c1, $c2, $c3, $c4, $c5, $c6, $c7, $c8, $c9, $c10, $c11, $c12, $c13, $c14, $c15] = array_pad($coefs, 16, 0);
        [$h1, $h2, $h3, $h4, $h5, $h6, $h7, $h8, $h9, $h10, $h11, $h12, $h13, $h14, $h15, $h16] = array_pad(array_reverse($warm), 16, 0);
        $s = $warm;
        foreach ($res as $r) {
            $v = $r + ((
                $c0 * $h1 + $c1 * $h2 + $c2 * $h3 + $c3 * $h4
                + $c4 * $h5 + $c5 * $h6 + $c6 * $h7 + $c7 * $h8
                + $c8 * $h9 + $c9 * $h10 + $c10 * $h11 + $c11 * $h12
                + $c12 * $h13 + $c13 * $h14 + $c14 * $h15 + $c15 * $h16
            ) >> $shift);
            $s[] = $v;
            $h16 = $h15;
            $h15 = $h14;
            $h14 = $h13;
            $h13 = $h12;
            $h12 = $h11;
            $h11 = $h10;
            $h10 = $h9;
            $h9 = $h8;
            $h8 = $h7;
            $h7 = $h6;
            $h6 = $h5;
            $h5 = $h4;
            $h4 = $h3;
            $h3 = $h2;
            $h2 = $h1;
            $h1 = $v;
        }
        return $s;
    }

    /**
     * @param list<int> $coefs at most 20
     * @param list<int> $warm
     * @param list<int> $res
     * @return list<int>
     */
    private static function lpc20(array $coefs, int $shift, array $warm, array $res): array
    {
        [$c0, $c1, $c2, $c3, $c4, $c5, $c6, $c7, $c8, $c9, $c10, $c11, $c12, $c13, $c14, $c15, $c16, $c17, $c18, $c19] = array_pad($coefs, 20, 0);
        [$h1, $h2, $h3, $h4, $h5, $h6, $h7, $h8, $h9, $h10, $h11, $h12, $h13, $h14, $h15, $h16, $h17, $h18, $h19, $h20] = array_pad(array_reverse($warm), 20, 0);
        $s = $warm;
        foreach ($res as $r) {
            $v = $r + ((
                $c0 * $h1 + $c1 * $h2 + $c2 * $h3 + $c3 * $h4
                + $c4 * $h5 + $c5 * $h6 + $c6 * $h7 + $c7 * $h8
                + $c8 * $h9 + $c9 * $h10 + $c10 * $h11 + $c11 * $h12
                + $c12 * $h13 + $c13 * $h14 + $c14 * $h15 + $c15 * $h16
                + $c16 * $h17 + $c17 * $h18 + $c18 * $h19 + $c19 * $h20
            ) >> $shift);
            $s[] = $v;
            $h20 = $h19;
            $h19 = $h18;
            $h18 = $h17;
            $h17 = $h16;
            $h16 = $h15;
            $h15 = $h14;
            $h14 = $h13;
            $h13 = $h12;
            $h12 = $h11;
            $h11 = $h10;
            $h10 = $h9;
            $h9 = $h8;
            $h8 = $h7;
            $h7 = $h6;
            $h6 = $h5;
            $h5 = $h4;
            $h4 = $h3;
            $h3 = $h2;
            $h2 = $h1;
            $h1 = $v;
        }
        return $s;
    }

    /**
     * @param list<int> $coefs at most 24
     * @param list<int> $warm
     * @param list<int> $res
     * @return list<int>
     */
    private static function lpc24(array $coefs, int $shift, array $warm, array $res): array
    {
        [$c0, $c1, $c2, $c3, $c4, $c5, $c6, $c7, $c8, $c9, $c10, $c11, $c12, $c13, $c14, $c15, $c16, $c17, $c18, $c19, $c20, $c21, $c22, $c23] = array_pad($coefs, 24, 0);
        [$h1, $h2, $h3, $h4, $h5, $h6, $h7, $h8, $h9, $h10, $h11, $h12, $h13, $h14, $h15, $h16, $h17, $h18, $h19, $h20, $h21, $h22, $h23, $h24] = array_pad(array_reverse($warm), 24, 0);
        $s = $warm;
        foreach ($res as $r) {
            $v = $r + ((
                $c0 * $h1 + $c1 * $h2 + $c2 * $h3 + $c3 * $h4
                + $c4 * $h5 + $c5 * $h6 + $c6 * $h7 + $c7 * $h8
                + $c8 * $h9 + $c9 * $h10 + $c10 * $h11 + $c11 * $h12
                + $c12 * $h13 + $c13 * $h14 + $c14 * $h15 + $c15 * $h16
                + $c16 * $h17 + $c17 * $h18 + $c18 * $h19 + $c19 * $h20
                + $c20 * $h21 + $c21 * $h22 + $c22 * $h23 + $c23 * $h24
            ) >> $shift);
            $s[] = $v;
            $h24 = $h23;
            $h23 = $h22;
            $h22 = $h21;
            $h21 = $h20;
            $h20 = $h19;
            $h19 = $h18;
            $h18 = $h17;
            $h17 = $h16;
            $h16 = $h15;
            $h15 = $h14;
            $h14 = $h13;
            $h13 = $h12;
            $h12 = $h11;
            $h11 = $h10;
            $h10 = $h9;
            $h9 = $h8;
            $h8 = $h7;
            $h7 = $h6;
            $h6 = $h5;
            $h5 = $h4;
            $h4 = $h3;
            $h3 = $h2;
            $h2 = $h1;
            $h1 = $v;
        }
        return $s;
    }

    /**
     * @param list<int> $coefs at most 32
     * @param list<int> $warm
     * @param list<int> $res
     * @return list<int>
     */
    private static function lpc32(array $coefs, int $shift, array $warm, array $res): array
    {
        [$c0, $c1, $c2, $c3, $c4, $c5, $c6, $c7, $c8, $c9, $c10, $c11, $c12, $c13, $c14, $c15, $c16, $c17, $c18, $c19, $c20, $c21, $c22, $c23, $c24, $c25, $c26, $c27, $c28, $c29, $c30, $c31] = array_pad($coefs, 32, 0);
        [$h1, $h2, $h3, $h4, $h5, $h6, $h7, $h8, $h9, $h10, $h11, $h12, $h13, $h14, $h15, $h16, $h17, $h18, $h19, $h20, $h21, $h22, $h23, $h24, $h25, $h26, $h27, $h28, $h29, $h30, $h31, $h32] = array_pad(array_reverse($warm), 32, 0);
        $s = $warm;
        foreach ($res as $r) {
            $v = $r + ((
                $c0 * $h1 + $c1 * $h2 + $c2 * $h3 + $c3 * $h4
                + $c4 * $h5 + $c5 * $h6 + $c6 * $h7 + $c7 * $h8
                + $c8 * $h9 + $c9 * $h10 + $c10 * $h11 + $c11 * $h12
                + $c12 * $h13 + $c13 * $h14 + $c14 * $h15 + $c15 * $h16
                + $c16 * $h17 + $c17 * $h18 + $c18 * $h19 + $c19 * $h20
                + $c20 * $h21 + $c21 * $h22 + $c22 * $h23 + $c23 * $h24
                + $c24 * $h25 + $c25 * $h26 + $c26 * $h27 + $c27 * $h28
                + $c28 * $h29 + $c29 * $h30 + $c30 * $h31 + $c31 * $h32
            ) >> $shift);
            $s[] = $v;
            $h32 = $h31;
            $h31 = $h30;
            $h30 = $h29;
            $h29 = $h28;
            $h28 = $h27;
            $h27 = $h26;
            $h26 = $h25;
            $h25 = $h24;
            $h24 = $h23;
            $h23 = $h22;
            $h22 = $h21;
            $h21 = $h20;
            $h20 = $h19;
            $h19 = $h18;
            $h18 = $h17;
            $h17 = $h16;
            $h16 = $h15;
            $h15 = $h14;
            $h14 = $h13;
            $h13 = $h12;
            $h12 = $h11;
            $h11 = $h10;
            $h10 = $h9;
            $h9 = $h8;
            $h8 = $h7;
            $h7 = $h6;
            $h6 = $h5;
            $h5 = $h4;
            $h4 = $h3;
            $h3 = $h2;
            $h2 = $h1;
            $h1 = $v;
        }
        return $s;
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     * @return list<list<int>>
     */
    private static function decorrelate(int $mode, array $a, array $b): array
    {
        $left = [];
        $right = [];
        if ($mode === self::CH_LEFT_SIDE) {
            foreach ($a as $i => $l) {
                $right[] = $l - $b[$i];
            }
            return [$a, $right];
        }
        if ($mode === self::CH_RIGHT_SIDE) {
            foreach ($b as $i => $r) {
                $left[] = $a[$i] + $r;
            }
            return [$left, $b];
        }
        foreach ($a as $i => $mid) {
            $side = $b[$i];
            $mid = ($mid << 1) | ($side & 1);
            $left[] = ($mid + $side) >> 1;
            $right[] = ($mid - $side) >> 1;
        }
        return [$left, $right];
    }

    /**
     * Interleaved little-endian PCM of a block, $width bytes per sample, as
     * the STREAMINFO MD5 hashes it.
     *
     * @param list<list<int>> $block
     */
    private static function pcm(array $block, int $width): string
    {
        $channels = count($block);
        if ($channels === 1) {
            $inter = $block[0];
        } elseif ($channels === 2) {
            $inter = [];
            $right = $block[1];
            foreach ($block[0] as $i => $l) {
                $inter[] = $l;
                $inter[] = $right[$i];
            }
        } else {
            $inter = [];
            $n = count($block[0]);
            for ($i = 0; $i < $n; $i++) {
                foreach ($block as $ch) {
                    $inter[] = $ch[$i];
                }
            }
        }
        $out = '';
        foreach (array_chunk($inter, 8192) as $chunk) {
            $out .= match ($width) {
                1 => pack('c*', ...$chunk),
                2 => pack('v*', ...$chunk),
                // 32-bit words with every fourth (top) byte dropped.
                3 => (string) preg_replace('/(...)./s', '$1', pack('V*', ...$chunk)),
                default => pack('V*', ...$chunk),
            };
        }
        return $out;
    }

    /**
     * The next $n bits as an unsigned value.
     *
     * @phpstan-impure
     */
    private function take(int $n): int
    {
        $v = (int) bindec(substr($this->bits, $this->pos, $n));
        $this->pos += $n;
        return $v;
    }

    /** @phpstan-impure */
    private function takeSigned(int $n): int
    {
        $v = $this->take($n);
        return $v >= (1 << ($n - 1)) ? $v - (1 << $n) : $v;
    }

    private function error(int $offset, string $what): \InvalidArgumentException
    {
        return new \InvalidArgumentException("$this->source: frame $this->frame at byte $offset: $what");
    }

    private static function tables(): void
    {
        if (self::$bitTable !== null) {
            return;
        }
        $bits = [];
        $crc8 = [];
        $crc16 = [];
        for ($i = 0; $i < 256; $i++) {
            $bits[chr($i)] = sprintf('%08b', $i);
            $c = $i;
            $w = $i << 8;
            for ($j = 0; $j < 8; $j++) {
                $c = ($c & 0x80) !== 0 ? (($c << 1) ^ 0x07) & 0xFF : ($c << 1) & 0xFF;
                $w = ($w & 0x8000) !== 0 ? (($w << 1) ^ 0x8005) & 0xFFFF : ($w << 1) & 0xFFFF;
            }
            $crc8[] = $c;
            $crc16[] = $w;
        }
        // Two bytes at once: crc' = W[crc ^ word] (the register is 16 bits wide).
        $words = [];
        for ($x = 0; $x < 0x10000; $x++) {
            $t = $crc16[$x >> 8];
            $words[] = (($t << 8) & 0xFFFF) ^ $crc16[($t >> 8) ^ ($x & 0xFF)];
        }
        self::$bitTable = $bits;
        self::$crc8 = $crc8;
        self::$crc16 = $crc16;
        self::$crc16w = $words;
    }

    private static function crc8(string $data, int $at, int $n): int
    {
        $t = self::$crc8 ?? [];
        $crc = 0;
        for ($i = 0; $i < $n; $i++) {
            $crc = $t[$crc ^ ord($data[$at + $i])];
        }
        return $crc;
    }

    private static function crc16(string $data, int $at, int $n): int
    {
        $w = self::$crc16w ?? [];
        $t = self::$crc16 ?? [];
        $crc = 0;
        $pairs = $n >> 1;
        if ($pairs > 0) {
            foreach (unpack('n*', substr($data, $at, $pairs * 2)) ?: [] as $word) {
                $crc = $w[$crc ^ (is_int($word) ? $word : 0)];
            }
        }
        if (($n & 1) !== 0) {
            $crc = (($crc << 8) & 0xFFFF) ^ $t[($crc >> 8) ^ ord($data[$at + $n - 1])];
        }
        return $crc;
    }

    private static function u16be(string $s, int $at): int
    {
        $v = (unpack('n', $s, $at) ?: [])[1] ?? 0;
        return is_int($v) ? $v : 0;
    }

    private static function u32be(string $s, int $at): int
    {
        $v = (unpack('N', $s, $at) ?: [])[1] ?? 0;
        return is_int($v) ? $v : 0;
    }
}
