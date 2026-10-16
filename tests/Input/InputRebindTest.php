<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Input;

use PHPUnit\Framework\TestCase;
use PHPolygon\Input\InputBinding;
use PHPolygon\Input\InputBindingType;
use PHPolygon\Input\InputMap;
use PHPolygon\Input\RebindConflictPolicy;
use PHPolygon\Input\RebindStatus;
use PHPolygon\Runtime\Input;

class InputRebindTest extends TestCase
{
    private const int G = 71;
    private const int F = 70;
    private const int T = 84;
    private const int E = 69;

    private InputMap $map;

    protected function setUp(): void
    {
        $this->map = new InputMap();
        $this->map->addAction('replay', InputBinding::key(self::G));
        $this->map->addAction('use', InputBinding::key(self::F));
        $this->map->addAction('interact', InputBinding::key(self::E));
    }

    public function testRebindChangesWhatFiresTheAction(): void
    {
        $result = $this->map->rebind('replay', self::T);

        $this->assertSame(RebindStatus::Bound, $result->status);
        $this->assertTrue($result->isSuccess());
        $this->assertFalse($this->map->isDefault('replay'));

        $input = new Input();
        $input->handleKeyEvent(self::T, 1);
        $this->map->poll($input);
        $this->assertTrue($this->map->getAction('replay')->isPressed());

        $input = new Input();
        $input->handleKeyEvent(self::G, 1);
        $this->map->poll($input);
        $this->assertFalse($this->map->getAction('replay')->isHeld());
    }

    public function testRebindingToTheSameBindingIsUnchanged(): void
    {
        $this->assertSame(RebindStatus::Unchanged, $this->map->rebind('replay', self::G)->status);
        $this->assertTrue($this->map->isDefault('replay'));
    }

    public function testUnknownActionAndInvalidSlot(): void
    {
        $this->assertSame(RebindStatus::UnknownAction, $this->map->rebind('fly', self::T)->status);
        $this->assertSame(RebindStatus::InvalidSlot, $this->map->rebind('replay', self::T, 5)->status);
    }

    public function testSlotEqualToCountAppendsASecondBinding(): void
    {
        $this->assertTrue($this->map->rebind('replay', InputBinding::mouseButton(3), 1)->isSuccess());
        $bindings = $this->map->getBindings('replay');
        $this->assertCount(2, $bindings);
        $this->assertSame(InputBindingType::MouseButton, $bindings[1]->type);
    }

    public function testReservedKeysAreRefused(): void
    {
        $this->map->reserve(self::T);
        $this->assertSame(RebindStatus::Reserved, $this->map->rebind('replay', self::T)->status);
        $this->assertTrue($this->map->isDefault('replay'));
    }

    public function testLockedActionCannotBeRebound_andItsKeysAreReserved(): void
    {
        $this->map->setRebindable('interact', false);

        $this->assertSame(RebindStatus::Locked, $this->map->rebind('interact', self::T)->status);
        $this->assertSame(RebindStatus::Reserved, $this->map->rebind('replay', self::E)->status);
        $this->assertFalse($this->map->isRebindable('interact'));
    }

    public function testAxisKeysAreReserved(): void
    {
        $this->map->addAxis('strafe', [InputBinding::key(68)], [InputBinding::key(65)]);
        $this->assertSame(RebindStatus::Reserved, $this->map->rebind('replay', 65)->status);
    }

    public function testBindableFilter(): void
    {
        $this->map->setBindableFilter(
            static fn (InputBinding $b): bool => $b->type === InputBindingType::Key && $b->code >= 65 && $b->code <= 90,
        );
        $this->assertSame(RebindStatus::NotBindable, $this->map->rebind('replay', 32)->status);
        $this->assertSame(RebindStatus::NotBindable, $this->map->rebind('replay', InputBinding::mouseButton(0))->status);
        $this->assertTrue($this->map->rebind('replay', self::T)->isSuccess());
    }

    public function testConflictIsRejectedByDefault(): void
    {
        $result = $this->map->rebind('replay', self::F);

        $this->assertSame(RebindStatus::Conflict, $result->status);
        $this->assertSame('use', $result->otherAction);
        $this->assertTrue($this->map->isDefault('replay'));
        $this->assertTrue($this->map->isDefault('use'));
    }

    public function testConflictSwapsWhenConfigured(): void
    {
        $this->map->setConflictPolicy(RebindConflictPolicy::Swap);
        $result = $this->map->rebind('replay', self::F);

        $this->assertSame(RebindStatus::Swapped, $result->status);
        $this->assertSame('use', $result->otherAction);
        $this->assertSame(self::F, $this->map->getBinding('replay')?->code);
        $this->assertSame(self::G, $this->map->getBinding('use')?->code);
    }

    public function testCheckRebindDoesNotApply(): void
    {
        $this->assertSame(RebindStatus::Bound, $this->map->checkRebind('replay', self::T)->status);
        $this->assertSame(RebindStatus::Conflict, $this->map->checkRebind('replay', self::F)->status);
        $this->assertTrue($this->map->isDefault('replay'));
    }

    public function testMovingABindingBetweenSlotsOfOneAction(): void
    {
        $this->map->addAction('jump', InputBinding::key(32), InputBinding::key(self::T));
        $this->assertTrue($this->map->rebind('jump', self::T, 0)->isSuccess());

        $codes = array_map(static fn (InputBinding $b): int => $b->code, $this->map->getBindings('jump'));
        $this->assertSame([self::T, 32], $codes);
    }

    public function testResetAndResetAll(): void
    {
        $this->map->rebind('replay', self::T);
        $this->map->rebind('use', 85);

        $this->map->resetBinding('replay');
        $this->assertTrue($this->map->isDefault('replay'));
        $this->assertFalse($this->map->isDefault('use'));

        $this->map->resetAllBindings();
        $this->assertTrue($this->map->isDefault('use'));
    }

    public function testResetCascadesToAnActionThatTookTheDefault(): void
    {
        $this->map->rebind('replay', self::T);   // G free now
        $this->map->rebind('use', self::G);      // use takes G

        $this->map->resetBinding('replay');

        $this->assertSame(self::G, $this->map->getBinding('replay')?->code);
        $this->assertTrue($this->map->isDefault('use'));
    }

    public function testExportContainsOnlyOverrides(): void
    {
        $this->assertSame([], $this->map->exportOverrides());

        $this->map->rebind('replay', self::T);
        $this->assertSame(
            ['replay' => [['type' => 'key', 'code' => self::T]]],
            $this->map->exportOverrides(),
        );
    }

    public function testExportImportRoundTrip(): void
    {
        $this->map->setConflictPolicy(RebindConflictPolicy::Swap);
        $this->map->rebind('replay', self::F); // swap G <-> F
        $saved = $this->map->exportOverrides();

        $fresh = new InputMap();
        $fresh->addAction('replay', InputBinding::key(self::G));
        $fresh->addAction('use', InputBinding::key(self::F));

        $this->assertSame([], $fresh->importOverrides($saved));
        $this->assertSame(self::F, $fresh->getBinding('replay')?->code);
        $this->assertSame(self::G, $fresh->getBinding('use')?->code);
    }

    public function testImportAcceptsBareKeyCodes(): void
    {
        $this->assertSame([], $this->map->importOverrides(['replay' => self::T]));
        $this->assertSame(self::T, $this->map->getBinding('replay')?->code);
    }

    public function testImportDropsInvalidEntries(): void
    {
        $this->map->reserve(82);
        $dropped = $this->map->importOverrides([
            'replay' => 82,              // reserved
            'nope' => self::T,           // unknown action
            'use' => ['garbage'],        // unreadable
        ]);

        $this->assertSame(['replay', 'nope', 'use'], $dropped);
        $this->assertSame([], $this->map->exportOverrides());
    }

    public function testImportResolvesClashesByDroppingTheLaterAction(): void
    {
        $dropped = $this->map->importOverrides(['replay' => self::T, 'use' => self::T]);

        $this->assertSame(['use'], $dropped);
        $this->assertSame(self::T, $this->map->getBinding('replay')?->code);
        $this->assertTrue($this->map->isDefault('use'));
    }

    public function testImportDropsAnOverrideThatClashesWithADefault(): void
    {
        $dropped = $this->map->importOverrides(['replay' => self::E]);

        $this->assertSame(['replay'], $dropped);
        $this->assertTrue($this->map->isDefault('replay'));
    }

    public function testBindingArrayRoundTrip(): void
    {
        $b = InputBinding::mouseButton(4);
        $back = InputBinding::fromArray($b->toArray());
        $this->assertNotNull($back);
        $this->assertTrue($b->equals($back));
        $this->assertNull(InputBinding::fromArray(['type' => 'pedal', 'code' => 1]));
    }
}
