<?php

declare(strict_types=1);

namespace PHPolygon\Streaming;

/** What one {@see CellStreamer::update()} did. */
final readonly class StreamFrame
{
    /**
     * @param list<array{0: string, 1: int}> $requested  (cell key, lod) submitted this frame
     * @param list<array{0: string, 1: int}> $applied    (cell key, lod) installed this frame
     * @param list<string>                   $evicted    cell keys released this frame
     * @param int $droppedStale  results thrown away: wrong epoch, cell no longer wanted, or a coarser level than wanted now
     * @param int $failed        jobs that came back as an error or a timeout
     * @param int $appliedBytes  bytes of the results installed
     * @param int $elapsedNs     time spent in the whole update() call
     * @param int $outstanding   jobs still in flight or waiting to install
     */
    public function __construct(
        public int $epoch,
        public array $requested,
        public array $applied,
        public array $evicted,
        public int $droppedStale,
        public int $failed,
        public int $appliedBytes,
        public int $elapsedNs,
        public int $outstanding,
    ) {}
}
