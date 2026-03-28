<?php

declare(strict_types=1);

namespace PHPolygon\Thread\Subsystem;

use PHPolygon\ECS\World;
use PHPolygon\Thread\SubsystemInterface;

/**
 * Non-blocking asset loading subsystem.
 *
 * Unlike other subsystems, this does NOT follow the frame-synchronous pattern.
 * It uses async channels: main thread queues load requests, worker thread
 * loads file data, main thread polls for results and uploads to GPU.
 *
 * GPU upload (texture creation) MUST happen on the main thread since the
 * OpenGL context is thread-local.
 *
 * Request format: ['type' => 'texture'|'audio', 'id' => string, 'path' => string]
 * Result format:  ['id' => string, 'type' => string, 'width' => int, 'height' => int, 'channels' => int, 'data' => string]
 */
class AssetStreamingSubsystem implements SubsystemInterface
{
    /** @var list<array{type: string, id: string, path: string}> */
    private array $pendingRequests = [];

    /**
     * Queue an asset load request.
     */
    public function requestLoad(string $type, string $id, string $path): void
    {
        $this->pendingRequests[] = ['type' => $type, 'id' => $id, 'path' => $path];
    }

    public function prepareInput(World $world, float $dt): array
    {
        $requests = $this->pendingRequests;
        $this->pendingRequests = [];
        return ['requests' => $requests];
    }

    public function applyDeltas(World $world, array $deltas): void
    {
        // Results are polled via getLoadedResults() — not applied to World directly.
        // The TextureManager handles GPU upload on the main thread.
    }

    /**
     * @param array<string, mixed> $deltas
     * @return list<array<string, mixed>>
     */
    public function getLoadedResults(array $deltas): array
    {
        /** @var list<array<string, mixed>> */
        return $deltas['results'] ?? [];
    }

    public static function threadEntry(string $channelPrefix): void
    {
        $in = \parallel\Channel::open("{$channelPrefix}_in");
        $out = \parallel\Channel::open("{$channelPrefix}_out");

        while (true) {
            $input = $in->recv();
            if (!is_array($input)) {
                break;
            }
            /** @var array<string, mixed> $input */
            $out->send(self::compute($input));
        }
    }

    public static function compute(array $input): array
    {
        /** @var list<array{type: string, id: string, path: string}> $requests */
        $requests = $input['requests'] ?? [];
        $results = [];

        foreach ($requests as $request) {
            $result = self::loadAsset($request);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        return ['results' => $results];
    }

    /**
     * @param array{type: string, id: string, path: string} $request
     * @return array<string, mixed>|null
     */
    private static function loadAsset(array $request): ?array
    {
        $path = $request['path'];
        if (!file_exists($path)) {
            return null;
        }

        if ($request['type'] === 'texture') {
            return self::loadTextureData($request['id'], $path);
        }

        if ($request['type'] === 'audio') {
            $data = file_get_contents($path);
            if ($data === false) {
                return null;
            }
            return [
                'id' => $request['id'],
                'type' => 'audio',
                'data' => $data,
                'size' => strlen($data),
            ];
        }

        return null;
    }

    /**
     * Load image file data using GD (available in worker thread, no GPU needed).
     *
     * @return array<string, mixed>|null
     */
    private static function loadTextureData(string $id, string $path): ?array
    {
        $img = @imagecreatefrompng($path);
        if ($img === false) {
            $img = @imagecreatefromjpeg($path);
        }
        if ($img === false) {
            // Fallback: return raw file data
            $data = file_get_contents($path);
            if ($data === false) {
                return null;
            }
            return [
                'id' => $id,
                'type' => 'texture',
                'raw' => true,
                'data' => $data,
            ];
        }

        $w = imagesx($img);
        $h = imagesy($img);

        // Extract raw RGBA pixel data
        $data = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($img, $x, $y);
                $a = ($rgba >> 24) & 0x7F;
                $data .= chr(($rgba >> 16) & 0xFF)
                    . chr(($rgba >> 8) & 0xFF)
                    . chr($rgba & 0xFF)
                    . chr(255 - $a * 2); // GD alpha 0=opaque, 127=transparent → GL 255=opaque, 0=transparent
            }
        }
        imagedestroy($img);

        return [
            'id' => $id,
            'type' => 'texture',
            'raw' => false,
            'width' => $w,
            'height' => $h,
            'channels' => 4,
            'data' => $data,
        ];
    }
}
