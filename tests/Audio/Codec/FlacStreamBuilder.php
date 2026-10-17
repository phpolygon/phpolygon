<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Codec;

/**
 * Writes FLAC streams with explicitly chosen header codes and subframe types –
 * the corners neither {@see \PHPolygon\Audio\Codec\FlacWriter} nor ffmpeg reach
 * (variable block size, 8/12/20/32-bit samples, LPC of any order, Rice2 and
 * escape partitions, wasted bits). Straight from RFC 9639, written for tests
 * only: no searching, every choice is the caller's.
 *
 * A subframe is described as an array:
 *   ['constant']
 *   ['verbatim']
 *   ['fixed', order]
 *   ['lpc', coefs (list<int>), precision, shift]
 * plus optional keys 'wasted' (int), 'method' (0 = Rice, 1 = Rice2),
 * 'partitionOrder' (int), 'escape' (int width: escape-code every partition).
 * The residual is computed here from the samples, so any predictor is lossless.
 */
final class FlacStreamBuilder
{
    private string $out = '';
    private int $acc = 0;
    private int $nbits = 0;

    /** @var list<string> */
    private array $frames = [];
    private int $total = 0;
    private string $pcm = '';

    public function __construct(
        private readonly int $rate,
        private readonly int $channels,
        private readonly int $bps,
        private readonly bool $variable = false,
    ) {}

    /**
     * Append one frame.
     *
     * @param list<list<int>> $samples per channel (the raw input, before stereo decorrelation)
     * @param list<array<int|string, mixed>> $subframes one description per channel
     * @param array{blockCode?: int, rateCode?: int, sizeCode?: int, chCode?: int} $codes
     */
    public function frame(array $samples, array $subframes, array $codes = []): self
    {
        $n = count($samples[0]);
        $chCode = $codes['chCode'] ?? $this->channels - 1;
        $coded = $samples;
        if ($chCode === 8) {
            $coded = [$samples[0], self::diff($samples[0], $samples[1])];
        } elseif ($chCode === 9) {
            $coded = [self::diff($samples[0], $samples[1]), $samples[1]];
        } elseif ($chCode === 10) {
            $mid = [];
            foreach ($samples[0] as $i => $l) {
                $mid[] = ($l + $samples[1][$i]) >> 1;
            }
            $coded = [$mid, self::diff($samples[0], $samples[1])];
        }

        $w = new self(0, 0, 0);
        $w->put(0x3FFE, 14);
        $w->put(0, 1);
        $w->put($this->variable ? 1 : 0, 1);
        $blockCode = $codes['blockCode'] ?? self::blockCode($n);
        $w->put($blockCode, 4);
        $rateCode = $codes['rateCode'] ?? 0;
        $w->put($rateCode, 4);
        $w->put($chCode, 4);
        $w->put($codes['sizeCode'] ?? 0, 3);
        $w->put(0, 1);
        $w->out .= self::utf8($this->variable ? $this->total : count($this->frames));
        if ($blockCode === 6) {
            $w->put($n - 1, 8);
        } elseif ($blockCode === 7) {
            $w->put($n - 1, 16);
        }
        if ($rateCode === 12) {
            $w->put(intdiv($this->rate, 1000), 8);
        } elseif ($rateCode === 13) {
            $w->put($this->rate, 16);
        } elseif ($rateCode === 14) {
            $w->put(intdiv($this->rate, 10), 16);
        }
        $w->put(self::crc8($w->out), 8);

        foreach ($coded as $c => $chSamples) {
            $side = ($chCode === 8 && $c === 1) || ($chCode === 9 && $c === 0) || ($chCode === 10 && $c === 1);
            $w->subframe($chSamples, $this->bps + ($side ? 1 : 0), $subframes[$c]);
        }
        $w->align();
        $w->out .= pack('n', self::crc16($w->out));
        $this->frames[] = $w->out;

        $width = intdiv($this->bps + 7, 8);
        for ($i = 0; $i < $n; $i++) {
            foreach ($samples as $ch) {
                $this->pcm .= substr(pack('V', $ch[$i] & 0xFFFFFFFF), 0, $width);
            }
        }
        $this->total += $n;
        return $this;
    }

    /** The finished stream; $totalSamples overrides STREAMINFO's count (0 = unknown). */
    public function bytes(?int $totalSamples = null, bool $md5 = true): string
    {
        $info = new self(0, 0, 0);
        $info->put(16, 16);
        $info->put(65535, 16);
        $info->put(0, 24);
        $info->put(0, 24);
        $info->put($this->rate, 20);
        $info->put($this->channels - 1, 3);
        $info->put($this->bps - 1, 5);
        $total = $totalSamples ?? $this->total;
        $info->put($total >> 32, 4);
        $info->put($total & 0xFFFFFFFF, 32);
        $sum = $md5 ? md5($this->pcm, true) : str_repeat("\0", 16);
        // An APPLICATION block after STREAMINFO, to be skipped.
        $app = 'test' . 'payload';
        return 'fLaC' . "\x00\x00\x00\x22" . $info->out . $sum
            . chr(0x80 | 2) . substr(pack('N', strlen($app)), 1) . $app
            . implode('', $this->frames);
    }

    /**
     * @param list<int> $s
     * @param array<int|string, mixed> $d
     */
    private function subframe(array $s, int $bps, array $d): void
    {
        $kind = $d[0];
        $wasted = is_int($d['wasted'] ?? null) ? $d['wasted'] : 0;
        if ($wasted > 0) {
            $s = array_map(static fn (int $v): int => $v >> $wasted, $s);
            $bps -= $wasted;
        }
        $this->put(0, 1);
        $type = match ($kind) {
            'constant' => 0,
            'verbatim' => 1,
            'fixed' => 8 + (int) $d[1],
            'lpc' => 31 + count(is_array($d[1]) ? $d[1] : []),
            default => throw new \LogicException("unknown subframe $kind"),
        };
        $this->put($type, 6);
        if ($wasted > 0) {
            $this->put(1, 1);
            $this->put(1, $wasted); // unary: wasted-1 zeros, then a one
        } else {
            $this->put(0, 1);
        }
        if ($kind === 'constant') {
            $this->putSigned($s[0], $bps);
            return;
        }
        if ($kind === 'verbatim') {
            foreach ($s as $v) {
                $this->putSigned($v, $bps);
            }
            return;
        }
        if ($kind === 'fixed') {
            $order = (int) $d[1];
            $coefs = [[], [1], [2, -1], [3, -3, 1], [4, -6, 4, -1]][$order];
            $shift = 0;
        } else {
            /** @var list<int> $coefs */
            $coefs = $d[1];
            $order = count($coefs);
            $shift = (int) $d[3];
        }
        for ($i = 0; $i < $order; $i++) {
            $this->putSigned($s[$i], $bps);
        }
        if ($kind === 'lpc') {
            $precision = (int) $d[2];
            $this->put($precision - 1, 4);
            $this->putSigned($shift, 5);
            foreach ($coefs as $c) {
                $this->putSigned($c, $precision);
            }
        }
        $res = [];
        for ($i = $order, $n = count($s); $i < $n; $i++) {
            $sum = 0;
            foreach ($coefs as $j => $c) {
                $sum += $c * $s[$i - 1 - $j];
            }
            $res[] = $s[$i] - ($sum >> $shift);
        }
        $this->residual($res, count($s), $order, $d);
    }

    /**
     * @param list<int> $res
     * @param array<int|string, mixed> $d
     */
    private function residual(array $res, int $blockSize, int $order, array $d): void
    {
        $method = (int) ($d['method'] ?? 0);
        $porder = (int) ($d['partitionOrder'] ?? 0);
        $escape = isset($d['escape']) ? (int) $d['escape'] : null;
        $this->put($method, 2);
        $this->put($porder, 4);
        $paramBits = $method === 1 ? 5 : 4;
        $size = $blockSize >> $porder;
        $at = 0;
        for ($p = 0; $p < (1 << $porder); $p++) {
            $n = $p === 0 ? $size - $order : $size;
            $part = array_slice($res, $at, $n);
            $at += $n;
            if ($escape !== null) {
                $this->put($method === 1 ? 31 : 15, $paramBits);
                $this->put($escape, 5);
                if ($escape > 0) {
                    foreach ($part as $v) {
                        $this->putSigned($v, $escape);
                    }
                }
                continue;
            }
            // Smallest parameter whose quotients stay short.
            $max = 0;
            foreach ($part as $v) {
                $max = max($max, $v >= 0 ? $v << 1 : ((-$v) << 1) - 1);
            }
            $k = 0;
            while (($max >> $k) > 4 && $k < ($method === 1 ? 30 : 14)) {
                $k++;
            }
            if (($max >> $k) > 64) {
                // Too wide for the parameter field: unencoded binary instead.
                $width = 1;
                while ($max >= (1 << $width)) {
                    $width++;
                }
                if ($width > 31) {
                    throw new \LogicException('residual exceeds 31 bits');
                }
                $this->put($method === 1 ? 31 : 15, $paramBits);
                $this->put($width, 5);
                foreach ($part as $v) {
                    $this->putSigned($v, $width);
                }
                continue;
            }
            $this->put($k, $paramBits);
            foreach ($part as $v) {
                $u = $v >= 0 ? $v << 1 : ((-$v) << 1) - 1;
                $q = $u >> $k;
                for ($z = 0; $z < $q; $z++) {
                    $this->put(0, 1);
                }
                $this->put(1, 1);
                if ($k > 0) {
                    $this->put($u & ((1 << $k) - 1), $k);
                }
            }
        }
    }

    private function put(int $v, int $n): void
    {
        for ($i = $n - 1; $i >= 0; $i--) {
            $this->acc = ($this->acc << 1) | (($v >> $i) & 1);
            if (++$this->nbits === 8) {
                $this->out .= chr($this->acc);
                $this->acc = 0;
                $this->nbits = 0;
            }
        }
    }

    private function putSigned(int $v, int $n): void
    {
        $this->put($v & ((1 << $n) - 1), $n);
    }

    private function align(): void
    {
        while ($this->nbits !== 0) {
            $this->put(0, 1);
        }
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     * @return list<int>
     */
    private static function diff(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $i => $v) {
            $out[] = $v - $b[$i];
        }
        return $out;
    }

    private static function blockCode(int $n): int
    {
        return match ($n) {
            192 => 1,
            576 => 2, 1152 => 3, 2304 => 4, 4608 => 5,
            256 => 8, 512 => 9, 1024 => 10, 2048 => 11, 4096 => 12, 8192 => 13, 16384 => 14, 32768 => 15,
            default => $n <= 256 ? 6 : 7,
        };
    }

    private static function utf8(int $v): string
    {
        if ($v < 0x80) {
            return chr($v);
        }
        // n bytes carry 5n + 1 bits (2 → 11 … 7 → 36).
        $n = 2;
        while ($v >= (1 << (5 * $n + 1))) {
            $n++;
        }
        $out = chr(((0xFF << (8 - $n)) & 0xFF) | ($v >> (6 * ($n - 1))));
        for ($i = $n - 2; $i >= 0; $i--) {
            $out .= chr(0x80 | (($v >> (6 * $i)) & 0x3F));
        }
        return $out;
    }

    private static function crc8(string $s): int
    {
        $crc = 0;
        foreach (str_split($s) as $ch) {
            $crc ^= ord($ch);
            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x80) !== 0 ? (($crc << 1) ^ 0x07) & 0xFF : ($crc << 1) & 0xFF;
            }
        }
        return $crc;
    }

    private static function crc16(string $s): int
    {
        $crc = 0;
        foreach (str_split($s) as $ch) {
            $crc ^= ord($ch) << 8;
            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x8005) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }
        return $crc;
    }
}
