<?php

// PHP, not Python: a stand-in for tools/speech/moss_worker.py in tests (run with
// PHPOLYGON_SPEECH_PYTHON = PHP_BINARY, or as the "ssh" client of a remote worker).
// It speaks the worker protocol and writes a real 24 kHz mono 16-bit WAV of one
// second of silence per job, with the job line (without out and reference_wav) and
// the worker's arguments as JSON in an extra "phpo" chunk before "data". A job whose
// text is "fail" reports an error.

declare(strict_types=1);

$args = array_slice($argv, 1);
$sep = array_search('--', $args, true);
$inline = $sep !== false || in_array('--inline', $args, true);
$command = $sep !== false ? (string) ($args[$sep + 1] ?? '') : implode(' ', $args);

while (($line = fgets(STDIN)) !== false) {
    if (trim($line) === '') {
        continue;
    }
    $job = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    if ($job['text'] === 'fail') {
        echo json_encode(['id' => $job['id'], 'error' => 'RuntimeError: fake failure']), "\n";
        continue;
    }
    $meta = $job;
    unset($meta['out'], $meta['reference_wav']);
    $meta['args'] = $command;
    $note = (string) json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if (strlen($note) % 2 === 1) {
        $note .= ' ';
    }
    $rate = 24000;
    $data = str_repeat("\0\0", $rate);
    $fmt = pack('vvVVvv', 1, 1, $rate, $rate * 2, 2, 16);
    $wav = 'RIFF' . pack('V', 4 + 8 + strlen($fmt) + 8 + strlen($note) + 8 + strlen($data)) . 'WAVE'
        . 'fmt ' . pack('V', strlen($fmt)) . $fmt
        . 'phpo' . pack('V', strlen($note)) . $note
        . 'data' . pack('V', strlen($data)) . $data;
    $result = ['id' => $job['id'], 'ok' => true, 'seconds' => 0.0];
    if ($inline) {
        $result['wav'] = base64_encode($wav);
    } else {
        file_put_contents($job['out'], $wav);
    }
    echo json_encode($result), "\n";
}
