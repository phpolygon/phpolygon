<?php

/**
 * Regenerates the FLAC decoder fixtures: a few short PCM sources (written here
 * as WAV, deterministic) and FLAC encodings of them by ffmpeg's encoder with
 * different settings. The FLAC files are named `<source>__<variant>.flac`;
 * FlacReaderTest decodes each and compares it bit for bit with the WAV.
 *
 * Run from anywhere: php tests/Audio/Codec/fixtures/flac/generate.php
 * Needs ffmpeg on PATH. The fixtures are committed; rerun only to change them.
 */

declare(strict_types=1);

$dir = __DIR__;

/** Park-Miller style LCG in [-1, 1) – the same numbers on every platform. */
$rng = 0x2545F491;
$noise = static function () use (&$rng): float {
    $rng = ($rng * 48271) % 0x7FFFFFFF;
    return $rng / 0x3FFFFFFF - 1.0;
};

/**
 * @param list<list<int>> $channels
 */
$wav = static function (string $file, array $channels, int $rate, int $bits): void {
    $n = count($channels[0]);
    $ch = count($channels);
    $width = intdiv($bits, 8);
    $data = '';
    for ($i = 0; $i < $n; $i++) {
        foreach ($channels as $c) {
            $data .= substr(pack('V', $c[$i]), 0, $width);
        }
    }
    $fmt = pack('vvVVvv', 1, $ch, $rate, $rate * $ch * $width, $ch * $width, $bits);
    file_put_contents($file, 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE'
        . 'fmt ' . pack('V', 16) . $fmt . 'data' . pack('V', strlen($data)) . $data);
};

$clip = static fn (float $v, int $bits): int => (int) max(-(1 << ($bits - 1)), min((1 << ($bits - 1)) - 1, round($v)));

/**
 * A tone with a glide, some noise and a silent stretch – predictable enough for
 * LPC, noisy enough for real residuals.
 *
 * @return list<int>
 */
$signal = static function (int $n, int $rate, int $bits, float $f0, float $amp) use ($noise, $clip): array {
    $full = (float) (1 << ($bits - 1));
    $out = [];
    $phase = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $f = $f0 * (1.0 + $i / $n);
        $phase += 2 * M_PI * $f / $rate;
        $silent = $i > $n * 0.6 && $i < $n * 0.7;
        $v = $silent ? 0.0 : $amp * (0.8 * sin($phase) + 0.15 * sin(3.1 * $phase) + 0.05 * $noise());
        $out[] = $clip($v * $full, $bits);
    }
    return $out;
};

$sources = [];

$sources['mono16_44k'] = [[$signal(3001, 44100, 16, 440.0, 0.7)], 44100, 16];

$l = $signal(2000, 48000, 16, 300.0, 0.6);
$r = [];
foreach ($l as $i => $v) {
    $r[] = $clip($v * 0.9 + 400 * $noise(), 16); // correlated: side stereo wins
}
$sources['stereo16_48k'] = [[$l, $r], 48000, 16];

$l = $signal(1200, 96000, 24, 1200.0, 0.95);
$r = [];
foreach ($l as $i => $v) {
    $r[] = $clip(-$v * 0.5 + 3000000 * $noise(), 24); // loud noise: large Rice parameters
}
$sources['stereo24_96k'] = [[$l, $r], 96000, 24];

$m = [];
foreach ($signal(1500, 22050, 24, 200.0, 0.5) as $v) {
    $m[] = $v & ~0xFF; // low byte always zero: wasted bits
}
$sources['mono24_22k_wasted'] = [[$m], 22050, 24];

$sources['mono16_12k'] = [[$signal(1300, 12000, 16, 500.0, 0.4)], 12000, 16];    // rate code 12 (kHz)
$sources['mono16_37800'] = [[$signal(1300, 37800, 16, 500.0, 0.4)], 37800, 16];  // rate code 14 (10 Hz)
$sources['mono16_11025'] = [[$signal(1300, 11025, 16, 500.0, 0.4)], 11025, 16];  // rate code 13 (Hz)
$sources['stereo16_8k_short'] = [[[0, 1, -1, 32767, -32768, 5, 4], [3, -2, 0, -32768, 32767, 5, 4]], 8000, 16];

foreach ($sources as $name => [$channels, $rate, $bits]) {
    $wav("$dir/$name.wav", $channels, $rate, $bits);
}

$variants = [
    'c0' => '-compression_level 0',
    'c5' => '-compression_level 5',
    'c8' => '-compression_level 8',
    'c12' => '-compression_level 12',
];
$stereoModes = [
    'indep' => '-compression_level 5 -ch_mode indep',
    'left' => '-compression_level 5 -ch_mode left_side',
    'right' => '-compression_level 5 -ch_mode right_side',
    'mid' => '-compression_level 5 -ch_mode mid_side',
];
$extra = [
    'fs1000' => '-compression_level 8 -frame_size 1000',  // block size code 7 (16-bit), short last frame
    'fs200' => '-compression_level 5 -frame_size 200',    // block size code 6 (8-bit)
    'chol' => '-compression_level 8 -lpc_type cholesky -lpc_passes 3 -exact_rice_parameters 1 -multi_dim_quant 1',
    'fixed' => '-lpc_type fixed',
    'verbatim' => '-lpc_type none',
];

/** Rewrite a FLAC file without its PADDING metadata blocks; frames untouched. */
$stripPadding = static function (string $file): void {
    $b = (string) file_get_contents($file);
    $p = 4;
    $blocks = [];
    do {
        $h = (int) (unpack('N', $b, $p) ?: [1 => 0])[1];
        $last = ($h >> 31) & 1;
        $type = ($h >> 24) & 0x7F;
        $size = $h & 0xFFFFFF;
        if ($type !== 1) {
            $blocks[] = [$type, substr($b, $p + 4, $size)];
        }
        $p += 4 + $size;
    } while ($last === 0);
    $out = 'fLaC';
    foreach ($blocks as $i => [$type, $body]) {
        $flag = $i === count($blocks) - 1 ? 0x80 : 0;
        $out .= pack('N', (($flag | $type) << 24) | strlen($body)) . $body;
    }
    file_put_contents($file, $out . substr($b, $p));
};

foreach (glob("$dir/*.flac") ?: [] as $old) {
    unlink($old);
}
$seen = [];
foreach ($sources as $name => [$channels, $rate, $bits]) {
    $set = $variants;
    if ($name === 'stereo16_48k' || $name === 'stereo24_96k') {
        $set += $stereoModes;
    }
    if ($name === 'mono16_44k' || $name === 'stereo24_96k') {
        $set += $extra;
    }
    if (str_starts_with($name, 'mono16_1') || str_starts_with($name, 'mono16_3') || $name === 'stereo16_8k_short') {
        $set = ['c5' => '-compression_level 5'];
    }
    $fmt = $bits === 16 ? 's16' : 's32';
    foreach ($set as $variant => $args) {
        $out = "$dir/{$name}__$variant.flac";
        $cmd = sprintf(
            'ffmpeg -hide_banner -loglevel error -y -i %s -map_metadata -1 -fflags +bitexact -flags:a +bitexact -sample_fmt %s %s -c:a flac %s',
            escapeshellarg("$dir/$name.wav"),
            $fmt,
            $args,
            escapeshellarg($out),
        );
        passthru($cmd, $code);
        if ($code !== 0) {
            fwrite(STDERR, "ffmpeg failed: $cmd\n");
            exit(1);
        }
        // ffmpeg reserves an 8 KB PADDING block; one fixture keeps it (the
        // decoder must skip it), the others drop it to stay small.
        if ($name !== 'stereo16_8k_short') {
            $stripPadding($out);
        }
        // A setting that changes nothing for this source adds no coverage.
        $hash = md5_file($out);
        if (isset($seen["$name/$hash"])) {
            unlink($out);
        }
        $seen["$name/$hash"] = true;
    }
}

$total = 0;
foreach (glob("$dir/*.{wav,flac}", GLOB_BRACE) ?: [] as $f) {
    $total += (int) filesize($f);
}
printf("%d files, %.1f KB\n", count(glob("$dir/*.{wav,flac}", GLOB_BRACE) ?: []), $total / 1024);
