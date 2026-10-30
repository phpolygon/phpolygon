<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * What a native upscaler provider answered on this device.
 */
final class UpscalerProbeResult
{
    /**
     * @param string       $reason    Why not, in the provider's words ('' when supported)
     * @param list<string> $files     Runtime libraries the answer rests on
     * @param bool         $cacheable False when the provider never got to look at the
     *                                device: such an answer is cheap and changes with the
     *                                game's files, not with the machine
     */
    public function __construct(
        public readonly bool $supported,
        public readonly string $reason = '',
        public readonly array $files = [],
        public readonly bool $cacheable = true,
    ) {}

    /**
     * From vio_upscaler_info(). The provider looked at the device when it said yes
     * or names the driver it checked. A refusal carries no 'library' (php-vio clears
     * it), so such an entry rests on the plugin alone; 'driver' stays empty when the
     * plugin or the runtime library was not found.
     *
     * @param array<string, mixed> $info
     */
    public static function fromProviderInfo(bool $supported, array $info): self
    {
        $reason = $info['reason'] ?? '';
        $files = array_values(array_filter(
            [$info['plugin'] ?? '', $info['library'] ?? ''],
            static fn(mixed $file): bool => is_string($file) && $file !== '',
        ));
        return new self(
            $supported,
            is_string($reason) ? $reason : '',
            $files,
            $supported || ($info['driver'] ?? '') !== '',
        );
    }
}
