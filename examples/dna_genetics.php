<?php

/**
 * PHPolygon - Character DNA Genetics
 *
 * Two random "parents" produce children via single-point byte crossover.
 * One child is then mutated with N random bit flips. All resulting
 * strands are decoded into PlayerProportions and shown side by side
 * so the inheritance / drift is visible from one run to the next.
 *
 * Run: php examples/dna_genetics.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPolygon\Character\DNA\CharacterDNA;
use PHPolygon\Character\DNA\GeneDecoder;
use PHPolygon\Character\DNA\PlayerProportions;

$rand    = new \Random\Randomizer(new \Random\Engine\Mt19937(2026));
$decoder = new GeneDecoder();

$parentA = CharacterDNA::random($rand);
$parentB = CharacterDNA::random($rand);

$childEarly = CharacterDNA::crossover($parentA, $parentB, bytePoint: 6);   // mostly A
$childMid   = CharacterDNA::crossover($parentA, $parentB, bytePoint: 9);   // 50/50
$childLate  = CharacterDNA::crossover($parentA, $parentB, bytePoint: 12);  // mostly B
$mutant     = $childMid->mutate(bitFlips: 4, rand: $rand);

$characters = [
    'Parent A'        => $parentA,
    'Parent B'        => $parentB,
    'Child (cut=6)'   => $childEarly,
    'Child (cut=9)'   => $childMid,
    'Child (cut=12)'  => $childLate,
    'Mutant (4 flips)' => $mutant,
];

echo "== Strands ==\n";
foreach ($characters as $label => $dna) {
    printf("  %-18s %s\n", $label, $dna->toAcgt());
}

echo "\n== Decoded traits ==\n";
$rows = [];
foreach ($characters as $label => $dna) {
    $rows[$label] = $decoder->decode($dna, PlayerProportions::class);
}

$traits = [
    'bodyHeight'     => fn(PlayerProportions $p) => sprintf('%.3f', $p->bodyHeight),
    'shoulderWidth'  => fn(PlayerProportions $p) => sprintf('%.3f', $p->shoulderWidth),
    'hipWidth'       => fn(PlayerProportions $p) => sprintf('%.3f', $p->hipWidth),
    'jawWidth'       => fn(PlayerProportions $p) => sprintf('%.3f', $p->jawWidth),
    'browProminence' => fn(PlayerProportions $p) => sprintf('%.3f', $p->browProminence),
    'skinTone'       => fn(PlayerProportions $p) => $p->skinTone->name,
    'hairColor'      => fn(PlayerProportions $p) => $p->hairColor->name,
    'hairStyle'      => fn(PlayerProportions $p) => $p->hairStyle->name,
    'eyeColor'       => fn(PlayerProportions $p) => $p->eyeColor->name,
    'eyeShape'       => fn(PlayerProportions $p) => $p->eyeShape->name,
];

printf("%-16s", 'trait');
foreach (array_keys($rows) as $label) {
    printf(" %-16s", $label);
}
echo "\n" . str_repeat('-', 16 + 17 * count($rows)) . "\n";

foreach ($traits as $name => $fmt) {
    printf("%-16s", $name);
    foreach ($rows as $props) {
        printf(" %-16s", $fmt($props));
    }
    echo "\n";
}

echo "\n== Hamming distance (in bits) ==\n";
$pairs = [
    'A -> B'                    => [$parentA, $parentB],
    'A -> Child(cut=9)'         => [$parentA, $childMid],
    'B -> Child(cut=9)'         => [$parentB, $childMid],
    'Child(cut=9) -> Mutant'    => [$childMid, $mutant],
];
foreach ($pairs as $label => [$x, $y]) {
    printf("  %-26s %2d / 144\n", $label, hammingBits($x->bytes, $y->bytes));
}

function hammingBits(string $a, string $b): int
{
    $dist = 0;
    for ($i = 0, $len = strlen($a); $i < $len; $i++) {
        $xor = ord($a[$i]) ^ ord($b[$i]);
        while ($xor !== 0) {
            $dist += $xor & 1;
            $xor >>= 1;
        }
    }
    return $dist;
}
