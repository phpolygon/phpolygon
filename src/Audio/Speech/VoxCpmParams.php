<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

/**
 * The fine controls of a VoxCPM2 voice ({@see VoxCpmSynthesizer}): guidance and
 * steps of the diffusion head, token limits, the bad-take retry, text
 * normalisation, Voice Design (`description`), the transcript of the reference
 * (`referenceText`, "ultimate" cloning) and the reference excerpt. Every field
 * null = the model's default. Defaults and ranges are those of voxcpm 2.0.3.
 *
 * No exaggeration and no cfgWeight: those are Chatterbox's. The seed lives on the
 * voice ({@see SpeechVoice}). There is no speaking-rate control either; a
 * description like "speaking slowly" steers the delivery, `profile.tempo` changes
 * it afterwards.
 */
final readonly class VoxCpmParams extends SynthesizerParams
{
    /** @var list<string> */
    public const array VOICE_FIELDS = ['seed'];

    /** @var list<string> */
    public const array REFERENCE_FIELDS = ['referenceText', 'referenceStart', 'referenceLength'];

    /**
     * @var array<string, array{type: string, default: int|float|bool|string|null, min: int|float|null, max: int|float|null, step: int|float|null, group: string, worker: string, description: string}>
     */
    public const array FIELDS = [
        'seed' => [
            'type' => self::TYPE_INT, 'default' => 0, 'min' => 0, 'max' => 4294967295, 'step' => 1,
            'group' => self::GROUP_VOICE, 'worker' => 'seed',
            'description' => 'Random seed of a line; the same seed, text, description and settings give the same take on the same device and dtype.',
        ],
        'description' => [
            'type' => self::TYPE_STRING, 'default' => null, 'min' => null, 'max' => null, 'step' => null,
            'group' => self::GROUP_VOICE, 'worker' => 'description',
            'description' => 'Voice Design: a voice described in words ("warm female voice, about 30, calm, clear"). Without a reference it creates a voice; with one it steers the style. A designed voice differs from line to line - design a reference once (speech:design) and clone it.',
        ],
        'cfgValue' => [
            'type' => self::TYPE_FLOAT, 'default' => 2.0, 'min' => 0.5, 'max' => 10.0, 'step' => 0.1,
            'group' => self::GROUP_SAMPLING, 'worker' => 'cfg_value',
            'description' => 'Classifier-free guidance of the diffusion head: higher follows the reference and description more closely, too high sounds strained.',
        ],
        'inferenceTimesteps' => [
            'type' => self::TYPE_INT, 'default' => 10, 'min' => 1, 'max' => 100, 'step' => 1,
            'group' => self::GROUP_DECODER, 'worker' => 'inference_timesteps',
            'description' => 'Diffusion steps per audio patch; more is cleaner and slower, fewer is faster and rougher.',
        ],
        'maxLen' => [
            'type' => self::TYPE_INT, 'default' => 4096, 'min' => 1, 'max' => 8192, 'step' => 64,
            'group' => self::GROUP_SAMPLING, 'worker' => 'max_len',
            'description' => 'Upper limit of audio tokens per line (6.25 per second); a longer line is cut off.',
        ],
        'minLen' => [
            'type' => self::TYPE_INT, 'default' => 2, 'min' => 0, 'max' => 4096, 'step' => 1,
            'group' => self::GROUP_SAMPLING, 'worker' => 'min_len',
            'description' => 'Lower limit of audio tokens per line.',
        ],
        'retryBadcase' => [
            'type' => self::TYPE_BOOL, 'default' => true, 'min' => null, 'max' => null, 'step' => null,
            'group' => self::GROUP_SAMPLING, 'worker' => 'retry_badcase',
            'description' => 'Generate a take again when its audio is far longer than the text (a run-on take).',
        ],
        'retryBadcaseMaxTimes' => [
            'type' => self::TYPE_INT, 'default' => 3, 'min' => 1, 'max' => 20, 'step' => 1,
            'group' => self::GROUP_SAMPLING, 'worker' => 'retry_badcase_max_times',
            'description' => 'Takes per line at most when retryBadcase is on.',
        ],
        'retryBadcaseRatioThreshold' => [
            'type' => self::TYPE_FLOAT, 'default' => 6.0, 'min' => 1.0, 'max' => 50.0, 'step' => 0.5,
            'group' => self::GROUP_SAMPLING, 'worker' => 'retry_badcase_ratio_threshold',
            'description' => 'Audio tokens per text token above which a take counts as run-on; raise it for slow speech.',
        ],
        'normalize' => [
            'type' => self::TYPE_BOOL, 'default' => false, 'min' => null, 'max' => null, 'step' => null,
            'group' => self::GROUP_SAMPLING, 'worker' => 'normalize',
            'description' => 'Spell out numbers, dates and symbols before speaking (the model\'s normaliser: Chinese and English).',
        ],
        'referenceText' => [
            'type' => self::TYPE_STRING, 'default' => null, 'min' => null, 'max' => null, 'step' => null,
            'group' => self::GROUP_REFERENCE, 'worker' => 'reference_text',
            'description' => 'The exact transcript of the reference (of its excerpt): the WAV then also serves as a continuation prompt - closest timbre and style ("ultimate cloning").',
        ],
        'referenceStart' => [
            'type' => self::TYPE_FLOAT, 'default' => 0.0, 'min' => 0.0, 'max' => 3600.0, 'step' => 0.1,
            'group' => self::GROUP_REFERENCE, 'worker' => 'reference_start',
            'description' => 'Seconds of the reference WAV skipped before the voice is cloned from it.',
        ],
        'referenceLength' => [
            'type' => self::TYPE_FLOAT, 'default' => null, 'min' => 0.5, 'max' => 3600.0, 'step' => 0.1,
            'group' => self::GROUP_REFERENCE, 'worker' => 'reference_length',
            'description' => 'Seconds of the reference WAV used from referenceStart on; empty = to the end.',
        ],
        'trimSilence' => [
            'type' => self::TYPE_BOOL, 'default' => false, 'min' => null, 'max' => null, 'step' => null,
            'group' => self::GROUP_OUTPUT, 'worker' => 'trim_silence',
            'description' => 'Cut leading and trailing silence of the generated audio (speech:design turns it on).',
        ],
    ];

    public function __construct(
        public ?string $description = null,
        public ?float $cfgValue = null,
        public ?int $inferenceTimesteps = null,
        public ?int $maxLen = null,
        public ?int $minLen = null,
        public ?bool $retryBadcase = null,
        public ?int $retryBadcaseMaxTimes = null,
        public ?float $retryBadcaseRatioThreshold = null,
        public ?bool $normalize = null,
        public ?string $referenceText = null,
        public ?float $referenceStart = null,
        public ?float $referenceLength = null,
        public ?bool $trimSilence = null,
    ) {
        foreach (['description' => $description, 'referenceText' => $referenceText] as $name => $text) {
            if ($text !== null && trim($text) === '') {
                throw new \InvalidArgumentException("params.{$name} must not be empty");
            }
        }
        $this->validate();
    }
}
