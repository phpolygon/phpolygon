<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

/**
 * A voice described in words, spoken once as a reference recording
 * ({@see VoxCpmSynthesizer::design()}): the description ("warm female voice,
 * about 30, calm, clear"), a text in the target language and the locale. The
 * result is a WAV to clone from (VoxCPM2 or Chatterbox), so every line of a voice
 * keeps the same timbre – a description alone yields a slightly different voice
 * for every line.
 *
 * Same description, text, seed, params, model version, device and dtype = the
 * same voice. For a 10–15 s reference the text needs about 25–40 words (CJK/Thai:
 * 60–90 characters) of natural, varied sentences.
 */
final readonly class VoiceDesign
{
    /** Length of a good cloning reference, in seconds. */
    public const float MIN_SECONDS = 10.0;
    public const float MAX_SECONDS = 15.0;

    /**
     * @param ?VoxCpmParams $params further controls (cfgValue, inferenceTimesteps, …);
     *                              description and trimSilence are set by the design itself
     */
    public function __construct(
        public string $description,
        public string $text,
        public string $locale,
        public ?VoxCpmParams $params = null,
    ) {
        if (trim($description) === '') {
            throw new \InvalidArgumentException('voice design: the description must not be empty');
        }
        if (trim($text) === '') {
            throw new \InvalidArgumentException('voice design: the text must not be empty');
        }
        if ($params?->usesReference()) {
            throw new \InvalidArgumentException('voice design: ' . implode(', ', VoxCpmParams::REFERENCE_FIELDS) . ' need a reference; a designed voice has none');
        }
    }

    /** The params of the design job: the given ones with description and trimmed silence. */
    public function jobParams(): VoxCpmParams
    {
        return VoxCpmParams::fromArray(['trimSilence' => true, ...($this->params?->toArray() ?? []), 'description' => $this->description]);
    }

    /**
     * The file of one take: $out itself for a single seed, else `name-s{seed}.ext`.
     *
     * @param list<int> $seeds
     */
    public static function takePath(string $out, int $seed, array $seeds): string
    {
        if (count($seeds) <= 1) {
            return $out;
        }
        $dot = strrpos($out, '.');
        $slash = max((int) strrpos($out, '/'), (int) strrpos($out, '\\'));
        if ($dot === false || $dot < $slash) {
            return "{$out}-s{$seed}";
        }
        return substr($out, 0, $dot) . "-s{$seed}" . substr($out, $dot);
    }
}
