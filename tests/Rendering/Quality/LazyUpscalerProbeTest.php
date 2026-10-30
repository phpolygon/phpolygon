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
    private string $runtime;
    private int $asked = 0;
    private int $keyed = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpolygon-probe-' . uniqid();
        mkdir($this->dir);
        $this->file = $this->dir . '/nested/upscaler-probe.json';
        $this->library = $this->dir . '/plugin.dll';
        $this->runtime = $this->dir . '/runtime.dll';
        file_put_contents($this->library, 'plugin');
        file_put_contents($this->runtime, 'runtime');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @rmdir(dirname($this->file));
        @unlink($this->library);
        @unlink($this->runtime);
        @rmdir($this->dir);
    }

    private function probe(?UpscalerProbeResult $answer = null, ?string $device = self::DEVICE, bool $cache = true): LazyUpscalerProbe
    {
        $answer ??= $this->accepted();
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

    /** vio_upscaler_info() as measured when NGX says yes. */
    private function accepted(): UpscalerProbeResult
    {
        return UpscalerProbeResult::fromProviderInfo(true, [
            'reason' => '', 'driver' => '621.01', 'plugin' => $this->library, 'library' => $this->runtime,
        ]);
    }

    /** As measured when NGX says no: the driver it checked, the library cleared. */
    private function refused(string $reason): UpscalerProbeResult
    {
        return UpscalerProbeResult::fromProviderInfo(false, [
            'reason' => $reason, 'driver' => '621.01', 'plugin' => $this->library, 'library' => '',
        ]);
    }

    public function testTheInfoOfARefusalIsStoredAndRestsOnThePlugin(): void
    {
        $answer = $this->refused('driver too old');

        $this->assertTrue($answer->cacheable);
        $this->assertSame([$this->library], $answer->files);
        $this->assertTrue($this->accepted()->cacheable);
        $this->assertSame([$this->library, $this->runtime], $this->accepted()->files);
    }

    public function testTheInfoOfAMissingPluginOrRuntimeIsNotStored(): void
    {
        // Measured with vio.dlss_plugin_path / vio.dlss_path on an empty directory.
        $noPlugin = ['reason' => 'vio_dlss.dll not found', 'driver' => '', 'plugin' => '', 'library' => ''];
        $noRuntime = ['reason' => 'nvngx_dlss.dll not found', 'driver' => '', 'plugin' => $this->library, 'library' => ''];

        $this->assertFalse(UpscalerProbeResult::fromProviderInfo(false, $noPlugin)->cacheable);
        $this->assertFalse(UpscalerProbeResult::fromProviderInfo(false, $noRuntime)->cacheable);
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
        $first = $this->probe($this->refused('driver too old'));
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
        $this->probe($this->refused('not an RTX GPU'))->settle();

        $probe = $this->probe();
        $this->assertSame('not an RTX GPU', $probe->settleForUse()->reason);
        $this->assertTrue($probe->isSettledForUse());
        $this->assertSame(1, $this->asked);
    }

    public function testALiveAnswerReplacesTheStoredOne(): void
    {
        $this->probe()->settle();
        $this->assertFalse($this->probe($this->refused('gone'))->settleForUse()->supported);

        $this->assertSame('gone', (new UpscalerProbeCache($this->file))->read(self::ID, self::DEVICE)?->reason);
    }

    /** @return iterable<string, array{0: string}> */
    public static function brokenFiles(): iterable
    {
        yield 'not JSON' => ['{"format":1,'];
        yield 'empty' => [''];
        yield 'a list' => ['[1, 2]'];
        $key = self::ID . '|' . self::DEVICE;
        yield 'other format' => ['{"format":999,"entries":{"' . $key . '":{"supported":true,"reason":"","files":{}}}}'];
        yield 'no entries' => ['{"format":1}'];
        yield 'supported is a string' => ['{"format":1,"entries":{"' . $key . '":{"supported":"yes","reason":"","files":{}}}}'];
        yield 'no reason' => ['{"format":1,"entries":{"' . $key . '":{"supported":true,"files":{}}}}'];
        yield 'files is a string' => ['{"format":1,"entries":{"' . $key . '":{"supported":true,"reason":"","files":"x"}}}'];
        yield 'entry is a string' => ['{"format":1,"entries":{"' . $key . '":"x"}}'];
        yield 'a stamp of nothing' => ['{"format":1,"entries":{"' . $key . '":{"supported":false,"reason":"","files":{"C:\\\\gone.dll":""}}}}'];
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
        $cache->write('dlss@vulkan', 'vulkan|device', $this->refused('other'));

        $this->assertNotNull($cache->read(self::ID, self::DEVICE));
        $this->assertSame('other', $cache->read('dlss@vulkan', 'vulkan|device')?->reason);
    }

    public function testOnlyTheNewestEntriesStay(): void
    {
        $cache = new UpscalerProbeCache($this->file);
        $count = UpscalerProbeCache::MAX_ENTRIES + 3;
        for ($i = 0; $i < $count; $i++) {
            $cache->write(self::ID, "driver {$i}", $this->refused("no {$i}"));
        }

        $this->assertNull($cache->read(self::ID, 'driver 0'), 'one old driver after the other');
        $this->assertSame('no ' . ($count - 1), $cache->read(self::ID, 'driver ' . ($count - 1))?->reason);
        $data = json_decode((string) file_get_contents($this->file), true);
        $this->assertIsArray($data);
        $this->assertIsArray($data['entries']);
        $this->assertCount(UpscalerProbeCache::MAX_ENTRIES, $data['entries']);
    }

    /** @return iterable<string, array{0: string}> */
    public static function unstampablePaths(): iterable
    {
        // php-vio reports GetModuleFileNameA in the ANSI code page: "C:\Spiele\Müller\vio_dlss.dll".
        yield 'not UTF-8' => ["C:\\Spiele\\M\xFCller\\vio_dlss.dll"];
        yield 'not there' => ['C:\\no\\such\\directory\\vio_dlss.dll'];
    }

    #[DataProvider('unstampablePaths')]
    public function testAnAnswerThatCannotBeStampedIsNotStoredAndSaysSo(string $plugin): void
    {
        $lines = [];
        $cache = new UpscalerProbeCache($this->file, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $cache->write(self::ID, 'the other GPU', $this->accepted());

        $cache->write(self::ID, self::DEVICE, UpscalerProbeResult::fromProviderInfo(true, [
            'reason' => '', 'driver' => '621.01', 'plugin' => $plugin, 'library' => $this->runtime,
        ]));

        $this->assertNull($cache->read(self::ID, self::DEVICE), 'frozen without anything to compare');
        $this->assertNotNull($cache->read(self::ID, 'the other GPU'), 'this answer only, the rest stays');
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('vio_dlss.dll', $lines[0]);
    }

    public function testAReasonThatIsNoUtf8IsStored(): void
    {
        $cache = new UpscalerProbeCache($this->file);

        $cache->write(self::ID, self::DEVICE, $this->refused("Treiber in M\xFCller zu alt"));

        $this->assertStringContainsString('Treiber', (string) $cache->read(self::ID, self::DEVICE)?->reason);
    }

    public function testTwoAdaptersOfOneMachineKeepTheirOwnAnswers(): void
    {
        // A hybrid laptop: the integrated GPU says no, the discrete one yes.
        $integrated = 'd3d12|32902:18086|Integrated|31.0|vio 2.38.1';
        $this->probe($this->refused('not an RTX GPU'), device: $integrated)->settle();
        $this->probe()->settle();
        $this->assertSame(2, $this->asked);

        $this->assertFalse($this->probe(device: $integrated)->settle()->supported);
        $this->assertTrue($this->probe()->settle()->supported);
        $this->assertSame(2, $this->asked, 'neither pushed the other out');
    }

    public function testAnAnswerWithoutTheProviderIsNotStored(): void
    {
        $probe = $this->probe(UpscalerProbeResult::fromProviderInfo(false, [
            'reason' => 'vio_dlss.dll not found', 'driver' => '', 'plugin' => '', 'library' => '',
        ]));

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

        $cache->write(self::ID, self::DEVICE, $this->accepted());
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
        $this->assertNotSame(self::DEVICE, UpscalerProbeCache::deviceKey('d3d12', 'GPU', $adapters, '2.38.1', 'project'));
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
