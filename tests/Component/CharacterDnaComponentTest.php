<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Component;

use PHPolygon\Character\DNA\CharacterDNA;
use PHPolygon\Character\DNA\PlayerProportions;
use PHPolygon\Component\CharacterDnaComponent;
use PHPolygon\ECS\Serializer\AttributeSerializer;
use PHPUnit\Framework\TestCase;

class CharacterDnaComponentTest extends TestCase
{
    public function testDefaultConstructorGeneratesRandomDna(): void
    {
        $component = new CharacterDnaComponent();

        $this->assertSame(CharacterDNA::STRAND_BASES, strlen($component->acgt));
        $this->assertMatchesRegularExpression('/^[ACGT]+$/', $component->acgt);
    }

    public function testAcceptsCharacterDnaInstance(): void
    {
        $dna = CharacterDNA::random();
        $component = new CharacterDnaComponent($dna);

        $this->assertSame($dna->toAcgt(), $component->acgt);
        $this->assertSame($dna, $component->dna());
    }

    public function testAcceptsAcgtString(): void
    {
        $acgt = str_repeat('ACGT', 18);
        $component = new CharacterDnaComponent($acgt);

        $this->assertSame($acgt, $component->acgt);
        $this->assertSame($acgt, $component->dna()->toAcgt());
    }

    public function testProportionsAreCached(): void
    {
        $component = new CharacterDnaComponent(CharacterDNA::random());

        $a = $component->proportions();
        $b = $component->proportions();
        $this->assertSame($a, $b);
        $this->assertInstanceOf(PlayerProportions::class, $a);
    }

    public function testSetDnaClearsCaches(): void
    {
        $component = new CharacterDnaComponent(str_repeat('A', 72));
        $first = $component->proportions();

        $component->setDna(str_repeat('T', 72));
        $second = $component->proportions();

        $this->assertNotSame($first, $second);
        $this->assertNotEquals($first->bodyHeight, $second->bodyHeight);
    }

    public function testRoundTripsThroughAttributeSerializer(): void
    {
        $dna = CharacterDNA::random();
        $original = new CharacterDnaComponent($dna);

        $serializer = new AttributeSerializer();
        $data = $serializer->toArray($original);

        $this->assertSame(CharacterDnaComponent::class, $data['_class']);
        $this->assertSame($dna->toAcgt(), $data['acgt']);

        $restored = $serializer->fromArray($data, CharacterDnaComponent::class);
        $this->assertInstanceOf(CharacterDnaComponent::class, $restored);
        $this->assertSame($dna->toAcgt(), $restored->acgt);
        $this->assertSame($dna->bytes, $restored->dna()->bytes);
    }

    public function testDecodeAsSupportsCustomTraitClasses(): void
    {
        $dna = CharacterDNA::random();
        $component = new CharacterDnaComponent($dna);

        $props = $component->decodeAs(PlayerProportions::class);
        $this->assertInstanceOf(PlayerProportions::class, $props);
    }
}
