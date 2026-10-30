<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPolygon\Rendering\Quality\LazyUpscalerProbe;
use PHPolygon\Rendering\Quality\UpscalerProbeCache;
use PHPolygon\Rendering\Quality\UpscalerProbeResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The provider query is a counted fake here: what matters is when it runs.
 * VioUpscalerProbeTest does the same against the real provider.
 */
final class LazyUpscalerProbeTest extends TestCase
{
    private const ID = 'dlss@d3d12';
    private const DEVICE = 'd3d12|4318:7815|GPU|1.2.3|vio 2.38.1';

    private string $dir;
    private string $file;
    private string $library;
    private int $asked = 0;
    private int $keyed = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpolygon-probe-' . uniqid();
        mkdir($this->dir);
        $this->file = $this->dir . '/nested/upscaler-probe.json';
        $this->library = $this->dir . '/plugin.dll';
        file_put_contents($this->library, 'plugin');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @rmdir(dirname($this->file));
        @unlink($this->library);
        @rmdir($this->dir);
    }

    private function probe(?UpscalerProbeResult $answer = null, ?string $device = self::DEVICE, bool $cache = true): LazyUpscalerProbe
    {
        $answer ??= new UpscalerProbeResult(true, '', [$this->library]);
        return new LazyUpscalerProbe(
            self::ID,
            function () use ($answer): UpscalerProbeResult {
                $this->asked++;
                return $answer;
            },
            function () use ($device): ?string {
                $this->keyed++;
                return $device;
            },
            $cache ? new UpscalerProbeCache($this->file) : null,
        );
    }

    public function testNothingRunsUntilSomebodyAsks(): void
    {
        $probe = $this->probe();

        $this->assertNull($probe->known());
        $this->assertFalse($probe->isSettledForUse());
        $this->assertSame(0, $this->asked);
        $this->assertSame(0, $this->keyed, 'not even the adapter list');
        $this->assertFileDoesNotExist($this->file);
    }

    public function testAProcessAsksOnceAndTheNextOneReadsTheDisk(): void
    {
        $first = $this->probe(new UpscalerProbeResult(false, 'driver too old', [$this->library]));
        $this->assertSame('driver too old', $first->settle()->reason);
        $first->settle();
        $this->assertSame(1, $this->asked);

        $second = $this->probe();
        $this->assertNull($second->known(), 'the disk is read on demand as well');
        $answer = $second->settle();
        $this->assertFalse($answer->supported);
        $this->assertSame('driver too old', $answer->reason);
        $this->assertSame(1, $this->asked, 'no second query');
    }

    public function testFromCacheNeverAsksTheProvider(): void
    {
        $probe = $this->probe();
        $this->assertNull($probe->fromCache());
        $this->assertNull($probe->fromCache());
        $this->assertSame(0, $this->asked);
        $this->assertSame(1, $this->keyed, 'one look at the disk per process');
    }

    public function testUseAsksDespiteAStoredYes(): void
    {
        $this->probe()->settle();
        $this->assertSame(1, $this->asked);

        $probe = $this->probe();
        $this->assertTrue($probe->settle()->supported);
        $this->assertSame(1, $this->asked);
        $this->assertFalse($probe->isSettledForUse(), 'it is about to run and has to initialise');

        $probe->settleForUse();
        $this->assertSame(2, $this->asked);
        $this->assertTrue($probe->isSettledForUse());
        $probe->settleForUse();
        $this->assertSame(2, $this->asked);
    }

    public function testUseTrustsAStoredNo(): void
    {
        $this->probe(new UpscalerProbeResult(false, 'not an RTX GPU', [$this->library]))->settle();

        $probe = $this->probe();
        $this->assertSame('not an RTX GPU', $probe->settleForUse()->reason);
        $this->assertTrue($probe->isSettledForUse());
        $this->assertSame(1, $this->asked);
    }

    public function testALiveAnswerReplacesTheStoredOne(): void
    {
        $this->probe()->settle();
        $this->assertFalse($this->probe(new UpscalerProbeResult(false, 'gone', [$this->library]))->settleForUse()->supported);

        $this->assertSame('gone', (new UpscalerProbeCache($this->file))->read(self::ID, self::DEVICE)?->reason);
    }

    /** @return iterable<string, array{0: string}> */
    public static function brokenFiles(): iterable
    {
        yield 'not JSON' => ['{"format":1,'];
        yield 'empty' => [''];
        yield 'a list' => ['[1, 2]'];
        yield 'other format' => ['{"format":999,"entries":{"dlss@d3d12":{"device":"' . self::DEVICE . '","supported":true,"reason":"","files":{}}}}'];
        yield 'no entries' => ['{"format":1}'];
        yield 'supported is a string' => ['{"format":1,"entries":{"dlss@d3d12":{"device":"' . self::DEVICE . '","supported":"yes","reason":"","files":{}}}}'];
        yield 'no reason' => ['{"format":1,"entries":{"dlss@d3d12":{"device":"' . self::DEVICE . '","supported":true,"files":{}}}}'];
        yield 'files is a string' => ['{"format":1,"entries":{"dlss@d3d12":{"device":"' . self::DEVICE . '","supported":true,"reason":"","files":"x"}}}'];
        yield 'entry is a string' => ['{"format":1,"entries":{"dlss@d3d12":"x"}}'];
    }

    #[DataProvider('brokenFiles')]
    public function testABrokenEntryIsProbedAgainAndRewritten(string $content): void
    {
        mkdir(dirname($this->file));
        file_put_contents($this->file, $content);

        $this->assertTrue($this->probe()->settle()->supported);
        $this->assertSame(1, $this->asked);
        $this->assertNotNull((new UpscalerProbeCache($this->file))->read(self::ID, self::DEVICE), 'healed');
    }

    public function testAnotherDriverOrPhpVioVersionProbesAgain(): void
    {
        $this->probe()->settle();
        $this->probe(device: 'd3d12|4318:7815|GPU|1.2.4|vio 2.38.1')->settle();
        $this->assertSame(2, $this->asked, 'driver');
        $this->probe(device: 'd3d12|4318:7815|GPU|1.2.4|vio 2.39.0')->settle();
        $this->assertSame(3, $this->asked, 'php-vio');
        $this->probe(device: 'd3d12|4318:7815|GPU|1.2.4|vio 2.39.0')->settle();
        $this->assertSame(3, $this->asked, 'the last one is stored');
    }

    public function testAChangedOrRemovedRuntimeLibraryProbesAgain(): void
    {
        $this->probe()->settle();
        file_put_contents($this->library, 'a newer plugin');
        $this->probe()->settle();
        $this->assertSame(2, $this->asked, 'changed');

        $this->probe()->settle();
        $this->assertSame(2, $this->asked);
        unlink($this->library);
        $this->probe()->settle();
        $this->assertSame(3, $this->asked, 'removed');
    }

    public function testEntriesOfOtherBackendsStay(): void
    {
        $this->probe()->settle();
        $cache = new UpscalerProbeCache($this->file);
        $cache->write('dlss@vulkan', 'vulkan|device', new UpscalerProbeResult(false, 'other'));

        $this->assertNotNull($cache->read(self::ID, self::DEVICE));
        $this->assertSame('other', $cache->read('dlss@vulkan', 'vulkan|device')?->reason);
    }

    public function testAnAnswerWithoutTheProviderIsNotStored(): void
    {
        $probe = $this->probe(new UpscalerProbeResult(false, 'vio_dlss.dll not found', cacheable: false));

        $this->assertFalse($probe->settle()->supported);
        $this->assertFileDoesNotExist($this->file, 'the next build may ship the plugin');
        $this->assertTrue($probe->isSettledForUse());
    }

    public function testWithoutADeviceKeyThereIsNoDiskButStillOneQueryPerProcess(): void
    {
        // vio_adapters() missing or the adapter not listed.
        $probe = $this->probe(device: null);

        $probe->settle();
        $probe->settle();
        $probe->settleForUse();
        $this->assertSame(1, $this->asked);
        $this->assertFileDoesNotExist($this->file);
    }

    public function testWithoutACachePathTheDeviceIsNotEvenLookedUp(): void
    {
        $probe = $this->probe(cache: false);

        $this->assertTrue($probe->settle()->supported);
        $this->assertSame(1, $this->asked);
        $this->assertSame(0, $this->keyed);
    }

    public function testAnUnwritableCacheIsNoError(): void
    {
        $cache = new UpscalerProbeCache($this->library . '/cannot/be/a/directory.json');

        $cache->write(self::ID, self::DEVICE, new UpscalerProbeResult(true));
        $this->assertNull($cache->read(self::ID, self::DEVICE));
    }

    public function testDeviceKeyNamesDeviceDriverAndVersion(): void
    {
        $adapters = [
            ['name' => 'Other GPU', 'vendor_id' => 1, 'device_id' => 2, 'driver' => '9'],
            ['name' => 'GPU', 'vendor_id' => 4318, 'device_id' => 7815, 'driver' => '1.2.3'],
        ];

        $this->assertSame(self::DEVICE, UpscalerProbeCache::deviceKey('d3d12', 'GPU', $adapters, '2.38.1'));
        $this->assertNotSame(self::DEVICE, UpscalerProbeCache::deviceKey('d3d12', 'Other GPU', $adapters, '2.38.1'));
    }

    public function testDeviceKeyRefusesWhatItCannotName(): void
    {
        $adapters = [['name' => 'GPU', 'vendor_id' => 4318, 'device_id' => 7815, 'driver' => '']];

        $this->assertNull(UpscalerProbeCache::deviceKey('d3d12', 'GPU', $adapters, '2.38.1'), 'no driver version');
        $this->assertNull(UpscalerProbeCache::deviceKey('d3d12', 'Unlisted', $adapters, '2.38.1'));
        $this->assertNull(UpscalerProbeCache::deviceKey('d3d12', '', [['name' => '', 'driver' => '1']], '2.38.1'));
        $this->assertNull(UpscalerProbeCache::deviceKey('d3d12', 'GPU', [], '2.38.1'));
    }
}
