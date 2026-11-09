<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Effects\EffectSwitches;
use PHPolygon\Rendering\GraphicsSettings;

final class EffectSwitchesTest extends TestCase
{
    public function testOnlySwitchedOffEffectsGetADefine(): void
    {
        $s = (new GraphicsSettings())->with(effects: ['water_foam' => false, 'holo_rim' => true, 'a_glow' => false]);
        self::assertSame(['PHPOLYGON_EFFECT_OFF_A_GLOW', 'PHPOLYGON_EFFECT_OFF_WATER_FOAM'], EffectSwitches::offDefines($s));
    }

    public function testNoSwitchesNoDefines(): void
    {
        self::assertSame([], EffectSwitches::offDefines(new GraphicsSettings()));
    }

    public function testNamesThatAreNoEffectNamesAreIgnored(): void
    {
        $s = (new GraphicsSettings())->with(effects: ['Bad Name' => false, 'ok' => false]);
        self::assertSame(['PHPOLYGON_EFFECT_OFF_OK'], EffectSwitches::offDefines($s));
    }

    public function testDefineIsUpperCase(): void
    {
        self::assertSame('PHPOLYGON_EFFECT_OFF_SHORE_FOAM', EffectSwitches::define('shore_foam'));
    }
}
