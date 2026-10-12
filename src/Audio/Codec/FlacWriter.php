<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Codec;

use PHPolygon\Audio\Dsp\Pcm;

/**
 * Lossless FLAC encoder for 16-bit stereo or mono ({@see encodeMono()}), in
 * plain PHP – rendered audio can ship as FLAC (php-vio decodes it) without a
 * codec tool in the build.
 *
 * The subset it writes, all of it standard FLAC:
 *   - fixed block size {@see BLOCK_SIZE}, a STREAMINFO block with total samples,
 *     frame size range and the MD5 of the PCM;
 *   - per block the cheapest of independent / left-side / right-side / mid-side
 *     stereo, chosen from the estimated residual size;
 *   - per channel a CONSTANT subframe (silence), a FIXED predictor of order 0–4
 *     (the one with the smallest residual), or VERBATIM when nothing helps;
 *   - partitioned Rice coding (4- or 5-bit parameters), partition order and
 *     parameters searched per subframe;
 *   - CRC-8 frame headers, CRC-16 frame footers, UTF-8 coded frame numbers.
 *
 * Typical music lands at 45–55 % of the WAV size; LPC subframes would win a few
 * percent more and are not worth the encode time here.
 */
final class FlacWriter
{
    public const int BLOCK_SIZE = 4096;
    private const int MAX_PARTITION_ORDER = 8;

    // Channel assignments of the frame header.
    private const int CH_INDEPENDENT = 1;
    private const int CH_LEFT_SIDE = 8;
    private const int CH_RIGHT_SIDE = 9;
    private const int CH_MID_SIDE = 10;

    /** @var list<int>|null */
    private static ?array $crc8 = null;
    /** @var list<int>|null */
    private static ?array $crc16 = null;

    private string $out = '';
    private int $acc = 0;
    private int $bits = 0;

    private function __construct() {}

    /**
     * Encode two equally long channels of signed 16-bit samples.
     *
     * @param list<int> $left
     * @param list<int> $right
     */
    public static function encode(array $left, array $right, int $sampleRate = Pcm::SAMPLE_RATE): string
    {
        if (count($right) !== count($left)) {
            throw new \InvalidArgumentException('both channels need the same length');
        }
        return self::stream([$left, $right], WavWriter::interleave($left, $right), $sampleRate);
    }

    /**
     * Encode one channel of signed 16-bit samples (e.g. speech).
     *
     * @param list<int> $samples
     */
    public static function encodeMono(array $samples, int $sampleRate = Pcm::SAMPLE_RATE): string
    {
        $pcm = '';
        foreach (array_chunk($samples, 4096) as $chunk) {
            $pcm .= pack('v*', ...array_map(static fn (int $v): int => $v & 0xFFFF, $chunk));
        }
        return self::stream([$samples], $pcm, $sampleRate);
    }

    /**
     * @param list<list<int>> $channels one or two equally long channels
     * @param string $pcm the interleaved 16-bit PCM, for the STREAMINFO MD5
     */
    private static function stream(array $channels, string $pcm, int $sampleRate): string
    {
        $total = count($channels[0]);
        $frames = '';
        $minFrame = PHP_INT_MAX;
        $maxFrame = 0;
        $frameNumber = 0;
        for ($start = 0; $start < $total; $start += self::BLOCK_SIZE) {
            $n = min(self::BLOCK_SIZE, $total - $start);
            $frame = !isset($channels[1])
                ? self::monoFrame(array_slice($channels[0], $start, $n), $frameNumber++, $sampleRate)
                : self::frame(
                    array_slice($channels[0], $start, $n),
                    array_slice($channels[1], $start, $n),
                    $frameNumber++,
                    $sampleRate,
                );
            $minFrame = min($minFrame, strlen($frame));
            $maxFrame = max($maxFrame, strlen($frame));
            $frames .= $frame;
        }
        if ($total === 0) {
            $minFrame = 0;
        }

        $info = new self();
        $info->write(self::BLOCK_SIZE, 16);
        $info->write(self::BLOCK_SIZE, 16);
        $info->write($minFrame, 24);
        $info->write($maxFrame, 24);
        $info->write($sampleRate, 20);
        $info->write(count($channels) - 1, 3);
        $info->write(Pcm::BITS_PER_SAMPLE - 1, 5);
        $info->write($total >> 32, 4);
        $info->write($total & 0xFFFFFFFF, 32);
        $md5 = md5($pcm, true);

        // Metadata block header: last block, type 0 (STREAMINFO), 34 bytes.
        return 'fLaC' . chr(0x80) . "\x00\x00\x22" . $info->out . $md5 . $frames;
    }

    /**
     * Read the samples of a 16-bit stereo WAV file (to re-encode it as FLAC).
     *
     * @return array{left: list<int>, right: list<int>, sampleRate: int}
     */
    public static function readWav(string $wav): array
    {
        if (substr($wav, 0, 4) !== 'RIFF' || substr($wav, 8, 4) !== 'WAVE') {
            throw new \InvalidArgumentException('not a WAV file');
        }
        $pos = 12;
        $sampleRate = Pcm::SAMPLE_RATE;
        $len = strlen($wav);
        while ($pos + 8 <= $len) {
            $id = substr($wav, $pos, 4);
            $size = self::u32($wav, $pos + 4);
            if ($id === 'fmt ') {
                if ($pos + 24 > $len) {
                    throw new \InvalidArgumentException('truncated fmt chunk');
                }
                $channels = self::u16($wav, $pos + 10);
                $sampleRate = self::u32($wav, $pos + 12);
                if (self::u16($wav, $pos + 22) !== 16 || $channels !== 2) {
                    throw new \InvalidArgumentException('only 16-bit stereo WAV');
                }
            } elseif ($id === 'data') {
                $left = [];
                $right = [];
                $raw = unpack('v*', substr($wav, $pos + 8, $size)) ?: [];
                $count = count($raw);
                for ($i = 1; $i + 1 <= $count; $i += 2) {
                    $l = self::int16($raw[$i] ?? 0);
                    $r = self::int16($raw[$i + 1] ?? 0);
                    $left[] = $l;
                    $right[] = $r;
                }
                return ['left' => $left, 'right' => $right, 'sampleRate' => $sampleRate];
            }
            $pos += 8 + $size + ($size & 1);
        }
        throw new \InvalidArgumentException('WAV without data chunk');
    }

    /** A little-endian unsigned 16-bit value as read by unpack(), as a signed sample. */
    private static function int16(mixed $v): int
    {
        $v = is_int($v) ? $v : 0;
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private static function u16(string $s, int $at): int
    {
        $v = (unpack('v', $s, $at) ?: [])[1] ?? 0;
        return is_int($v) ? $v : 0;
    }

    private static function u32(string $s, int $at): int
    {
        $v = (unpack('V', $s, $at) ?: [])[1] ?? 0;
        return is_int($v) ? $v : 0;
    }

    /**
     * @param list<int> $left
     * @param list<int> $right
     */
    private static function frame(array $left, array $right, int $frameNumber, int $sampleRate): string
    {
        $n = count($left);
        $mid = [];
        $side = [];
        for ($i = 0; $i < $n; $i++) {
            $mid[] = ($left[$i] + $right[$i]) >> 1;
            $side[] = $left[$i] - $right[$i];
        }

        $estL = self::analyse($left);
        $estR = self::analyse($right);
        $estM = self::analyse($mid);
        $estS = self::analyse($side);
        $options = [
            self::CH_INDEPENDENT => $estL[1] + $estR[1],
            self::CH_LEFT_SIDE => $estL[1] + $estS[1],
            self::CH_RIGHT_SIDE => $estR[1] + $estS[1],
            self::CH_MID_SIDE => $estM[1] + $estS[1],
        ];
        $assignment = (int) array_search(min($options), $options, true);

        [$first, $firstBits, $firstOrder, $second, $secondBits, $secondOrder] = match ($assignment) {
            self::CH_LEFT_SIDE => [$left, 16, $estL[0], $side, 17, $estS[0]],
            self::CH_RIGHT_SIDE => [$side, 17, $estS[0], $right, 16, $estR[0]],
            self::CH_MID_SIDE => [$mid, 16, $estM[0], $side, 17, $estS[0]],
            default => [$left, 16, $estL[0], $right, 16, $estR[0]],
        };

        $header = self::frameHeader($n, $assignment, $frameNumber, $sampleRate);

        $writer = new self();
        $writer->subframe($first, $firstBits, $firstOrder);
        $writer->subframe($second, $secondBits, $secondOrder);
        $writer->alignToByte();

        $frame = $header . $writer->out;
        $crc = self::crc16($frame);
        return $frame . chr(($crc >> 8) & 0xFF) . chr($crc & 0xFF);
    }

    /** @param list<int> $x */
    private static function monoFrame(array $x, int $frameNumber, int $sampleRate): string
    {
        // Channel assignment 0 = one independent channel.
        $header = self::frameHeader(count($x), 0, $frameNumber, $sampleRate);
        $writer = new self();
        $writer->subframe($x, 16, self::analyse($x)[0]);
        $writer->alignToByte();

        $frame = $header . $writer->out;
        $crc = self::crc16($frame);
        return $frame . chr(($crc >> 8) & 0xFF) . chr($crc & 0xFF);
    }

    private static function frameHeader(int $n, int $assignment, int $frameNumber, int $sampleRate): string
    {
        $header = "\xFF\xF8"; // sync, reserved 0, fixed block size
        $blockCode = $n === self::BLOCK_SIZE ? 12 : 7;
        $rateCode = match ($sampleRate) {
            44100 => 9,
            48000 => 10,
            32000 => 8,
            96000 => 11,
            default => 0, // from STREAMINFO
        };
        $header .= chr((($blockCode << 4) | $rateCode) & 0xFF);
        $header .= chr((($assignment << 4) | (4 << 1)) & 0xFF); // sample size code 4 = 16 bit
        $header .= self::utf8Number($frameNumber);
        if ($blockCode === 7) {
            $header .= chr((($n - 1) >> 8) & 0xFF) . chr(($n - 1) & 0xFF);
        }
        return $header . chr(self::crc8($header) & 0xFF);
    }

    /**
     * Best fixed predictor order of a channel and the estimated bits it costs.
     *
     * @param list<int> $x
     * @return array{int, int}
     */
    private static function analyse(array $x): array
    {
        $n = count($x);
        $s0 = $s1 = $s2 = $s3 = $s4 = 0;
        $l0 = $l1 = $l2 = $l3 = 0;
        for ($i = 0; $i < $n; $i++) {
            $e0 = $x[$i];
            $e1 = $e0 - $l0;
            $e2 = $e1 - $l1;
            $e3 = $e2 - $l2;
            $e4 = $e3 - $l3;
            $l0 = $e0;
            $l1 = $e1;
            $l2 = $e2;
            $l3 = $e3;
            if ($i >= 4) {
                $s0 += $e0 < 0 ? -$e0 : $e0;
                $s1 += $e1 < 0 ? -$e1 : $e1;
                $s2 += $e2 < 0 ? -$e2 : $e2;
                $s3 += $e3 < 0 ? -$e3 : $e3;
                $s4 += $e4 < 0 ? -$e4 : $e4;
            }
        }
        $sums = [$s0, $s1, $s2, $s3, $s4];
        $order = 0;
        for ($k = 1; $k <= 4; $k++) {
            if ($sums[$k] < $sums[$order]) {
                $order = $k;
            }
        }
        $m = max(1, $n - 4);
        // Zigzag doubles the magnitudes; Rice bits ≈ n·(k+1) + Σu >> k.
        $sum = 2 * $sums[$order];
        $k = $sum > $m ? (int) floor(log($sum / $m, 2)) : 0;
        return [$order, $m * ($k + 1) + ($sum >> $k)];
    }

    /** @param list<int> $x */
    private function subframe(array $x, int $bps, int $order): void
    {
        $n = count($x);
        $constant = true;
        for ($i = 1; $i < $n; $i++) {
            if ($x[$i] !== $x[0]) {
                $constant = false;
                break;
            }
        }
        if ($constant) {
            $this->write(0, 8); // padding bit, type 000000, no wasted bits
            $this->write($x[0], $bps);
            return;
        }

        $order = min($order, $n - 1);
        // Residuals of the fixed predictor, zigzag-mapped.
        $u = [];
        $l0 = $l1 = $l2 = $l3 = 0;
        for ($i = 0; $i < $n; $i++) {
            $e = $x[$i];
            $e1 = $e - $l0;
            $e2 = $e1 - $l1;
            $e3 = $e2 - $l2;
            $e4 = $e3 - $l3;
            $l0 = $e;
            $l1 = $e1;
            $l2 = $e2;
            $l3 = $e3;
            if ($i >= $order) {
                $r = match ($order) { 0 => $e, 1 => $e1, 2 => $e2, 3 => $e3, default => $e4 };
                $u[] = $r >= 0 ? $r << 1 : ((-$r) << 1) - 1;
            }
        }

        [$partitionOrder, $params, $bits] = self::riceSearch($u, $n, $order);
        $headerBits = 8 + $order * $bps;
        if ($headerBits + $bits >= 8 + $n * $bps) {
            $this->write(0b00000010, 8); // VERBATIM
            foreach ($x as $v) {
                $this->write($v, $bps);
            }
            return;
        }

        $this->write((0b001000 | $order) << 1, 8);
        for ($i = 0; $i < $order; $i++) {
            $this->write($x[$i], $bps);
        }
        $method = max([0, ...$params]) > 14 ? 1 : 0;
        $this->write($method, 2);
        $this->write($partitionOrder, 4);
        $paramBits = $method === 1 ? 5 : 4;
        $partitions = 1 << $partitionOrder;
        $size = $n >> $partitionOrder;
        $index = 0;
        for ($p = 0; $p < $partitions; $p++) {
            $count = $p === 0 ? $size - $order : $size;
            $k = $params[$p];
            $this->write($k, $paramBits);
            $this->riceRun($u, $index, $count, $k);
            $index += $count;
        }
    }

    /**
     * Pick the partition order and Rice parameters with the fewest estimated bits.
     *
     * @param list<int> $u zigzag residuals (block length minus predictor order)
     * @return array{int, list<int>, int} partition order, parameters, estimated bits
     */
    private static function riceSearch(array $u, int $n, int $order): array
    {
        $maxOrder = 0;
        while ($maxOrder < self::MAX_PARTITION_ORDER
            && $n % (1 << ($maxOrder + 1)) === 0
            && ($n >> ($maxOrder + 1)) > $order) {
            $maxOrder++;
        }

        // Sums per partition at the finest order, merged pairwise for coarser ones.
        $size = $n >> $maxOrder;
        $sums = [];
        $counts = [];
        $index = 0;
        for ($p = 0; $p < (1 << $maxOrder); $p++) {
            $count = $p === 0 ? $size - $order : $size;
            $s = 0;
            for ($i = 0; $i < $count; $i++) {
                $s += $u[$index + $i];
            }
            $index += $count;
            $sums[] = $s;
            $counts[] = $count;
        }

        $best = [0, [0], PHP_INT_MAX];
        for ($po = $maxOrder; $po >= 0; $po--) {
            $params = [];
            $bits = 0;
            foreach ($sums as $p => $s) {
                $count = $counts[$p];
                [$k, $cost] = self::bestParam($s, $count);
                $params[] = $k;
                $bits += $cost + 4;
            }
            if (max([0, ...$params]) > 14) {
                $bits += count($params); // 5-bit parameters
            }
            if ($bits < $best[2]) {
                $best = [$po, $params, $bits];
            }
            if ($po > 0) {
                $merged = [];
                $mergedCounts = [];
                for ($p = 0; $p < count($sums); $p += 2) {
                    $merged[] = $sums[$p] + $sums[$p + 1];
                    $mergedCounts[] = $counts[$p] + $counts[$p + 1];
                }
                $sums = $merged;
                $counts = $mergedCounts;
            }
        }
        return [$best[0], $best[1], $best[2] + 6];
    }

    /** @return array{int, int} parameter and estimated bits for a partition */
    private static function bestParam(int $sum, int $count): array
    {
        if ($count === 0) {
            return [0, 0];
        }
        $k = $sum > $count ? (int) floor(log($sum / $count, 2)) : 0;
        $k = max(0, min(30, $k));
        $best = [$k, $count * ($k + 1) + ($sum >> $k)];
        foreach ([$k - 1, $k + 1] as $c) {
            if ($c < 0 || $c > 30) {
                continue;
            }
            $cost = $count * ($c + 1) + ($sum >> $c);
            if ($cost < $best[1]) {
                $best = [$c, $cost];
            }
        }
        return $best;
    }

    /**
     * Rice-code $count values of $u from $start with parameter $k: the quotient
     * in unary (zeros, then a one), then the low $k bits. Inlined bit writer.
     *
     * @param list<int> $u
     */
    private function riceRun(array $u, int $start, int $count, int $k): void
    {
        $acc = $this->acc;
        $bits = $this->bits;
        $out = '';
        $mask = (1 << $k) - 1;
        $end = $start + $count;
        for ($i = $start; $i < $end; $i++) {
            $v = $u[$i];
            $q = $v >> $k;
            // Long codes: emit leading zeros first so the accumulator (< 2^8
            // between values) never needs more than 57 bits.
            while ($q + $k > 48) {
                $z = min($q, 24);
                $acc <<= $z;
                $bits += $z;
                $q -= $z;
                while ($bits >= 8) {
                    $bits -= 8;
                    $out .= chr(($acc >> $bits) & 0xFF);
                }
                $acc &= (1 << $bits) - 1;
            }
            $len = $q + 1 + $k;
            $acc = ($acc << $len) | (1 << $k) | ($v & $mask);
            $bits += $len;
            while ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($acc >> $bits) & 0xFF);
            }
            $acc &= (1 << $bits) - 1;
        }
        $this->out .= $out;
        $this->acc = $acc;
        $this->bits = $bits;
    }

    /** Append the low $n bits (n ≤ 32) of $value, most significant first. */
    private function write(int $value, int $n): void
    {
        $this->acc = ($this->acc << $n) | ($value & ((1 << $n) - 1));
        $this->bits += $n;
        while ($this->bits >= 8) {
            $this->bits -= 8;
            $this->out .= chr(($this->acc >> $this->bits) & 0xFF);
        }
        $this->acc &= (1 << $this->bits) - 1;
    }

    private function alignToByte(): void
    {
        if ($this->bits > 0) {
            $this->write(0, 8 - $this->bits);
        }
    }

    /** The frame number in FLAC's UTF-8-like variable-length coding. */
    private static function utf8Number(int $value): string
    {
        if ($value < 0x80) {
            return chr($value & 0x7F);
        }
        $bytes = '';
        $limit = 0x40; // free bits in the first byte shrink with each continuation byte
        $count = 1;
        while ($value >= $limit) {
            $bytes = chr(0x80 | ($value & 0x3F)) . $bytes;
            $value >>= 6;
            $limit >>= 1;
            $count++;
        }
        $lead = (0xFF << (8 - $count)) & 0xFF;
        return chr(($lead | $value) & 0xFF) . $bytes;
    }

    public static function crc8(string $data): int
    {
        if (self::$crc8 === null) {
            self::$crc8 = [];
            for ($i = 0; $i < 256; $i++) {
                $c = $i;
                for ($b = 0; $b < 8; $b++) {
                    $c = ($c & 0x80) !== 0 ? (($c << 1) ^ 0x07) & 0xFF : ($c << 1) & 0xFF;
                }
                self::$crc8[] = $c;
            }
        }
        $table = self::$crc8;
        $crc = 0;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $crc = $table[$crc ^ ord($data[$i])];
        }
        return $crc;
    }

    public static function crc16(string $data): int
    {
        if (self::$crc16 === null) {
            self::$crc16 = [];
            for ($i = 0; $i < 256; $i++) {
                $c = $i << 8;
                for ($b = 0; $b < 8; $b++) {
                    $c = ($c & 0x8000) !== 0 ? (($c << 1) ^ 0x8005) & 0xFFFF : ($c << 1) & 0xFFFF;
                }
                self::$crc16[] = $c;
            }
        }
        $table = self::$crc16;
        $crc = 0;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $crc = (($crc << 8) & 0xFFFF) ^ $table[($crc >> 8) ^ ord($data[$i])];
        }
        return $crc;
    }
}
