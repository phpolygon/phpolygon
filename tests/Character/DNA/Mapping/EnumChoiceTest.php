<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Character\DNA\Mapping;

use PHPUnit\Framework\TestCase;
use PHPolygon\Character\DNA\Mapping\EnumChoice;
use PHPolygon\Character\DNA\Enum\SkinTone;

class EnumChoiceTest extends TestCase
{
    public function testStoresEnumClass(): void
    {
        $choice = new EnumChoice(SkinTone::class);
        $this->assertSame(SkinTone::class, $choice->enumClass);
    }

    public function testCodonZeroMapsToFirstCase(): void
    {
        $choice = new EnumChoice(SkinTone::class);
        $this->assertSame(SkinTone::cases()[0], $choice->map(0));
    }

    public function testMapsByModuloOfCaseCount(): void
    {
        $choice = new EnumChoice(SkinTone::class);
        $cases  = SkinTone::cases();
        $this->assertSame($cases[1], $choice->map(1));
        $this->assertSame($cases[2], $choice->map(2));
    }

    public function testWrapsAroundAtCaseCount(): void
    {
        $choice = new EnumChoice(SkinTone::class);
        $cases  = SkinTone::cases();
        $count  = count($cases);
        // codon == count wraps back to the first case
        $this->assertSame($cases[0], $choice->map($count));
        $this->assertSame($cases[1], $choice->map($count + 1));
    }

    public function testReturnsAUnitEnumInstance(): void
    {
        $choice = new EnumChoice(SkinTone::class);
        $this->assertInstanceOf(SkinTone::class, $choice->map(5));
    }

    public function testRejectsNonEnumClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EnumChoice(\stdClass::class);
    }
}
