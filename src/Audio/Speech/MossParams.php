<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

/**
 * The fine controls of a MOSS-TTS v1.5 voice ({@see MossTtsSynthesizer}): sampling of
 * the audio tokens and of the text channel, the frame limit, duration control, the
 * transcript of the reference (`referenceText`: cloning with continuation) and the
 * reference excerpt. Every field null = the model's default (those of the model's
 * own generate() and demo app).
 *
 * No exaggeration, no cfgWeight, no description: MOSS-TTS clones from a reference.
 * The seed lives on the voice ({@see SpeechVoice}). Pauses go into the text itself
 * (`[pause 1.5s]`); `profile.tempo` changes the pace afterwards.
 */
final readonly class MossParams extends SynthesizerParams
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
            'description' => 'Random seed of a line; the same seed, text, reference and settings give the same take on the same device, dtype and quantisation.',
        ],
        'audioTemperature' => [
            'type' => self::TYPE_FLOAT, 'default' => 1.7, 'min' => 0.1, 'max' => 3.0, 'step' => 0.05,
            'group' => self::GROUP_SAMPLING, 'worker' => 'audio_temperature',
            'description' => 'Randomness of the audio tokens: lower is steadier and flatter, higher livelier and less stable.',
        ],
        'audioTopP' => [
            'type' => self::TYPE_FLOAT, 'default' => 0.8, 'min' => 0.1, 'max' => 1.0, 'step' => 0.01,
            'group' => self::GROUP_SAMPLING, 'worker' => 'audio_top_p',
            'description' => 'Nucleus sampling of the audio tokens: the smallest set of tokens whose probability reaches this share.',
        ],
        'audioTopK' => [
            'type' => self::TYPE_INT, 'default' => 25, 'min' => 1, 'max' => 200, 'step' => 1,
            'group' => self::GROUP_SAMPLING, 'worker' => 'audio_top_k',
            'description' => 'Audio tokens considered per step at most.',
        ],
        'audioRepetitionPenalty' => [
            'type' => self::TYPE_FLOAT, 'default' => 1.0, 'min' => 0.8, 'max' => 2.0, 'step' => 0.05,
            'group' => self::GROUP_SAMPLING, 'worker' => 'audio_repetition_penalty',
            'description' => 'Penalty on audio tokens that already occurred; above 1 counters stuck, repeating sounds.',
        ],
        'textTemperature' => [
            'type' => self::TYPE_FLOAT, 'default' => 1.5, 'min' => 0.0, 'max' => 3.0, 'step' => 0.05,
            'group' => self::GROUP_SAMPLING, 'worker' => 'text_temperature',
            'description' => 'Randomness of the text channel (where the audio starts and ends); 0 = greedy.',
        ],
        'textTopP' => [
            'type' => self::TYPE_FLOAT, 'default' => 1.0, 'min' => 0.1, 'max' => 1.0, 'step' => 0.01,
            'group' => self::GROUP_SAMPLING, 'worker' => 'text_top_p',
            'description' => 'Nucleus sampling of the text channel.',
        ],
        'textTopK' => [
            'type' => self::TYPE_INT, 'default' => 50, 'min' => 1, 'max' => 200, 'step' => 1,
            'group' => self::GROUP_SAMPLING, 'worker' => 'text_top_k',
            'description' => 'Text tokens considered per step at most.',
        ],
        'maxNewTokens' => [
            'type' => self::TYPE_INT, 'default' => 4096, 'min' => 64, 'max' => 8192, 'step' => 64,
            'group' => self::GROUP_SAMPLING, 'worker' => 'max_new_tokens',
            'description' => 'Upper limit of audio frames per line (12.5 per second); a longer line is cut off, a run-on take stops here.',
        ],
        'durationTokens' => [
            'type' => self::TYPE_INT, 'default' => null, 'min' => 1, 'max' => 8192, 'step' => 1,
            'group' => self::GROUP_SAMPLING, 'worker' => 'duration_tokens',
            'description' => 'Duration control: the expected length of the line in audio frames (12.5 per second); empty = the model decides. Not together with referenceText.',
        ],
        'referenceText' => [
            'type' => self::TYPE_STRING, 'default' => null, 'min' => null, 'max' => null, 'step' => null,
            'group' => self::GROUP_REFERENCE, 'worker' => 'reference_text',
            'description' => 'The exact transcript of the reference (of its excerpt): the reference then also opens the answer and the line continues it - closest timbre and style.',
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
            'description' => 'Cut leading and trailing silence of the generated audio.',
        ],
    ];

    public function __construct(
        public ?float $audioTemperature = null,
        public ?float $audioTopP = null,
        public ?int $audioTopK = null,
        public ?float $audioRepetitionPenalty = null,
        public ?float $textTemperature = null,
        public ?float $textTopP = null,
        public ?int $textTopK = null,
        public ?int $maxNewTokens = null,
        public ?int $durationTokens = null,
        public ?string $referenceText = null,
        public ?float $referenceStart = null,
        public ?float $referenceLength = null,
        public ?bool $trimSilence = null,
    ) {
        if ($referenceText !== null && trim($referenceText) === '') {
            throw new \InvalidArgumentException('params.referenceText must not be empty');
        }
        if ($referenceText !== null && $durationTokens !== null) {
            throw new \InvalidArgumentException('params.durationTokens cannot be combined with params.referenceText (the model has no duration control when it continues the reference)');
        }
        $this->validate();
    }
}
