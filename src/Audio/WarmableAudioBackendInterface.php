<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

/**
 * A backend whose {@see AudioBackendInterface::load()} only notes the file and
 * opens it at the first play (php-vio: the file is read and the decoder set up
 * inside the frame that starts the sound). {@see warm()} does that work ahead of
 * time, e.g. behind a load screen, so the first play only starts a ready sound.
 * {@see AudioManager::warm()} and {@see StemPlayer::preload()} call it.
 */
interface WarmableAudioBackendInterface extends AudioBackendInterface
{
    /**
     * Open a loaded clip now and keep it ready for the next play(s).
     *
     * @param int $copies how many ready instances to hold (one per overlapping playback that should start without opening)
     * @return bool false when the clip is unknown, its file is missing or it fails to open
     */
    public function warm(string $clipId, int $copies = 1): bool;

    /** Ready instances of a clip that have not been played yet. */
    public function warmCount(string $clipId): int;
}
