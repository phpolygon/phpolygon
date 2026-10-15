<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Speech;

use PHPolygon\Audio\Codec\WavWriter;
use PHPolygon\Audio\Dsp\Pcm;
use PHPolygon\Audio\Speech\SpeechJob;
use PHPolygon\Audio\Speech\SpeechSynthesizerInterface;

/** Speaks every job as a short tone at 24 kHz, like Chatterbox's output rate. */
final class FakeSynthesizer implements SpeechSynthesizerInterface
{
    /** @var list<SpeechJob> */
    public array $jobs = [];

    /** @param list<string> $failTexts texts reported as failed */
    public function __construct(
        public string $id = 'fake/1',
        private readonly array $failTexts = [],
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function language(string $locale): ?string
    {
        return in_array($locale, ['de', 'en'], true) ? $locale : null;
    }

    public function synthesize(array $jobs, string $workDir, callable $onResult): void
    {
        foreach ($jobs as $job) {
            $this->jobs[] = $job;
            if (in_array($job->text, $this->failTexts, true)) {
                $onResult($job, 'synthesis failed');
                continue;
            }
            $pcm = '';
            for ($i = 0; $i < 2400; $i++) {
                $pcm .= Pcm::packSample(0.3 * sin(2 * M_PI * 220 * $i / 24000));
            }
            file_put_contents($job->wavPath, WavWriter::wrap($pcm, 1, 24000));
            $onResult($job, null);
        }
    }
}
