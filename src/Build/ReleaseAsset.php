<?php

declare(strict_types=1);

namespace PHPolygon\Build;

/**
 * One asset of a GitHub release as the release API describes it. Its identity is
 * what decides whether a cached copy is still the published one
 * ({@see ReleaseAssetCache}).
 */
final class ReleaseAsset
{
    public function __construct(
        public readonly string $name,
        public readonly int $id,
        public readonly string $updatedAt,
        /** `sha256:<hex>` of the asset, null when the API reports none. */
        public readonly ?string $digest = null,
        public readonly ?int $size = null,
        public readonly string $downloadUrl = '',
    ) {}

    /**
     * The assets of a release response; entries without a name or an id are skipped.
     *
     * @param array<mixed> $release GitHub release JSON
     * @return list<self>
     */
    public static function fromRelease(array $release): array
    {
        $assets = [];
        foreach (is_array($release['assets'] ?? null) ? $release['assets'] : [] as $asset) {
            if (!is_array($asset) || !is_string($asset['name'] ?? null) || !is_int($asset['id'] ?? null)) {
                continue;
            }
            $assets[] = new self(
                $asset['name'],
                $asset['id'],
                is_string($asset['updated_at'] ?? null) ? $asset['updated_at'] : '',
                is_string($asset['digest'] ?? null) && $asset['digest'] !== '' ? strtolower($asset['digest']) : null,
                is_int($asset['size'] ?? null) ? $asset['size'] : null,
                is_string($asset['browser_download_url'] ?? null) ? $asset['browser_download_url'] : '',
            );
        }
        return $assets;
    }

    /** Short form for the build log. */
    public function describe(): string
    {
        $parts = ["asset {$this->id}"];
        if ($this->updatedAt !== '') {
            $parts[] = "updated {$this->updatedAt}";
        }
        if ($this->digest !== null) {
            $parts[] = substr($this->digest, 0, 19);
        }
        return implode(', ', $parts);
    }
}
