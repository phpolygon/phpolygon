<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\PostProcess\FullscreenPass;
use PHPolygon\Rendering\PostProcess\FullscreenPassRegistry;
use PHPolygon\Rendering\PostProcess\PostStage;
use PHPolygon\Rendering\Quality\AdaptiveSlot;
use PHPolygon\Rendering\Quality\AdaptiveStep;
use PHPolygon\Rendering\Quality\AdaptiveTierStack;

/** The chain's bookkeeping, effect states in the settings and adaptive steps, no GPU involved. */
final class FullscreenPassRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        FullscreenPassRegistry::clear();
        AdaptiveTierStack::clearSteps();
    }

    protected function tearDown(): void
    {
        FullscreenPassRegistry::clear();
        AdaptiveTierStack::clearSteps();
    }

    public function testPassesRunInTheirOrderWithinTheirStage(): void
    {
        FullscreenPassRegistry::register(new FullscreenPass('late', PostStage::Final, 'x', order: 20));
        FullscreenPassRegistry::register(new FullscreenPass('early', PostStage::Final, 'x', order: 10));
        FullscreenPassRegistry::register(new FullscreenPass('elsewhere', PostStage::Scene, 'x'));

        self::assertSame(['early', 'late'], array_map(static fn (FullscreenPass $p): string => $p->name, FullscreenPassRegistry::forStage(PostStage::Final)));
        self::assertSame([], FullscreenPassRegistry::forStage(PostStage::Display));
    }

    public function testNamesAreSafeForProgramIdsAndMarks(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FullscreenPass('Bad-Name', PostStage::Scene, 'x');
    }

    public function testAPassFollowsItsSettingAndItsDefault(): void
    {
        $on = new FullscreenPass('grain', PostStage::Final, 'x');
        $off = new FullscreenPass('sketch', PostStage::Final, 'x', default: false);
        $settings = new GraphicsSettings();

        self::assertTrue($on->enabledBy($settings));
        self::assertFalse($off->enabledBy($settings));
        self::assertFalse($on->enabledBy($settings->with(effects: ['grain' => false])));
        self::assertTrue($off->enabledBy($settings->with(effects: ['sketch' => true])));
    }

    public function testEffectStatesMergeAndSurviveTheSaveFile(): void
    {
        $settings = (new GraphicsSettings())->with(effects: ['a' => false])->with(effects: ['b' => true]);
        self::assertSame(['a' => false, 'b' => true], $settings->effects);

        $json = json_decode((string) json_encode($settings->toJson()), true);
        self::assertIsArray($json);
        $loaded = GraphicsSettings::fromJson($json);
        self::assertSame(['a' => false, 'b' => true], $loaded->effects);
    }

    public function testASaveFileWithoutEffectsStaysAsItWas(): void
    {
        self::assertArrayNotHasKey('effects', (new GraphicsSettings())->toJson());
        self::assertSame([], GraphicsSettings::fromJson(['effects' => ['broken' => 'yes', 3 => true]])->effects);
    }

    /** @return list<GraphicsSettings> */
    private static function walkDown(GraphicsSettings $settings): array
    {
        $chain = [];
        while (($next = AdaptiveTierStack::downgrade($settings)) !== null) {
            $chain[] = $settings = $next;
            self::assertLessThan(200, count($chain), 'the stack ends');
        }
        return $chain;
    }

    public function testWithoutStepsTheStackIsTheFixedOne(): void
    {
        $settings = new GraphicsSettings(volumetricFog: true);
        self::assertSame($settings->with(volumetricFog: false)->toJson(), AdaptiveTierStack::downgrade($settings)?->toJson());
    }

    public function testAStepSitsAtItsSlotAndComesBackInReverse(): void
    {
        AdaptiveTierStack::registerStep(new AdaptiveStep('first', AdaptiveSlot::First));
        AdaptiveTierStack::registerStep(new AdaptiveStep('late', AdaptiveSlot::AfterBloom));
        $start = new GraphicsSettings(volumetricFog: true, bloom: true);

        $chain = self::walkDown($start);
        self::assertFalse($chain[0]->effectEnabled('first'), 'the first step of the stack');
        self::assertTrue($chain[0]->volumetricFog);
        self::assertFalse($chain[1]->volumetricFog, 'then the fixed stack');

        $bloomOff = null;
        $lateOff = null;
        foreach ($chain as $i => $settings) {
            $bloomOff ??= $settings->bloom ? null : $i;
            $lateOff ??= $settings->effectEnabled('late') ? null : $i;
        }
        self::assertSame($bloomOff + 1, $lateOff, 'directly behind bloom');

        // And up again: the stack walks all the way back.
        $last = end($chain);
        self::assertInstanceOf(GraphicsSettings::class, $last);
        $settings = $last;
        $n = 0;
        while (($next = AdaptiveTierStack::upgrade($settings)) !== null) {
            $settings = $next;
            self::assertLessThan(200, ++$n);
        }
        self::assertTrue($settings->effectEnabled('first'));
        self::assertTrue($settings->effectEnabled('late'));
    }

    public function testAStepRespectsAnEffectThatIsOffByDefault(): void
    {
        AdaptiveTierStack::registerStep(new AdaptiveStep('optional', AdaptiveSlot::First, default: false));
        $settings = new GraphicsSettings(volumetricFog: true);
        self::assertTrue($settings->volumetricFog);
        self::assertFalse(AdaptiveTierStack::downgrade($settings)?->volumetricFog, 'nothing to switch off, the fixed stack goes on');
    }
}
