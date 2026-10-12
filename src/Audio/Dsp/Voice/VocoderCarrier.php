<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp\Voice;

/**
 * The instrument a {@see Vocoder} lets a recording play: a held, harmonically
 * rich tone whose bands the voice's envelopes open. A game plugs in its own
 * synthesiser here to give the vocoded voice the timbre of its music;
 * {@see SawChordCarrier} is the built-in default.
 */
interface VocoderCarrier
{
    /**
     * @param float $rootHz fundamental of the held tone
     * @param int $length samples to render
     * @param int $sampleRate rate of the vocoded signal
     * @param int $seed the vocoder's seed – the carrier must be deterministic per seed
     * @return list<float> mono, exactly $length samples, roughly within ±1
     */
    public function render(float $rootHz, int $length, int $sampleRate, int $seed): array;
}
