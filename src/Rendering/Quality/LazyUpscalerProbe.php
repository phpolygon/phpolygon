<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * One provider whose first query on a device blocks for seconds (DLSS: NGX
 * initialises on whatever call reaches it first, measured 4-5 s per context).
 * Nothing runs until somebody asks; then the answer comes from memory, the disk
 * cache or, at the very end, the provider.
 *
 * Two questions, because they cost differently:
 *  - {@see settle()}: does it run here? A disk answer is enough (an options menu).
 *  - {@see settleForUse()}: it is about to run. A disk "no" is enough (a refusal
 *    the provider gave after looking at the device), a disk "yes" is not: the
 *    provider has to initialise anyway, so it does it now and the answer it gives
 *    replaces the stored one.
 */
final class LazyUpscalerProbe
{
    private ?UpscalerProbeResult $result = null;
    private bool $live = false;
    private bool $cacheRead = false;
    private ?string $device = null;

    /**
     * @param \Closure(): UpscalerProbeResult $probe     The blocking provider query
     * @param \Closure(): ?string             $deviceKey Identity of the device, null = cache unusable
     */
    public function __construct(
        private readonly string $id,
        private readonly \Closure $probe,
        private readonly \Closure $deviceKey,
        private readonly ?UpscalerProbeCache $cache = null,
    ) {}

    /** What is known by now. Touches neither the disk nor the provider. */
    public function known(): ?UpscalerProbeResult
    {
        return $this->result;
    }

    /** The answer the disk holds, read once. Never asks the provider. */
    public function fromCache(): ?UpscalerProbeResult
    {
        if ($this->result !== null || $this->cacheRead || $this->cache === null) {
            return $this->result;
        }
        $this->cacheRead = true;
        $this->device = ($this->deviceKey)();
        return $this->result = $this->device !== null ? $this->cache->read($this->id, $this->device) : null;
    }

    public function settle(): UpscalerProbeResult
    {
        return $this->fromCache() ?? $this->ask();
    }

    public function settleForUse(): UpscalerProbeResult
    {
        $result = $this->fromCache();
        if ($result !== null && ($this->live || !$result->supported)) {
            return $result;
        }
        return $this->ask();
    }

    /** {@see settleForUse()} would return at once. */
    public function isSettledForUse(): bool
    {
        return $this->result !== null && ($this->live || !$this->result->supported);
    }

    private function ask(): UpscalerProbeResult
    {
        $result = ($this->probe)();
        $this->result = $result;
        $this->live = true;
        if ($this->cache !== null && $this->device !== null && $result->cacheable) {
            $this->cache->write($this->id, $this->device, $result);
        }
        return $result;
    }
}
