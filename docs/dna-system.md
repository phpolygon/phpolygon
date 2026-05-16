# Character DNA System

PHPolygon's character DNA system encodes a humanoid's appearance and proportions
into a fixed-size, shareable, mutable strand of 72 bases. Games author
characters by:

1. Generating or loading a `CharacterDNA` strand (binary or ACGT string).
2. Attaching a `CharacterDnaComponent` to an entity.
3. Calling `CharacterMeshBuilder::buildOn()` to spawn the procedural rig.

No model files, no texture imports, no Blender pipeline. Every visible feature
is a function of the strand and the procedural mesh generators in `src/Geometry/`.

---

## Strand layout

| Property | Value |
|---|---|
| Strand size | **18 bytes** (`CharacterDNA::STRAND_BYTES`) |
| Base count | **72** (`STRAND_BASES`) — 4 bases per byte, 2 bits each |
| Codon count | **24** (`STRAND_CODONS`) — 3 bases per codon, 6 bits each |
| Codon range | 0..63 |
| ACGT alphabet | `A`=0, `C`=1, `G`=2, `T`=3 |
| Text encoding | 72-character ACGT string, shareable, deterministic |

The 18-byte binary form is the canonical in-memory representation; the ACGT
string is the canonical save-game and clipboard form (`toAcgt()` / `fromAcgt()`).

```php
$dna = CharacterDNA::random();           // PRNG seeded by Random\Randomizer
$str = $dna->toAcgt();                   // "TCAGTC..." (72 chars)
$same = CharacterDNA::fromAcgt($str);    // byte-identical roundtrip

$dna->codon(7);                          // 0..63 at locus 7
$dna->base(21);                          // 0..3  at base index 21
$dna->mutate(bitFlips: 3);               // Hamming distance ≤ 3
CharacterDNA::crossover($parentA, $parentB, bytePoint: 9);  // child strand
```

---

## Loci → traits

The decoder (`GeneDecoder`) walks a trait class via `Reflection` and reads each
constructor parameter that carries a `#[Gene(locus, mapping)]` attribute. The
built-in trait class is `PlayerProportions`, which uses all 24 loci:

| Locus | Trait | Mapping | Range / Cases |
|---|---|---|---|
| 0 | `bodyHeight` | `ContinuousRange` | 0.85 .. 1.15 |
| 1 | `shoulderWidth` | `ContinuousRange` | 0.70 .. 1.30 |
| 2 | `hipWidth` | `ContinuousRange` | 0.70 .. 1.20 |
| 3 | `torsoLength` | `ContinuousRange` | 0.90 .. 1.10 |
| 4 | `limbLength` | `ContinuousRange` | 0.85 .. 1.15 |
| 5 | `limbTaper` | `ContinuousRange` | 0.60 .. 1.00 |
| 6 | `skullHeight` | `ContinuousRange` | 0.90 .. 1.10 |
| 7 | `skullWidth` | `ContinuousRange` | 0.85 .. 1.15 |
| 8 | `jawWidth` | `ContinuousRange` | 0.70 .. 1.30 |
| 9 | `browProminence` | `ContinuousRange` | 0.00 .. 1.00 |
| 10 | `eyeSpacing` | `ContinuousRange` | 0.85 .. 1.15 |
| 11 | `skinTone` | `EnumChoice` | `SkinTone` (12 cases) |
| 12 | `hairColor` | `EnumChoice` | `HairColor` (12) |
| 13 | `hairStyle` | `EnumChoice` | `HairStyle` (12) |
| 14 | `eyeColor` | `EnumChoice` | `EyeColor` (12) |
| 15 | `eyeShape` | `EnumChoice` | `EyeShape` (6) |
| 16 | `facialHair` | `EnumChoice` | `FacialHair` (6) |
| 17 | `eyebrowThickness` | `ContinuousRange` | 0.60 .. 1.50 |
| 18 | `eyebrowAngle` | `ContinuousRange` | -0.35 .. 0.35 |
| 19 | `noseShape` | `EnumChoice` | `NoseShape` (5) |
| 20 | `earSize` | `ContinuousRange` | 0.75 .. 1.35 |
| 21 | `age` | `ContinuousRange` | 0.00 .. 1.00 |
| 22 | `buildBias` | `ContinuousRange` | -1.00 .. 1.00 |
| 23 | `accessory` | `EnumChoice` | `Accessory` (5) |

---

## Mapping strategies

A `GeneMapping` turns a 6-bit codon into a typed value. Three strategies ship:

| Strategy | When to use |
|---|---|
| `ContinuousRange(min, max)` | Linear float interpolation. Best for proportions / strengths. |
| `EnumChoice($enumClass)` | Pick an enum case by `codon mod count`. Best for typed discrete traits. |
| `Palette([...])` | Pick from an arbitrary value list (strings, ints, mixed). Best when the value set isn't backed by an enum. |

Custom traits combine them freely:

```php
final readonly class OutfitVariation
{
    public function __construct(
        #[Gene(0, new Palette(['rags', 'tunic', 'armor', 'robe']))]
        public string $outfit,

        #[Gene(1, new ContinuousRange(0.0, 1.0))]
        public float $wear,
    ) {}
}

$decoder = new GeneDecoder();
$outfit = $decoder->decode($dna, OutfitVariation::class);
```

Modulo bias note: when `count(cases)` is not a divisor of 64, the lowest
`64 mod count` cases each receive one extra codon. With 12 cases (`HairStyle`,
`HairColor`, `EyeColor`, `SkinTone`) the first 4 cases see 6 codons each, the
remaining 8 see 5. With 5 cases (`NoseShape`, `Accessory`) the first 4 see 13,
the last sees 12. Tolerable for character variation; if a trait must be
unbiased, derive your own mapping or restrict the range.

---

## ECS integration

`CharacterDnaComponent` is the canonical attach point. It holds the ACGT string
(save-game serialisable via the engine's `AttributeSerializer`) and lazily
decodes the strand + proportions on first access.

```php
use PHPolygon\Character\CharacterMeshBuilder;
use PHPolygon\Character\DNA\CharacterDNA;
use PHPolygon\Component\CharacterDnaComponent;
use PHPolygon\Component\Transform3D;

CharacterMeshBuilder::registerDefaults();

$root = $world->createEntity();
$root->attach(new Transform3D(position: new Vec3(0, 0, 0)));
$root->attach(new CharacterDnaComponent(CharacterDNA::random()));

$parts = CharacterMeshBuilder::buildOn($world, $root);  // ~80-120 child entities
```

The component exposes:

- `acgt: string` — serialised form, save-game safe
- `dna(): CharacterDNA` — cached decoded strand
- `proportions(): PlayerProportions` — cached decoded proportions
- `decodeAs(class-string $T): T` — decode the same strand into a custom trait class
- `setDna(...)` — replace the strand, clears the caches

---

## CharacterMeshBuilder

`CharacterMeshBuilder` is a static, stateless helper that:

1. Registers a small fixed set of primitive meshes (box, sphere, cylinder,
   procedural skull, lathe-revolved torso) and material variants (per skin
   tone, per hair colour, per eye colour, plus shared accessory materials).
2. Builds the humanoid rig under a root entity according to a
   `PlayerProportions`.

Registration is idempotent and lazy — `buildOn()` triggers it on first use.

```php
CharacterMeshBuilder::registerDefaults();              // optional, idempotent
$parts = CharacterMeshBuilder::buildOn($world, $root); // proportions from CharacterDnaComponent
$parts = CharacterMeshBuilder::buildOn($world, $root, $explicitProportions);
```

Mesh + material IDs are exposed as public constants:

```
CharacterMeshBuilder::MESH_SKULL
CharacterMeshBuilder::MESH_TORSO
CharacterMeshBuilder::skinMaterialId(SkinTone::TanWarm)
CharacterMeshBuilder::hairMaterialId(HairColor::Auburn)
```

Custom games can override individual materials by registering a different
`Material` under the same ID **after** calling `registerDefaults()`.

---

## Versioning the strand

The 24-locus / 18-byte layout is the **v1** schema. All 24 loci are populated.
To add traits without breaking saved ACGT strings:

- **Repurpose an existing locus** only when the trait was clearly experimental
  and never shipped publicly.
- **Add a new versioned strand class** (e.g. `CharacterDNAv2`) when the new
  trait set is large enough to justify a migration. Keep `CharacterDNA` (v1)
  around for backwards compatibility and offer a one-time migration in
  `CharacterDnaComponent` (e.g. detect 72-char vs 80-char ACGT and decode
  accordingly).

Never silently extend `STRAND_BYTES` or `STRAND_BASES` on the existing
class — every saved game depends on the codon-at-locus mapping staying
stable.

---

## Anti-patterns

- **Do not** add a new trait by editing `PlayerProportions` constructor order.
  Locus assignments must stay stable; new traits go on new loci or a v2 class.
- **Do not** put GPU calls or `MeshRegistry` lookups inside `GeneDecoder` /
  `PlayerProportions`. These are pure data — the builder is the consumer.
- **Do not** build characters by directly calling `World::createEntity()` for
  every body part in game code. Use `CharacterMeshBuilder::buildOn()` so the
  rig stays consistent and the engine can evolve the mesh layout without every
  game rewriting its character constructor.
- **Do not** mutate a `CharacterDnaComponent`'s `acgt` field directly. Use
  `setDna()` so the cached `dna()` / `proportions()` are invalidated.
- **Do not** swap `EnumChoice` for `Palette` on a stable locus just to add one
  value — the modulo wrap shifts every existing strand's decoded value. Add
  the value at the end of the enum / palette only.
