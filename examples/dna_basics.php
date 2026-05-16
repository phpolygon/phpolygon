<?php

/**
 * PHPolygon - Character DNA Basics
 *
 * Generates a random CharacterDNA strand, decodes it into PlayerProportions,
 * prints the result, then verifies the ACGT roundtrip + a known-strand decode.
 *
 * Run: php examples/dna_basics.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPolygon\Character\DNA\CharacterDNA;
use PHPolygon\Character\DNA\GeneDecoder;
use PHPolygon\Character\DNA\PlayerProportions;

$decoder = new GeneDecoder();
$dna     = CharacterDNA::random();
$props   = $decoder->decode($dna, PlayerProportions::class);

echo "== Random character ==\n";
echo "ACGT (72 bases / 18 bytes):\n  " . $dna->toAcgt() . "\n\n";
printProportions($props);

echo "\n== ACGT roundtrip ==\n";
$restored      = CharacterDNA::fromAcgt($dna->toAcgt());
$restoredProps = $decoder->decode($restored, PlayerProportions::class);
echo ($props == $restoredProps ? 'OK  ' : 'FAIL') . " - decoded proportions identical after ACGT roundtrip\n";

echo "\n== Known strand (all-A / all-T) ==\n";
$min = $decoder->decode(CharacterDNA::fromAcgt(str_repeat('A', 72)), PlayerProportions::class);
$max = $decoder->decode(CharacterDNA::fromAcgt(str_repeat('T', 72)), PlayerProportions::class);
printf("bodyHeight   min=%.3f max=%.3f\n", $min->bodyHeight, $max->bodyHeight);
printf("jawWidth     min=%.3f max=%.3f\n", $min->jawWidth, $max->jawWidth);
printf("skinTone     min=%s max=%s\n", $min->skinTone->name, $max->skinTone->name);
printf("hairStyle    min=%s max=%s\n", $min->hairStyle->name, $max->hairStyle->name);

echo "\n== Codon table ==\n";
for ($locus = 0; $locus < CharacterDNA::STRAND_CODONS; $locus++) {
    printf("  locus %2d -> codon %2d\n", $locus, $dna->codon($locus));
}

function printProportions(PlayerProportions $p): void
{
    printf("  bodyHeight     %.3f\n", $p->bodyHeight);
    printf("  shoulderWidth  %.3f\n", $p->shoulderWidth);
    printf("  hipWidth       %.3f\n", $p->hipWidth);
    printf("  torsoLength    %.3f\n", $p->torsoLength);
    printf("  limbLength     %.3f\n", $p->limbLength);
    printf("  limbTaper      %.3f\n", $p->limbTaper);
    printf("  skullHeight    %.3f\n", $p->skullHeight);
    printf("  skullWidth     %.3f\n", $p->skullWidth);
    printf("  jawWidth       %.3f\n", $p->jawWidth);
    printf("  browProminence %.3f\n", $p->browProminence);
    printf("  eyeSpacing     %.3f\n", $p->eyeSpacing);
    printf("  skinTone       %-14s %s\n", $p->skinTone->name, $p->skinTone->value);
    printf("  hairColor      %-14s %s\n", $p->hairColor->name, $p->hairColor->value);
    printf("  hairStyle      %s\n",        $p->hairStyle->name);
    printf("  eyeColor       %-14s %s\n", $p->eyeColor->name, $p->eyeColor->value);
    printf("  eyeShape       %s\n",        $p->eyeShape->name);
}
