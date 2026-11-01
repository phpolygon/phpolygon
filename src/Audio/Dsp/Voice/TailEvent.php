<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp\Voice;

/**
 * One sound after the last voiced frame of a recording, as measured by
 * {@see TailBreathTrimmer}: levels in dB relative to the speech level, times in ms.
 */
final readonly class TailEvent
{
    /**
     * @param int $startSample first sample of the event
     * @param int $endSample one past the last sample of the event
     * @param int $valleySample quietest point of the gap before it
     * @param float $gapMs quiet time between the previous sound and this one, 0 = attached
     * @param float $valleyDb level at $valleySample
     * @param float $peakDb loudest frame
     * @param float $attackMs rise from 20 dB under the peak to 3 dB under it
     * @param float $kurtosis mean per-frame kurtosis, 3 = steady noise, above = pulses or a click
     * @param float $lowShare share of the 0.3–12 kHz energy below 1 kHz
     * @param float $highShare share of the 0.3–12 kHz energy above 4.5 kHz
     */
    public function __construct(
        public int $startSample,
        public int $endSample,
        public int $valleySample,
        public float $durationMs,
        public float $gapMs,
        public float $valleyDb,
        public float $peakDb,
        public float $attackMs,
        public float $kurtosis,
        public float $lowShare,
        public float $highShare,
    ) {}
}
