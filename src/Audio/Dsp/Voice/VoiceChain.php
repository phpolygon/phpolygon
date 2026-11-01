<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp\Voice;

use PHPolygon\Audio\Dsp\Loudness;
use PHPolygon\Audio\Dsp\Pcm;

/**
 * The whole voice path from a base recording to a game-ready line:
 *
 *   [trim the breath behind the last word] → [match to a reference voice]
 *   → shaper (pitch, formant, tempo, tilt, breath) → crush → vocoder → −16 LUFS
 *
 * The trim comes first ({@see TailBreathTrimmer}): nothing behind it measures
 * or levels a breath that will not be heard.
 *
 * Loudness after BS.1770 ({@see Loudness::integratedMono()}); the gain is
 * capped so no sample exceeds {@see PEAK_CEILING} (−1 dBFS) – very peaky clips
 * then end up a little quieter instead of clipping.
 *
 * {@see CHAIN_VERSION} belongs in render-cache keys: it is bumped whenever
 * the output for the same input, profile and carrier changes. A custom
 * {@see VocoderCarrier} is part of the sound too – a game that changes its
 * carrier keys its caches on that as well.
 *
 * Mono at {@see sampleRate()}. Deterministic.
 */
final class VoiceChain
{
    /**
     * 2: {@see TailBreathTrimmer} before the matcher.
     */
    public const int CHAIN_VERSION = 2;
    public const float TARGET_LUFS = -16.0;
    public const float PEAK_CEILING = 0.891; // −1 dBFS

    private readonly VoiceShaper $shaper;
    private readonly Crusher $crusher;
    private readonly VoiceMatcher $matcher;
    private readonly TailBreathTrimmer $tailTrimmer;

    /**
     * Parts left out are built at $sampleRate; parts passed in must run at it.
     *
     * @param VocoderCarrier|null $carrier the vocoder's instrument, null = {@see SawChordCarrier}
     */
    public function __construct(
        ?VoiceShaper $shaper = null,
        ?Crusher $crusher = null,
        ?VoiceMatcher $matcher = null,
        private readonly ?VocoderCarrier $carrier = null,
        private readonly int $sampleRate = Pcm::SAMPLE_RATE,
        ?TailBreathTrimmer $tailTrimmer = null,
    ) {
        $detector = new PitchDetector(sampleRate: $sampleRate);
        $this->shaper = $shaper ?? new VoiceShaper($detector);
        $this->crusher = $crusher ?? new Crusher(sampleRate: $sampleRate);
        $this->matcher = $matcher ?? new VoiceMatcher(new VoiceShaper($detector), $detector);
        $this->tailTrimmer = $tailTrimmer ?? new TailBreathTrimmer($sampleRate);
        foreach (['shaper' => $this->shaper->sampleRate(), 'crusher' => $this->crusher->sampleRate, 'matcher' => $this->matcher->sampleRate(), 'tail trimmer' => $this->tailTrimmer->sampleRate] as $part => $rate) {
            if ($rate !== $sampleRate) {
                throw new \InvalidArgumentException("voice chain at $sampleRate Hz, $part at $rate Hz");
            }
        }
    }

    public function sampleRate(): int
    {
        return $this->sampleRate;
    }

    /**
     * @param list<float> $pcm mono at {@see sampleRate()}
     * @param VoiceReference|null $match base voice to steer the recording toward
     *        first (e.g. a synthetic line onto a recorded voice), before the profile
     * @param bool $trimTailBreath remove a breath behind the last word ({@see TailBreathTrimmer})
     * @param float|null $tailCutSeconds cut $pcm here instead of searching for a breath
     *        ({@see TailBreathTrimmer::cutAt()}), whatever $trimTailBreath says. It changes the
     *        output, so a render cache keys on it as well
     * @return list<float>
     */
    public function render(array $pcm, VoiceProfile $profile, ?VoiceReference $match = null, bool $trimTailBreath = true, ?float $tailCutSeconds = null): array
    {
        if ($pcm === []) {
            return [];
        }
        if ($tailCutSeconds !== null) {
            $y = $this->tailTrimmer->cutAt($pcm, $tailCutSeconds);
        } else {
            $y = $trimTailBreath ? $this->tailTrimmer->trim($pcm) : $pcm;
        }
        $y = $match === null ? $y : $this->matcher->match($y, $match);
        $y = $this->shaper->shape($y, $profile);
        if ($profile->crush > 0.0) {
            $y = $this->crusher->apply($y, $profile->crush);
        }
        if ($profile->vocoderHz !== null) {
            $vocoder = new Vocoder($profile->vocoderHz, $profile->vocoderDry, sampleRate: $this->sampleRate, carrier: $this->carrier);
            $y = $vocoder->process($y);
        }
        return $this->normalise($y);
    }

    /**
     * @param list<float> $y
     * @return list<float>
     */
    private function normalise(array $y): array
    {
        $lufs = Loudness::integratedMono($y, $this->sampleRate);
        $peak = 0.0;
        foreach ($y as $v) {
            $a = abs($v);
            if ($a > $peak) {
                $peak = $a;
            }
        }
        if ($lufs <= -70.0 || $peak <= 0.0) {
            return $y;
        }
        $gain = min(10.0 ** ((self::TARGET_LUFS - $lufs) / 20.0), self::PEAK_CEILING / $peak);
        foreach ($y as $i => $v) {
            $y[$i] = $v * $gain;
        }
        return $y;
    }
}
