<?php

// PHP, not Python: a stand-in for tools/speech/chatterbox_worker.py in tests. The
// synthesizer runs it with PHPOLYGON_SPEECH_PYTHON = PHP_BINARY, and as the "ssh"
// client of remote workers. It speaks the worker protocol (JSON jobs on stdin, one
// JSON result per job on stdout) and writes "worker|text|reference|p=<params>" as
// the WAV (inline: "worker|text|reference|sent=0/1|p=<params>"); <params> = JSON
// of the job's optional SpeechParams fields, in the order they arrived.
//
// Behaviour "name.delayMs.dieAfter.startExit":
//   worker mode  --fake=<behaviour>, else env FAKE_SPEECH_<DEVICE> (e.g. FAKE_SPEECH_CPU)
//   ssh mode     the user of user@host before "--"; the remote command must ask for --inline
//   dieAfter N   exit 7 on receiving the Nth job, without reporting it
//   startExit N  exit N before reading any job

declare(strict_types=1);

$args = array_slice($argv, 1);
$sep = array_search('--', $args, true);
if ($sep !== false && $sep > 0) {
    $behaviour = explode('@', (string) $args[$sep - 1])[0];
    $remote = (string) ($args[$sep + 1] ?? '');
    if (!str_contains($remote, '--inline') || !str_contains($remote, '--jobs -')) {
        fwrite(STDERR, "remote command without --inline\n");
        exit(9);
    }
    $inline = true;
} else {
    $behaviour = '';
    $device = 'auto';
    foreach ($args as $i => $arg) {
        if (str_starts_with($arg, '--fake=')) {
            $behaviour = substr($arg, 7);
        }
        if ($arg === '--device') {
            $device = (string) ($args[$i + 1] ?? 'auto');
        }
    }
    if ($behaviour === '') {
        $behaviour = (string) getenv('FAKE_SPEECH_' . strtoupper($device));
    }
    $inline = in_array('--inline', $args, true);
}
$parts = explode('.', $behaviour);
$name = $parts[0] !== '' ? $parts[0] : 'fake';
$delay = (int) ($parts[1] ?? 0);
$dieAfter = (int) ($parts[2] ?? 0);
$startExit = (int) ($parts[3] ?? 0);

fwrite(STDERR, "fake {$name} ready\n");
if ($startExit !== 0) {
    exit($startExit);
}
echo "loading model\n"; // stray library output on stdout

$refs = [];
$n = 0;
while (($line = fgets(STDIN)) !== false) {
    if (trim($line) === '') {
        continue;
    }
    $job = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    $n++;
    if ($dieAfter > 0 && $n >= $dieAfter) {
        fwrite(STDERR, "fake {$name} dies\n");
        exit(7);
    }
    usleep($delay * 1000);
    $content = "{$name}|{$job['text']}|";
    $base = ['id', 'text', 'language', 'reference', 'reference_wav', 'out', 'seed', 'exaggeration', 'cfg_weight'];
    $params = '|p=' . json_encode((object) array_diff_key($job, array_flip($base)), JSON_PRESERVE_ZERO_FRACTION);
    if ($inline) {
        $reference = $job['reference'];
        if (isset($job['reference_wav'])) {
            $refs[$reference] = base64_decode($job['reference_wav']);
        }
        if ($reference !== null && !isset($refs[$reference])) {
            echo json_encode(['id' => $job['id'], 'error' => "reference {$reference} never sent"]), "\n";
            continue;
        }
        $content .= ($reference !== null ? $refs[$reference] : 'builtin') . '|sent=' . (isset($job['reference_wav']) ? 1 : 0) . $params;
        echo json_encode(['id' => $job['id'], 'ok' => true, 'wav' => base64_encode($content)]), "\n";
    } else {
        file_put_contents($job['out'], $content . ($job['reference'] ?? 'builtin') . $params);
        echo json_encode(['id' => $job['id'], 'ok' => true]), "\n";
    }
}
