<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

/**
 * The fine controls of a voice beyond seed, exaggeration and cfg weight: how
 * speech tokens are sampled, how the flow-matching decoder runs, whether the
 * output is watermarked and which part of the reference WAV the voice is
 * cloned from. Every field null = the model's own default, so a voice without
 * params sounds (and caches) exactly as before.
 *
 * {@see self::FIELDS} is the one source of the ranges: the constructor, build.json
 * parsing ({@see SpeechConfig}) and the editor schema ({@see self::schema()}) all
 * read it. seed, exaggeration and cfgWeight live on {@see SpeechVoice} /
 * {@see SpeechJob} (older API) but are listed in the schema as well.
 *
 * Defaults and ranges are those of Chatterbox Multilingual 0.1.7. The model has
 * no speaking-rate control (its `speed` is an unimplemented TODO); change the
 * tempo afterwards with {@see \PHPolygon\Audio\Dsp\Voice\VoiceProfile} `tempo`.
 */
final readonly class SpeechParams extends SynthesizerParams
{
    /**
     * Fields of the voice itself ({@see SpeechVoice}), not of the params object.
     *
     * @var list<string>
     */
    public const array VOICE_FIELDS = ['seed', 'exaggeration', 'cfgWeight'];

    /**
     * Fields that only act on a cloned voice (a reference WAV), not on the built-in one.
     *
     * @var list<string>
     */
    public const array REFERENCE_FIELDS = ['referenceStart', 'referenceLength', 'promptSeconds', 'voiceSeconds'];

    /**
     * Every parameter: type, model default (null = none, e.g. "the whole file"),
     * range, editor step, group, the worker's job field and a description.
     *
     * @var array<string, array{type: string, default: int|float|bool|string|null, min: int|float|null, max: int|float|null, step: int|float|null, group: string, worker: string, description: string}>
     */
    public const array FIELDS = [
        'seed' => [
            'type' => self::TYPE_INT, 'default' => 0, 'min' => 0, 'max' => 4294967295, 'step' => 1,
            'group' => self::GROUP_VOICE, 'worker' => 'seed',
            'description' => 'Random seed of a line; the same seed, text and settings give the same take on the same device.',
        ],
        'exaggeration' => [
            'type' => self::TYPE_FLOAT, 'default' => 0.5, 'min' => 0.0, 'max' => 2.0, 'step' => 0.05,
            'group' => self::GROUP_VOICE, 'worker' => 'exaggeration',
            'description' => 'Emotional intensity; 0.5 is neutral, higher is more expressive and usually faster.',
        ],
        'cfgWeight' => [
            'type' => self::TYPE_FLOAT, 'default' => 0.5, 'min' => 0.0, 'max' => 1.0, 'step' => 0.05,
            'group' => self::GROUP_VOICE, 'worker' => 'cfg_weight',
            'description' => 'Classifier-free guidance: how closely the delivery follows the reference; lower is slower and more deliberate, 0 ignores the reference accent.',
        ],
        'temperature' => [
            'type' => self::TYPE_FLOAT, 'default' => 0.8, 'min' => 0.05, 'max' => 2.0, 'step' => 0.05,
            'group' => self::GROUP_SAMPLING, 'worker' => 'temperature',
            'description' => 'Sampling temperature of the speech tokens; lower keeps the takes of a voice closer to each other, higher varies more.',
        ],
        'topP' => [
            'type' => self::TYPE_FLOAT, 'default' => 1.0, 'min' => 0.01, 'max' => 1.0, 'step' => 0.01,
            'group' => self::GROUP_SAMPLING, 'worker' => 'top_p',
            'description' => 'Nucleus sampling: only the most likely tokens up to this probability mass; 1 disables it.',
        ],
        'minP' => [
            'type' => self::TYPE_FLOAT, 'default' => 0.05, 'min' => 0.0, 'max' => 1.0, 'step' => 0.01,
            'group' => self::GROUP_SAMPLING, 'worker' => 'min_p',
            'description' => 'Drops tokens less likely than this fraction of the most likely one; 0 disables it.',
        ],
        'repetitionPenalty' => [
            'type' => self::TYPE_FLOAT, 'default' => 2.0, 'min' => 1.0, 'max' => 3.0, 'step' => 0.05,
            'group' => self::GROUP_SAMPLING, 'worker' => 'repetition_penalty',
            'description' => 'Penalty on speech tokens already generated; 1 disables it, higher fights stutter and loops.',
        ],
        'maxNewTokens' => [
            'type' => self::TYPE_INT, 'default' => 1000, 'min' => 1, 'max' => 4096, 'step' => 25,
            'group' => self::GROUP_SAMPLING, 'worker' => 'max_new_tokens',
            'description' => 'Upper limit of speech tokens per line (25 per second, so 1000 = 40 s); a longer line is cut off.',
        ],
        'cfmSteps' => [
            'type' => self::TYPE_INT, 'default' => 10, 'min' => 1, 'max' => 50, 'step' => 1,
            'group' => self::GROUP_DECODER, 'worker' => 'cfm_steps',
            'description' => 'Flow-matching steps of the decoder that turns tokens into audio; fewer is faster and rougher.',
        ],
        'referenceStart' => [
            'type' => self::TYPE_FLOAT, 'default' => 0.0, 'min' => 0.0, 'max' => 3600.0, 'step' => 0.1,
            'group' => self::GROUP_REFERENCE, 'worker' => 'reference_start',
            'description' => 'Seconds of the reference WAV skipped before the voice is cloned from it.',
        ],
        'referenceLength' => [
            'type' => self::TYPE_FLOAT, 'default' => null, 'min' => 0.5, 'max' => 3600.0, 'step' => 0.1,
            'group' => self::GROUP_REFERENCE, 'worker' => 'reference_length',
            'description' => 'Seconds of the reference WAV used from referenceStart on; empty = to the end. The speaker embedding hears all of it.',
        ],
        'promptSeconds' => [
            'type' => self::TYPE_FLOAT, 'default' => 6.0, 'min' => 0.5, 'max' => 6.0, 'step' => 0.5,
            'group' => self::GROUP_REFERENCE, 'worker' => 'prompt_seconds',
            'description' => 'Seconds of the reference the token model hears as its speech prompt (at most 150 tokens = 6 s).',
        ],
        'voiceSeconds' => [
            'type' => self::TYPE_FLOAT, 'default' => 10.0, 'min' => 0.5, 'max' => 10.0, 'step' => 0.5,
            'group' => self::GROUP_REFERENCE, 'worker' => 'voice_seconds',
            'description' => 'Seconds of the reference the decoder takes its timbre from (the model is trained on at most 10 s).',
        ],
        'watermark' => [
            'type' => self::TYPE_BOOL, 'default' => true, 'min' => null, 'max' => null, 'step' => null,
            'group' => self::GROUP_OUTPUT, 'worker' => 'watermark',
            'description' => 'Embed the model\'s inaudible Perth watermark; switch it off only where your own licence and disclosure policy allow it.',
        ],
    ];

    public function __construct(
        public ?float $temperature = null,
        public ?float $topP = null,
        public ?float $minP = null,
        public ?float $repetitionPenalty = null,
        public ?int $maxNewTokens = null,
        public ?int $cfmSteps = null,
        public ?float $referenceStart = null,
        public ?float $referenceLength = null,
        public ?float $promptSeconds = null,
        public ?float $voiceSeconds = null,
        public ?bool $watermark = null,
    ) {
        $this->validate();
    }
}
