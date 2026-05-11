<?php

declare(strict_types=1);

namespace PHPolygon\Prefab\Vehicles;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\CarBodyMesh;
use PHPolygon\Geometry\CylinderMesh;
use PHPolygon\Geometry\Generated\DoorHandleMesh;
use PHPolygon\Geometry\Generated\TireMesh;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\SpokedRimMesh;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Scene\EntityDeclaration;
use PHPolygon\Scene\Prefab;
use PHPolygon\Scene\SceneBuilder;

/**
 * Reference vehicle prefab. Demonstrates the modifier pattern:
 *
 *   $b->spawn(new Car())->suv()->cabrio()->place(new Vec3(10, 0, 5));
 *
 * The spawned hierarchy looks like:
 *   Car (root)
 *     Body            chassis silhouette, paint
 *     Windshield      angled glass quad
 *     RearWindow      angled glass quad
 *     SideWindow_L/R  flat glass panels on cabin sides
 *     BumperFront/Rear  dark trim
 *     Headlight_L/R   emissive white blocks
 *     Taillight_L/R   emissive red blocks
 *     PlateFront/Rear small white plates
 *     Mirror_L/R      small angled boxes
 *     Wheel_0..3      tire cylinders
 *       Rim_0..3      smaller chrome cylinders (children of wheels)
 *
 * Override hooks (subclass-friendly):
 *   bodyDimensions(), bodyProfile(), wheelRadius(), wheelWidth(),
 *   wheelbaseFraction(), rimRadius(), rimWidth()
 *   buildBody(), buildTire(), buildRim(),
 *   buildWindshield(), buildRearWindow(), buildSideWindow(),
 *   buildBumperFront(), buildBumperRear(),
 *   buildHeadlight(), buildTaillight(), buildPlate(), buildMirror()
 *
 * @phpstan-consistent-constructor
 */
class Car extends Prefab
{
    protected CarChassis $chassis = CarChassis::Sedan;
    protected CarRoof $roof = CarRoof::Hardtop;

    // -- Material slots (overridable per instance via fluent setters) -----
    protected string  $bodyMaterial      = 'car_paint_default';
    protected string  $tireMaterial      = 'rubber';
    protected string  $glassMaterial     = 'car_glass_default';
    protected string  $bumperMaterial    = 'car_bumper_default';
    protected string  $rimMaterial       = 'car_rim_default';
    protected string  $headlightMaterial = 'car_headlight_default';
    protected string  $taillightMaterial = 'car_taillight_default';
    protected string  $plateMaterial     = 'car_plate_default';
    /** Mirrors fall back to the body paint material when null. */
    protected ?string $mirrorMaterial    = null;
    protected string  $grilleMaterial    = 'car_grille_default';
    /** Door handles fall back to bumper material when null (chrome on dark plastic). */
    protected ?string $doorHandleMaterial = null;
    /** Exhaust falls back to bumper material when null (dark metal). */
    protected ?string $exhaustMaterial    = null;

    // -- Chassis modifiers ------------------------------------------------

    public function suv(): static     { $this->chassis = CarChassis::SUV;     return $this; }
    public function pickup(): static  { $this->chassis = CarChassis::Pickup;  return $this; }
    public function compact(): static { $this->chassis = CarChassis::Compact; return $this; }
    public function sedan(): static   { $this->chassis = CarChassis::Sedan;   return $this; }
    public function cabrio(): static  { $this->roof = CarRoof::Convertible;   return $this; }
    public function hardtop(): static { $this->roof = CarRoof::Hardtop;       return $this; }

    // -- Material modifiers ----------------------------------------------

    public function paintedWith(string $id): static    { $this->bodyMaterial      = $id; return $this; }
    public function wheelsOf(string $id): static       { $this->tireMaterial      = $id; return $this; }
    public function glassWith(string $id): static      { $this->glassMaterial     = $id; return $this; }
    public function bumpersOf(string $id): static      { $this->bumperMaterial    = $id; return $this; }
    public function rimsOf(string $id): static         { $this->rimMaterial       = $id; return $this; }
    public function headlightsAs(string $id): static   { $this->headlightMaterial = $id; return $this; }
    public function taillightsAs(string $id): static   { $this->taillightMaterial = $id; return $this; }
    public function platesAs(string $id): static       { $this->plateMaterial     = $id; return $this; }
    public function mirrorsOf(string $id): static      { $this->mirrorMaterial    = $id; return $this; }
    public function grilleOf(string $id): static       { $this->grilleMaterial    = $id; return $this; }
    public function doorHandlesOf(string $id): static  { $this->doorHandleMaterial = $id; return $this; }
    public function exhaustOf(string $id): static      { $this->exhaustMaterial   = $id; return $this; }

    // -- Override hooks --------------------------------------------------

    /** @return array{0: float, 1: float, 2: float} length, height, width */
    protected function bodyDimensions(): array
    {
        return match ($this->chassis) {
            CarChassis::Sedan   => [4.5, 1.4,  1.80],
            CarChassis::SUV     => [4.7, 1.8,  1.95],
            CarChassis::Pickup  => [5.4, 1.7,  2.00],
            CarChassis::Compact => [3.8, 1.4,  1.70],
        };
    }

    protected function wheelRadius(): float
    {
        return match ($this->chassis) {
            CarChassis::SUV     => 0.40,
            CarChassis::Pickup  => 0.42,
            CarChassis::Compact => 0.30,
            default             => 0.32,
        };
    }

    protected function wheelWidth(): float
    {
        return 0.20;
    }

    protected function rimRadius(): float
    {
        return $this->wheelRadius() * 0.62;
    }

    protected function rimWidth(): float
    {
        return $this->wheelWidth() * 0.85;
    }

    /**
     * Wheelbase as a fraction of the total chassis length. Real-car ratios:
     *   sedan / compact: ~0.60
     *   SUV:             ~0.62
     *   pickup:          ~0.65 (long bed pushes the rear wheels forward)
     */
    protected function wheelbaseFraction(): float
    {
        return match ($this->chassis) {
            CarChassis::Pickup => 0.65,
            CarChassis::SUV    => 0.62,
            default            => 0.60,
        };
    }

    protected function buildBody(): MeshData
    {
        [$length, $height, $width] = $this->bodyDimensions();
        $profile = $this->bodyProfile();
        $cabinHeightFrac = $this->roof === CarRoof::Convertible ? 0.55 : 1.0;

        return CarBodyMesh::generate(
            length:           $length,
            width:            $width,
            bodyHeight:       $height,
            hoodLengthFrac:   $profile['hoodLengthFrac'],
            hoodHeightFrac:   $profile['hoodHeightFrac'],
            cabinLengthFrac:  $profile['cabinLengthFrac'],
            cabinHeightFrac:  $cabinHeightFrac,
            trunkHeightFrac:  $profile['trunkHeightFrac'],
            windshieldSlope:  $length * $profile['windshieldSlopeFrac'],
            rearWindowSlope:  $length * $profile['rearWindowSlopeFrac'],
            // Skip the windshield (edge 2) and rear-window (edge 4) panels:
            // glass quads will fill those slots without z-fighting.
            skipSidePanels:   [2, 4],
        );
    }

    /**
     * Per-chassis silhouette proportions consumed by buildBody().
     *
     * @return array{
     *     hoodLengthFrac: float,
     *     hoodHeightFrac: float,
     *     cabinLengthFrac: float,
     *     trunkHeightFrac: float,
     *     windshieldSlopeFrac: float,
     *     rearWindowSlopeFrac: float
     * }
     */
    protected function bodyProfile(): array
    {
        return match ($this->chassis) {
            CarChassis::Sedan => [
                'hoodLengthFrac'      => 0.30,
                'hoodHeightFrac'      => 0.45,
                'cabinLengthFrac'     => 0.32,
                'trunkHeightFrac'     => 0.62,
                'windshieldSlopeFrac' => 0.10,
                'rearWindowSlopeFrac' => 0.09,
            ],
            CarChassis::SUV => [
                'hoodLengthFrac'      => 0.22,
                'hoodHeightFrac'      => 0.55,
                'cabinLengthFrac'     => 0.50,
                'trunkHeightFrac'     => 0.85,
                'windshieldSlopeFrac' => 0.06,
                'rearWindowSlopeFrac' => 0.04,
            ],
            CarChassis::Pickup => [
                'hoodLengthFrac'      => 0.28,
                'hoodHeightFrac'      => 0.55,
                'cabinLengthFrac'     => 0.28,
                'trunkHeightFrac'     => 0.40,
                'windshieldSlopeFrac' => 0.08,
                'rearWindowSlopeFrac' => 0.03,
            ],
            CarChassis::Compact => [
                'hoodLengthFrac'      => 0.24,
                'hoodHeightFrac'      => 0.45,
                'cabinLengthFrac'     => 0.45,
                'trunkHeightFrac'     => 0.62,
                'windshieldSlopeFrac' => 0.07,
                'rearWindowSlopeFrac' => 0.07,
            ],
        };
    }

    protected function buildTire(): MeshData
    {
        // SVG-derived tire profile, revolved around Y. Generated mesh has
        // outer radius 30, inner radius 16, width 20 in raw SVG units.
        // The wheel entity transform scales these to physical wheelRadius
        // and wheelWidth - see attachWheels() for the scale calculation.
        return TireMesh::generate();
    }

    protected function buildRim(): MeshData
    {
        return SpokedRimMesh::generate(
            outerRadius: $this->rimRadius(),
            innerRadius: $this->rimRadius() * 0.88,
            // Rim slightly wider than the tire so the chrome face is
            // visible outboard of the rubber wall.
            width:       $this->wheelWidth() + 0.02,
            spokeCount:  $this->rimSpokeCount(),
            spokeWidth:  $this->wheelWidth() * 0.30,
            spokeDepth:  $this->rimRadius() * 0.10,
            segments:    16,
        );
    }

    /**
     * Number of radial spokes on the alloy rim. Defaults to 5 (classic
     * 5-spoke alloy). Override per chassis or per subclass for a sportier
     * look (e.g. 6-spoke for SUV, multi-spoke for compact city car).
     */
    protected function rimSpokeCount(): int
    {
        return match ($this->chassis) {
            CarChassis::Pickup  => 6,  // heavier truck wheels read 6-spoke
            CarChassis::Compact => 5,
            CarChassis::SUV     => 5,
            default             => 5,  // sedan default
        };
    }

    /**
     * Windshield: angled glass quad placed exactly at silhouette edge p2 → p3.
     * Vertices listed CCW from outside (forward+up viewpoint).
     */
    protected function buildWindshield(): MeshData
    {
        $g = $this->geometry();
        $halfBodyW = $g['halfBodyWidth'];
        return CarBodyMesh::quad(
            new Vec3($g['hoodRearX'],      $g['hoodHeight'],   +$halfBodyW),
            new Vec3($g['hoodRearX'],      $g['hoodHeight'],   -$halfBodyW),
            new Vec3($g['cabinFrontTopX'], $g['cabinHeight'],  -$halfBodyW),
            new Vec3($g['cabinFrontTopX'], $g['cabinHeight'],  +$halfBodyW),
        );
    }

    /**
     * Rear window: angled glass quad placed exactly at silhouette edge p4 → p5.
     */
    protected function buildRearWindow(): MeshData
    {
        $g = $this->geometry();
        $halfBodyW = $g['halfBodyWidth'];
        return CarBodyMesh::quad(
            new Vec3($g['cabinRearTopX'], $g['cabinHeight'],  -$halfBodyW),
            new Vec3($g['cabinRearTopX'], $g['cabinHeight'],  +$halfBodyW),
            new Vec3($g['trunkFrontX'],   $g['trunkHeight'],  +$halfBodyW),
            new Vec3($g['trunkFrontX'],   $g['trunkHeight'],  -$halfBodyW),
        );
    }

    /**
     * Side window: thin flat box positioned just outboard of the cabin wall.
     * Returned mesh is centred at the local origin so the entity Transform3D
     * places it on the appropriate side.
     */
    protected function buildSideWindow(): MeshData
    {
        $g = $this->geometry();
        $cabinLen = max($g['cabinFrontTopX'] - $g['cabinRearTopX'], 0.05);
        $beltLine = $g['hoodHeight'] + ($g['cabinHeight'] - $g['hoodHeight']) * 0.35;
        $glassH = max($g['cabinHeight'] - $beltLine - 0.05, 0.15);
        return BoxMesh::generate($cabinLen * 0.85, $glassH, 0.02);
    }

    protected function buildBumperFront(): MeshData
    {
        $g = $this->geometry();
        return BoxMesh::generate(0.18, 0.30, $g['halfBodyWidth'] * 1.95);
    }

    protected function buildBumperRear(): MeshData
    {
        $g = $this->geometry();
        return BoxMesh::generate(0.18, 0.30, $g['halfBodyWidth'] * 1.95);
    }

    protected function buildHeadlight(): MeshData
    {
        return BoxMesh::generate(0.10, 0.18, 0.30);
    }

    protected function buildTaillight(): MeshData
    {
        return BoxMesh::generate(0.10, 0.18, 0.30);
    }

    protected function buildPlate(): MeshData
    {
        return BoxMesh::generate(0.04, 0.16, 0.40);
    }

    protected function buildMirror(): MeshData
    {
        return BoxMesh::generate(0.10, 0.10, 0.18);
    }

    /**
     * Tail-pipe: short metal cylinder mounted under the rear bumper.
     * Aligned along +X (so the open end points backward when attached
     * with the default rotation around Z).
     */
    protected function buildExhaust(): MeshData
    {
        return CylinderMesh::generate(
            radius:   0.05,
            height:   0.18,
            segments: 12,
        );
    }

    /**
     * Door handle: detailed pull-bar silhouette, generated from
     * `assets/svg/details/door_handle.svg` and committed as the
     * `DoorHandleMesh` PHP class. The SVG outline is unit-normalised, so
     * we scale the resulting mesh to a realistic ~18 cm wide × 3 cm tall
     * × 1.5 cm deep handle by adjusting the entity Transform3D in
     * `attachDoorHandles()`.
     *
     * Override this hook (or call `useBoxDoorHandles()`) for a coarser
     * box-only fallback; useful for low-LOD distant cars.
     */
    protected function buildDoorHandle(): MeshData
    {
        return DoorHandleMesh::generate();
    }

    /**
     * Front grille: shallow plate sitting on the front of the body
     * between the headlights. Reads as the main air-intake when paired
     * with the dark bumper material.
     */
    protected function buildGrille(): MeshData
    {
        $g = $this->geometry();
        $grilleW = $g['halfBodyWidth'] * 1.10;
        return BoxMesh::generate(0.06, 0.16, $grilleW);
    }

    // -- Mesh IDs --------------------------------------------------------

    protected function bodyMeshId(): string       { return sprintf('car.%s.%s.body',         $this->chassis->value, $this->roof->value); }
    protected function windshieldMeshId(): string { return sprintf('car.%s.%s.windshield',   $this->chassis->value, $this->roof->value); }
    protected function rearWindowMeshId(): string { return sprintf('car.%s.%s.rear_window',  $this->chassis->value, $this->roof->value); }
    protected function sideWindowMeshId(): string { return sprintf('car.%s.%s.side_window',  $this->chassis->value, $this->roof->value); }
    protected function bumperFrontMeshId(): string { return sprintf('car.%s.bumper_front',   $this->chassis->value); }
    protected function bumperRearMeshId(): string  { return sprintf('car.%s.bumper_rear',    $this->chassis->value); }
    protected function headlightMeshId(): string  { return 'car.headlight'; }
    protected function taillightMeshId(): string  { return 'car.taillight'; }
    protected function plateMeshId(): string      { return 'car.plate'; }
    protected function mirrorMeshId(): string     { return 'car.mirror'; }
    protected function tireMeshId(): string       { return sprintf('car.%s.tire', $this->chassis->value); }
    protected function rimMeshId(): string        { return sprintf('car.%s.rim',  $this->chassis->value); }
    protected function exhaustMeshId(): string    { return 'car.exhaust'; }
    protected function doorHandleMeshId(): string { return 'car.door_handle.svg_v1'; }
    protected function grilleMeshId(): string     { return sprintf('car.%s.grille', $this->chassis->value); }

    /** Backwards-compatible alias (older tests reference 'car.{chassis}.wheel'). */
    protected function wheelMeshId(): string      { return $this->tireMeshId(); }

    // -- Style factories (static, named-constructor pattern) ------------

    /**
     * Default sedan: standard 4-door silhouette with the engine's default
     * blue paint. Equivalent to `new self()`. Provided for symmetry with
     * the other style factories so callers can use the same pattern for
     * every variant.
     */
    public static function styleSedan(): static
    {
        return new static();
    }

    /**
     * SUV cabrio: tall body, long cabin, lowered roof to read as
     * convertible. Useful as a "leisure / off-road convertible" variant.
     */
    public static function styleSuvCabrio(): static
    {
        return (new static())->suv()->cabrio();
    }

    /**
     * Pickup with the engine's default red paint. Long bed, short cabin,
     * pickup-tuned wheelbase ratio.
     */
    public static function styleRedPickup(): static
    {
        return (new static())->pickup()->paintedWith('car_paint_red');
    }

    /**
     * Compact city car in the engine's default yellow paint. Shorter
     * chassis, longer cabin proportion, smaller wheels.
     */
    public static function styleYellowCompact(): static
    {
        return (new static())->compact()->paintedWith('car_paint_yellow');
    }

    // -- Demo helpers (static) -------------------------------------------

    /**
     * Register the default car-related materials referenced by the protected
     * material slots ('car_paint_default', 'rubber', 'car_glass_default',
     * 'car_bumper_default', 'car_rim_default', 'car_headlight_default',
     * 'car_taillight_default', 'car_plate_default') if a game has not
     * already registered them. Idempotent: each material is skipped if a
     * registration already exists, so games may pre-register custom
     * versions and let Car fill in only the gaps.
     */
    public static function registerDefaultMaterials(): void
    {
        $defaults = [
            // Body paints — use Material::carpaint() so the renderer enables
            // metallic flake jitter, the clearcoat lobe and IBL reflection
            // (proc_mode = 10 via the 'car_paint' prefix).
            'car_paint_default'     => Material::carpaint(new Color(0.20, 0.55, 0.85)),
            'car_paint_red'         => Material::carpaint(new Color(0.85, 0.15, 0.15), metallic: 0.7, roughness: 0.30),
            'car_paint_yellow'      => Material::carpaint(new Color(0.95, 0.85, 0.15), metallic: 0.5, roughness: 0.30, flakes: 0.55),
            'rubber'                => new Material(albedo: new Color(0.05, 0.05, 0.05), roughness: 0.95, metallic: 0.0, useEnvironmentMap: false),
            // Glass: high reflectivity via clearcoat, near-zero roughness so
            // IBL gives a sharp environment reflection.
            'car_glass_default'     => new Material(albedo: new Color(0.10, 0.14, 0.20), roughness: 0.05, metallic: 0.0, alpha: 0.55, clearcoat: 0.9, clearcoatRoughness: 0.04),
            'car_bumper_default'    => new Material(albedo: new Color(0.08, 0.08, 0.09), roughness: 0.55, metallic: 0.1),
            // Chrome rim: full metallic + IBL reflection.
            'car_rim_default'       => new Material(albedo: new Color(0.78, 0.80, 0.85), roughness: 0.20, metallic: 1.0, useEnvironmentMap: true),
            'car_headlight_default' => new Material(albedo: new Color(0.95, 0.95, 0.90), emission: new Color(1.0, 0.95, 0.80), roughness: 0.20, metallic: 0.0, useEnvironmentMap: false),
            'car_taillight_default' => new Material(albedo: new Color(0.55, 0.05, 0.05), emission: new Color(0.85, 0.10, 0.10), roughness: 0.30, metallic: 0.0, useEnvironmentMap: false),
            'car_plate_default'     => new Material(albedo: new Color(0.92, 0.92, 0.88), roughness: 0.85, metallic: 0.0, useEnvironmentMap: false),
            // Grille: matte black plastic with a hint of metallic so the
            // edges catch a little light and the shape reads as recessed.
            'car_grille_default'    => new Material(albedo: new Color(0.05, 0.05, 0.06), roughness: 0.45, metallic: 0.20),
        ];

        foreach ($defaults as $id => $material) {
            if (MaterialRegistry::get($id) === null) {
                MaterialRegistry::register($id, $material);
            }
        }
    }

    /**
     * Spawn a 4-car showcase that demonstrates the modifier API:
     *   1. Default sedan
     *   2. SUV cabrio (chained `suv()->cabrio()`)
     *   3. Red pickup (`pickup()->paintedWith('car_paint_red')`)
     *   4. Yellow compact (`compact()->paintedWith('car_paint_yellow')`)
     *
     * Cars are placed in a row centred on $origin along the X axis.
     * Default materials are registered (if missing) before spawning so the
     * lineup renders out-of-the-box without the caller wiring materials.
     *
     * @return list<EntityDeclaration> The four spawned car roots, ordered
     *                                 left-to-right along +X.
     */
    public static function demoLineup(
        SceneBuilder $builder,
        ?Vec3 $origin = null,
        float $spacing = 7.5,
    ): array {
        self::registerDefaultMaterials();

        $origin ??= new Vec3(0.0, 0.0, 0.0);

        return [
            $builder->spawn(static::styleSedan())
                ->named('Sedan')
                ->place(new Vec3($origin->x - $spacing * 1.5, $origin->y, $origin->z)),

            $builder->spawn(static::styleSuvCabrio())
                ->named('SuvCabrio')
                ->place(new Vec3($origin->x - $spacing * 0.5, $origin->y, $origin->z)),

            $builder->spawn(static::styleRedPickup())
                ->named('RedPickup')
                ->place(new Vec3($origin->x + $spacing * 0.5, $origin->y, $origin->z)),

            $builder->spawn(static::styleYellowCompact())
                ->named('Commuter')
                ->place(new Vec3($origin->x + $spacing * 1.5, $origin->y, $origin->z)),
        ];
    }

    // -- Build -----------------------------------------------------------

    public function build(SceneBuilder $builder): EntityDeclaration
    {
        $this->registerMeshes();

        $g = $this->geometry();
        $wheelR = $g['wheelRadius'];
        $halfL  = $g['halfWheelbase'];
        $halfW  = $g['halfWheelTrack'];

        $wheelRotation = Quaternion::fromAxisAngle(new Vec3(1.0, 0.0, 0.0), M_PI_2);

        $car = $builder->entity($this->getInstanceName())
            ->with(new Transform3D(
                position: $this->getPosition(),
                rotation: $this->getRotation(),
                scale:    $this->getScale(),
            ));

        $this->attachBody($car, $wheelR);
        $this->attachWindows($car, $wheelR);
        $this->attachBumpers($car, $wheelR);
        $this->attachGrille($car, $wheelR);
        $this->attachLights($car, $wheelR);
        $this->attachPlates($car, $wheelR);
        $this->attachMirrors($car, $wheelR);
        $this->attachDoorHandles($car, $wheelR);
        $this->attachExhaust($car, $wheelR);
        $this->attachWheels($car, $halfL, $halfW, $wheelR, $wheelRotation);

        return $car;
    }

    // -- Attach helpers --------------------------------------------------

    protected function attachBody(EntityDeclaration $car, float $wheelR): void
    {
        $car->child('Body')
            ->with(new Transform3D(position: new Vec3(0.0, $wheelR, 0.0)))
            ->with(new MeshRenderer(meshId: $this->bodyMeshId(), materialId: $this->bodyMaterial));
    }

    protected function attachWindows(EntityDeclaration $car, float $wheelR): void
    {
        $glass = $this->glassMaterial;
        $g = $this->geometry();

        // Windshield + rear window meshes already encode the slope; entity
        // sits at the body lift so they line up with the body silhouette.
        $car->child('Windshield')
            ->with(new Transform3D(position: new Vec3(0.0, $wheelR, 0.0)))
            ->with(new MeshRenderer(meshId: $this->windshieldMeshId(), materialId: $glass));

        $car->child('RearWindow')
            ->with(new Transform3D(position: new Vec3(0.0, $wheelR, 0.0)))
            ->with(new MeshRenderer(meshId: $this->rearWindowMeshId(), materialId: $glass));

        // Side windows are flat boxes pushed slightly outboard of the cabin
        // wall (z = ±halfBodyWidth + tiny offset) so they read as "stuck on"
        // glass without z-fighting against the body cap.
        $cabinMidX = ($g['cabinFrontTopX'] + $g['cabinRearTopX']) * 0.5;
        $beltLine = $g['hoodHeight'] + ($g['cabinHeight'] - $g['hoodHeight']) * 0.35;
        $glassH = max($g['cabinHeight'] - $beltLine - 0.05, 0.15);
        $glassY = $beltLine + $glassH / 2.0 + $wheelR;
        $sideZ = $g['halfBodyWidth'] + 0.005;

        $car->child('SideWindow_L')
            ->with(new Transform3D(position: new Vec3($cabinMidX, $glassY, +$sideZ)))
            ->with(new MeshRenderer(meshId: $this->sideWindowMeshId(), materialId: $glass));

        $car->child('SideWindow_R')
            ->with(new Transform3D(position: new Vec3($cabinMidX, $glassY, -$sideZ)))
            ->with(new MeshRenderer(meshId: $this->sideWindowMeshId(), materialId: $glass));
    }

    protected function attachBumpers(EntityDeclaration $car, float $wheelR): void
    {
        $g = $this->geometry();
        $bumperY = $wheelR + 0.18;

        $car->child('BumperFront')
            ->with(new Transform3D(position: new Vec3($g['halfBodyLength'] + 0.04, $bumperY, 0.0)))
            ->with(new MeshRenderer(meshId: $this->bumperFrontMeshId(), materialId: $this->bumperMaterial));

        $car->child('BumperRear')
            ->with(new Transform3D(position: new Vec3(-$g['halfBodyLength'] - 0.04, $bumperY, 0.0)))
            ->with(new MeshRenderer(meshId: $this->bumperRearMeshId(), materialId: $this->bumperMaterial));
    }

    protected function attachLights(EntityDeclaration $car, float $wheelR): void
    {
        $g = $this->geometry();
        $lightY = $wheelR + $g['hoodHeight'] - 0.10;
        $lightZ = $g['halfBodyWidth'] * 0.7;

        $car->child('Headlight_L')
            ->with(new Transform3D(position: new Vec3($g['halfBodyLength'] - 0.06, $lightY, +$lightZ)))
            ->with(new MeshRenderer(meshId: $this->headlightMeshId(), materialId: $this->headlightMaterial));

        $car->child('Headlight_R')
            ->with(new Transform3D(position: new Vec3($g['halfBodyLength'] - 0.06, $lightY, -$lightZ)))
            ->with(new MeshRenderer(meshId: $this->headlightMeshId(), materialId: $this->headlightMaterial));

        $car->child('Taillight_L')
            ->with(new Transform3D(position: new Vec3(-$g['halfBodyLength'] + 0.06, $lightY, +$lightZ)))
            ->with(new MeshRenderer(meshId: $this->taillightMeshId(), materialId: $this->taillightMaterial));

        $car->child('Taillight_R')
            ->with(new Transform3D(position: new Vec3(-$g['halfBodyLength'] + 0.06, $lightY, -$lightZ)))
            ->with(new MeshRenderer(meshId: $this->taillightMeshId(), materialId: $this->taillightMaterial));
    }

    protected function attachPlates(EntityDeclaration $car, float $wheelR): void
    {
        $g = $this->geometry();
        $plateY = $wheelR + 0.20;

        $car->child('PlateFront')
            ->with(new Transform3D(position: new Vec3($g['halfBodyLength'] + 0.13, $plateY, 0.0)))
            ->with(new MeshRenderer(meshId: $this->plateMeshId(), materialId: $this->plateMaterial));

        $car->child('PlateRear')
            ->with(new Transform3D(position: new Vec3(-$g['halfBodyLength'] - 0.13, $plateY, 0.0)))
            ->with(new MeshRenderer(meshId: $this->plateMeshId(), materialId: $this->plateMaterial));
    }

    protected function attachMirrors(EntityDeclaration $car, float $wheelR): void
    {
        $g = $this->geometry();
        $mirrorMaterial = $this->mirrorMaterial ?? $this->bodyMaterial;
        $mirrorY = $wheelR + $g['hoodHeight'] + 0.12;
        $mirrorX = $g['cabinFrontTopX'] - 0.05;
        $mirrorZ = $g['halfBodyWidth'] + 0.10;

        $car->child('Mirror_L')
            ->with(new Transform3D(position: new Vec3($mirrorX, $mirrorY, +$mirrorZ)))
            ->with(new MeshRenderer(meshId: $this->mirrorMeshId(), materialId: $mirrorMaterial));

        $car->child('Mirror_R')
            ->with(new Transform3D(position: new Vec3($mirrorX, $mirrorY, -$mirrorZ)))
            ->with(new MeshRenderer(meshId: $this->mirrorMeshId(), materialId: $mirrorMaterial));
    }

    protected function attachGrille(EntityDeclaration $car, float $wheelR): void
    {
        $g = $this->geometry();
        $grilleY = $wheelR + $g['hoodHeight'] - 0.20;

        $car->child('Grille')
            ->with(new Transform3D(position: new Vec3($g['halfBodyLength'] + 0.02, $grilleY, 0.0)))
            ->with(new MeshRenderer(meshId: $this->grilleMeshId(), materialId: $this->grilleMaterial));
    }

    protected function attachDoorHandles(EntityDeclaration $car, float $wheelR): void
    {
        $g = $this->geometry();
        $beltLine   = $g['hoodHeight'] + ($g['cabinHeight'] - $g['hoodHeight']) * 0.35;
        $handleY    = $wheelR + $beltLine - 0.04;
        $handleX    = ($g['cabinFrontTopX'] + $g['cabinRearTopX']) * 0.5;
        $sideZ      = $g['halfBodyWidth'];

        // The DoorHandleMesh is generated from a unit-normalised SVG, so
        // scale uniformly to a realistic ~18 cm pull-bar. With the SVG's
        // 100×~22 viewport ratio, uniform 0.18 gives 18 cm × 4.3 cm ×
        // 2.7 cm, which sticks ~1.3 cm out of the door panel after the
        // mesh's Z-centred extrusion sits flush on the cabin side wall.
        $handleScale = new Vec3(0.18, 0.18, 0.18);

        $car->child('DoorHandle_L')
            ->with(new Transform3D(
                position: new Vec3($handleX, $handleY, +$sideZ),
                scale:    $handleScale,
            ))
            ->with(new MeshRenderer(meshId: $this->doorHandleMeshId(), materialId: $this->doorHandleMaterial ?? $this->bumperMaterial));

        // Right-side: rotate 180° around Y so the handle's outboard face
        // points toward -Z (away from the body).
        $flipY = Quaternion::fromAxisAngle(new Vec3(0, 1, 0), M_PI);

        $car->child('DoorHandle_R')
            ->with(new Transform3D(
                position: new Vec3($handleX, $handleY, -$sideZ),
                rotation: $flipY,
                scale:    $handleScale,
            ))
            ->with(new MeshRenderer(meshId: $this->doorHandleMeshId(), materialId: $this->doorHandleMaterial ?? $this->bumperMaterial));
    }

    protected function attachExhaust(EntityDeclaration $car, float $wheelR): void
    {
        $g = $this->geometry();
        // Tail pipe sits just below the rear bumper, slightly off-centre
        // toward the right (driver's side in LHD markets). The cylinder
        // mesh is Y-aligned, so we rotate 90° around Z to point along +X.
        $exhaustX = -$g['halfBodyLength'] - 0.05;
        $exhaustY = $wheelR * 0.55;
        $exhaustZ = $g['halfBodyWidth'] * 0.5;
        $rotation = Quaternion::fromAxisAngle(new Vec3(0.0, 0.0, 1.0), M_PI_2);

        $car->child('Exhaust')
            ->with(new Transform3D(position: new Vec3($exhaustX, $exhaustY, -$exhaustZ), rotation: $rotation))
            ->with(new MeshRenderer(meshId: $this->exhaustMeshId(), materialId: $this->exhaustMaterial ?? $this->bumperMaterial));
    }

    protected function attachWheels(EntityDeclaration $car, float $halfL, float $halfW, float $wheelR, Quaternion $wheelRotation): void
    {
        $offsets = [
            new Vec3( $halfL, $wheelR,  $halfW),
            new Vec3( $halfL, $wheelR, -$halfW),
            new Vec3(-$halfL, $wheelR,  $halfW),
            new Vec3(-$halfL, $wheelR, -$halfW),
        ];

        // The SVG-derived TireMesh has raw outer radius 30 and width 20
        // (axis along Y). Scale into world units along the radial axes
        // (X, Z) and axial direction (Y) independently so non-cylindrical
        // tires with custom width / radius ratios still look correct.
        $tireScale = new Vec3(
            $wheelR             / 30.0, // X: radial
            $this->wheelWidth() / 20.0, // Y: axial (width)
            $wheelR             / 30.0, // Z: radial
        );

        foreach ($offsets as $i => $offset) {
            // Wheel is a pure container — no MeshRenderer here. Tire and
            // Rim are independent children so the tire can be non-uniformly
            // scaled (radius vs width) without warping the rim's spokes.
            $wheel = $car->child("Wheel_{$i}")
                ->with(new Transform3D(position: $offset, rotation: $wheelRotation));

            $wheel->child("Tire_{$i}")
                ->with(new Transform3D(scale: $tireScale))
                ->with(new MeshRenderer(meshId: $this->tireMeshId(), materialId: $this->tireMaterial));

            // Rim is sized in absolute world units by SpokedRimMesh, so
            // its transform stays at identity.
            $wheel->child("Rim_{$i}")
                ->with(new Transform3D())
                ->with(new MeshRenderer(meshId: $this->rimMeshId(), materialId: $this->rimMaterial));
        }
    }

    // -- Mesh registration ----------------------------------------------

    private function registerMeshes(): void
    {
        /** @var array<string, \Closure(): MeshData> $entries */
        $entries = [
            $this->bodyMeshId()        => fn(): MeshData => $this->buildBody(),
            $this->windshieldMeshId()  => fn(): MeshData => $this->buildWindshield(),
            $this->rearWindowMeshId()  => fn(): MeshData => $this->buildRearWindow(),
            $this->sideWindowMeshId()  => fn(): MeshData => $this->buildSideWindow(),
            $this->bumperFrontMeshId() => fn(): MeshData => $this->buildBumperFront(),
            $this->bumperRearMeshId()  => fn(): MeshData => $this->buildBumperRear(),
            $this->headlightMeshId()   => fn(): MeshData => $this->buildHeadlight(),
            $this->taillightMeshId()   => fn(): MeshData => $this->buildTaillight(),
            $this->plateMeshId()       => fn(): MeshData => $this->buildPlate(),
            $this->mirrorMeshId()      => fn(): MeshData => $this->buildMirror(),
            $this->tireMeshId()        => fn(): MeshData => $this->buildTire(),
            $this->rimMeshId()         => fn(): MeshData => $this->buildRim(),
            $this->exhaustMeshId()     => fn(): MeshData => $this->buildExhaust(),
            $this->doorHandleMeshId()  => fn(): MeshData => $this->buildDoorHandle(),
            $this->grilleMeshId()      => fn(): MeshData => $this->buildGrille(),
        ];

        foreach ($entries as $id => $factory) {
            if (!MeshRegistry::has($id)) {
                MeshRegistry::register($id, $factory());
            }
        }
    }

    // -- Geometry cache --------------------------------------------------

    /**
     * Resolve all dependent silhouette / wheel positions from the current
     * chassis + roof config in one place. Returned values are in body
     * mesh-local space (y=0 at the bottom of the body silhouette, before
     * the wheel-radius lift is applied to the Body child entity).
     *
     * @return array{
     *     length: float, bodyHeight: float, width: float,
     *     halfBodyLength: float, halfBodyWidth: float,
     *     hoodHeight: float, cabinHeight: float, trunkHeight: float,
     *     hoodRearX: float, cabinFrontTopX: float, cabinRearTopX: float, trunkFrontX: float,
     *     wheelRadius: float, halfWheelbase: float, halfWheelTrack: float
     * }
     */
    protected function geometry(): array
    {
        [$length, $bodyHeight, $width] = $this->bodyDimensions();
        $profile = $this->bodyProfile();
        $cabinHeightFrac = $this->roof === CarRoof::Convertible ? 0.55 : 1.0;

        $hoodHeight  = $bodyHeight * $profile['hoodHeightFrac'];
        $cabinHeight = $bodyHeight * max($cabinHeightFrac, $profile['hoodHeightFrac'] + 0.05);
        $trunkHeight = $bodyHeight * $profile['trunkHeightFrac'];

        $halfL = $length / 2.0;
        $hoodRearX      =  $halfL - $length * $profile['hoodLengthFrac'];
        $cabinFrontTopX = $hoodRearX - $length * $profile['windshieldSlopeFrac'];
        $cabinRearTopX  = $cabinFrontTopX - $length * $profile['cabinLengthFrac'];
        $trunkFrontX    = max($cabinRearTopX - $length * $profile['rearWindowSlopeFrac'], -$halfL);

        $wheelR = $this->wheelRadius();

        return [
            'length'         => $length,
            'bodyHeight'     => $bodyHeight,
            'width'          => $width,
            'halfBodyLength' => $halfL,
            'halfBodyWidth'  => $width / 2.0,
            'hoodHeight'     => $hoodHeight,
            'cabinHeight'    => $cabinHeight,
            'trunkHeight'    => $trunkHeight,
            'hoodRearX'      => $hoodRearX,
            'cabinFrontTopX' => $cabinFrontTopX,
            'cabinRearTopX'  => $cabinRearTopX,
            'trunkFrontX'    => $trunkFrontX,
            'wheelRadius'    => $wheelR,
            'halfWheelbase'  => $length * $this->wheelbaseFraction() / 2.0,
            'halfWheelTrack' => $width / 2.0 + $this->wheelWidth() / 2.0,
        ];
    }
}
