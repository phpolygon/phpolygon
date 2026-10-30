<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\SurfaceText;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Locale\LocaleManager;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Text\SurfaceTextBaker;
use PHPolygon\Rendering\Text\SurfaceTextStoreInterface;
use PHPolygon\Rendering\Texture;
use PHPolygon\System\SurfaceTextSystem;
use PHPolygon\Testing\NullTextureManager;

/**
 * The system runs on a store that has every texture, so it needs no php-vio.
 */
final class SurfaceTextSystemTest extends TestCase
{
    private LocaleManager $locale;

    private NullTextureManager $textures;

    private SurfaceTextBaker $baker;

    protected function setUp(): void
    {
        MaterialRegistry::clear();
        $this->locale = new LocaleManager('de', 'en');
        $this->locale->add('de', ['sign.open' => 'Offen', 'sign.exit' => 'Ausgang']);
        $this->locale->add('fr', ['sign.open' => 'Ouvert', 'sign.exit' => 'Sortie']);
        $this->textures = new class extends NullTextureManager {
            public int $loads = 0;

            /** @var list<string> */
            public array $unloaded = [];

            public function load(string $id, ?string $path = null): Texture
            {
                $this->loads++;
                return parent::load($id, $path);
            }

            public function unload(string $id): void
            {
                $this->unloaded[] = $id;
                parent::unload($id);
            }
        };
        $store = new class implements SurfaceTextStoreInterface {
            public function path(string $textureId): ?string
            {
                return "/baked/{$textureId}.ktx2";
            }

            public function store(string $textureId, string $rgba, int $width, int $height): void {}
        };
        $this->baker = new SurfaceTextBaker($this->locale, $this->textures, $store);
    }

    protected function tearDown(): void
    {
        MaterialRegistry::clear();
    }

    private function system(float $budget = 1000.0): SurfaceTextSystem
    {
        return new SurfaceTextSystem($this->locale, $this->baker, $budget);
    }

    /** @return array{0: MeshRenderer, 1: SurfaceText} */
    private function sign(World $world, string $key): array
    {
        $entity = $world->createEntity();
        $mesh = new MeshRenderer('quad_unit', 'placeholder', castShadows: false);
        $surface = new SurfaceText(textKey: $key);
        $world->attachComponent($entity->id, new Transform3D());
        $world->attachComponent($entity->id, $mesh);
        $world->attachComponent($entity->id, $surface);

        return [$mesh, $surface];
    }

    public function testNewSurfaceGetsItsTextureMaterial(): void
    {
        $world = new World();
        [$mesh, $surface] = $this->sign($world, 'sign.open');

        $this->system()->update($world, 0.016);

        $material = MaterialRegistry::get($mesh->materialId);
        self::assertNotNull($material);
        self::assertSame($this->baker->textureId($surface, 'de'), $material->albedoTexture);
        self::assertSame($material->albedoTexture, $surface->textureId);
    }

    public function testLanguageChangeSwitchesTheMaterialOnceWithoutTouchingTheWorld(): void
    {
        $world = new World();
        [$mesh, $surface] = $this->sign($world, 'sign.open');
        $system = $this->system();
        $system->update($world, 0.016);
        $german = $mesh->materialId;
        $loads = $this->textures->loads;
        $entities = $world->entityCount();

        // Nothing to do while nothing changes.
        $system->update($world, 0.016);
        self::assertSame($loads, $this->textures->loads);

        $this->locale->setLocale('fr');
        $system->update($world, 0.016);

        self::assertNotSame($german, $mesh->materialId);
        self::assertSame($this->baker->textureId($surface, 'fr'), MaterialRegistry::get($mesh->materialId)?->albedoTexture);
        self::assertSame($loads + 1, $this->textures->loads);
        self::assertSame($entities, $world->entityCount());

        $system->update($world, 0.016);
        self::assertSame($loads + 1, $this->textures->loads, 'once');
    }

    public function testOldLanguageIsFreedOnlyAfterFramesInFlightAreDone(): void
    {
        $world = new World();
        [$mesh, $surface] = $this->sign($world, 'sign.open');
        $system = $this->system();
        $system->update($world, 0.016);
        $system->render($world);
        $german = $surface->textureId;

        $this->locale->setLocale('fr');
        $system->update($world, 0.016);
        $system->render($world);
        self::assertTrue($this->textures->has($german), 'a frame in flight may still read it');

        for ($i = 0; $i < 10; $i++) {
            $system->render($world);
        }
        self::assertFalse($this->textures->has($german));
        self::assertTrue($this->textures->has($surface->textureId));
    }

    public function testSwitchingBackInsideTheWindowKeepsTheTextureShown(): void
    {
        $world = new World();
        [, $surface] = $this->sign($world, 'sign.open');
        $system = $this->system();
        $system->update($world, 0.016);
        $german = $surface->textureId;

        $this->locale->setLocale('fr');
        $system->update($world, 0.016);
        $system->render($world);
        $this->locale->setLocale('de');
        $system->update($world, 0.016);
        for ($i = 0; $i < 10; $i++) {
            $system->render($world);
        }

        self::assertSame($german, $surface->textureId);
        self::assertTrue($this->textures->has($german));
    }

    public function testBudgetSpreadsALanguageChangeOverTicksAndOldTextStaysMeanwhile(): void
    {
        $world = new World();
        $signs = [$this->sign($world, 'sign.open'), $this->sign($world, 'sign.exit')];
        // Budget 0: one surface per tick.
        $system = $this->system(0.0);
        $system->update($world, 0.016);
        $system->update($world, 0.016);
        self::assertNotSame('placeholder', $signs[1][0]->materialId);
        $before = $signs[1][0]->materialId;

        $this->locale->setLocale('fr');
        $system->update($world, 0.016);
        self::assertSame($before, $signs[1][0]->materialId, 'not its turn yet, still the old text');
        self::assertTrue($this->textures->has((string) MaterialRegistry::get($before)?->albedoTexture));

        $system->update($world, 0.016);
        self::assertNotSame($before, $signs[1][0]->materialId);
    }

    public function testEditedTextIsPickedUp(): void
    {
        $world = new World();
        [$mesh, $surface] = $this->sign($world, 'sign.open');
        $system = $this->system();
        $system->update($world, 0.016);
        $before = $mesh->materialId;

        $surface->setText('Changed');
        $system->update($world, 0.016);

        self::assertNotSame($before, $mesh->materialId);
        self::assertSame('', $surface->textKey);
    }

    public function testEqualSignsShareOneMaterialAndOneTexture(): void
    {
        $world = new World();
        [$a] = $this->sign($world, 'sign.open');
        [$b] = $this->sign($world, 'sign.open');

        $this->system()->update($world, 0.016);

        self::assertSame($a->materialId, $b->materialId);
        self::assertSame(1, $this->textures->loads);
    }

    public function testDormantSurfaceCatchesUpWhenItWakes(): void
    {
        $world = new World();
        [$mesh] = $this->sign($world, 'sign.open');
        $entity = 0;
        foreach ($world->componentEntities(SurfaceText::class) as $id) {
            $entity = $id;
        }
        $system = $this->system();
        $system->update($world, 0.016);
        $german = $mesh->materialId;

        $world->setEntityDormant($entity, true);
        $this->locale->setLocale('fr');
        $system->update($world, 0.016);
        self::assertSame($german, $mesh->materialId);

        $world->setEntityDormant($entity, false);
        $system->update($world, 0.016);
        self::assertNotSame($german, $mesh->materialId);
    }

    public function testRetiringKeepsTheTextureOfACurrentDormantSurface(): void
    {
        $world = new World();
        [$mesh, $surface] = $this->sign($world, 'sign.open');
        $entity = (int) array_key_first($world->componentPool(SurfaceText::class));
        $system = $this->system();
        $system->update($world, 0.016);
        $world->setEntityDormant($entity, true);

        // A new surface elsewhere finishes a pass and frees what nothing shows.
        $this->sign($world, 'sign.exit');
        $system->update($world, 0.016);
        for ($i = 0; $i < 10; $i++) {
            $system->render($world);
        }

        self::assertTrue($this->textures->has($surface->textureId));
        self::assertSame($surface->textureId, MaterialRegistry::get($mesh->materialId)?->albedoTexture);
    }

    /**
     * Quick clicking through the languages while the budget stretches each pass
     * over several ticks: no texture may go sooner than the frames in flight
     * after a surface last showed it, and none while a pass runs.
     */
    public function testRapidLanguageChangesNeverFreeATextureTooEarly(): void
    {
        $this->locale->add('it', ['sign.open' => 'Aperto', 'sign.exit' => 'Uscita']);
        $world = new World();
        $this->locale->add('de', ['sign.help' => 'Hilfe']);
        $this->locale->add('fr', ['sign.help' => 'Aide']);
        $this->locale->add('it', ['sign.help' => 'Aiuto']);
        $signs = [$this->sign($world, 'sign.open'), $this->sign($world, 'sign.exit'), $this->sign($world, 'sign.help')];
        $system = $this->system(0.0);
        $lastShown = [];
        $frame = 0;
        $tick = function () use ($world, $system, $signs, &$lastShown, &$frame): void {
            $frame++;
            $before = $this->textures->unloaded;
            $system->update($world, 0.016);
            foreach ($signs as [, $surface]) {
                $lastShown[$surface->textureId] = $frame;
            }
            $system->render($world);
            foreach (array_slice($this->textures->unloaded, count($before)) as $id) {
                self::assertGreaterThanOrEqual(8, $frame - ($lastShown[$id] ?? $frame), 'freed too soon after it was shown');
            }
        };

        for ($i = 0; $i < 12; $i++) {
            $tick();
        }
        foreach (['fr', 'it', 'de', 'fr', 'it'] as $language) {
            $this->locale->setLocale($language);
            // Three ticks for the pass, six idle: the next pass starts one frame
            // before the window of this one runs out, and that window ends inside it.
            for ($i = 0; $i < 9; $i++) {
                $tick();
            }
        }
        for ($i = 0; $i < 12; $i++) {
            $tick();
        }

        self::assertNotSame([], $this->textures->unloaded, 'the old languages do go in the end');
    }

    public function testNothingIsFreedWhileAPassRuns(): void
    {
        $world = new World();
        $this->sign($world, 'sign.open');
        $this->sign($world, 'sign.exit');
        $system = $this->system(0.0);
        $system->prewarm($world);
        for ($i = 0; $i < 7; $i++) {
            $system->render($world);
        }

        // One surface per tick: the window of the first pass ends inside this one.
        $this->locale->setLocale('fr');
        $system->update($world, 0.016);
        for ($i = 0; $i < 10; $i++) {
            $system->render($world);
        }
        self::assertSame([], $this->textures->unloaded, 'the pass is still open');

        $system->update($world, 0.016);
        for ($i = 0; $i < 10; $i++) {
            $system->render($world);
        }
        self::assertCount(2, $this->textures->unloaded);
    }

    public function testDormantSurfaceWhoseTextureWentIsAPlainPlateNotAWhiteOne(): void
    {
        $world = new World();
        [$mesh, $surface] = $this->sign($world, 'sign.open');
        $this->sign($world, 'sign.exit');
        $entity = (int) array_key_first($world->componentPool(SurfaceText::class));
        $system = $this->system();
        $system->update($world, 0.016);
        $german = $surface->textureId;
        $world->setEntityDormant($entity, true);

        $this->locale->setLocale('fr');
        $system->update($world, 0.016);
        for ($i = 0; $i < 10; $i++) {
            $system->render($world);
        }

        self::assertFalse($this->textures->has($german));
        self::assertSame(SurfaceTextBaker::plainMaterial($surface), $mesh->materialId);
        self::assertSame('', $surface->textureId);

        $world->setEntityDormant($entity, false);
        $system->update($world, 0.016);
        self::assertSame($this->baker->textureId($surface, 'fr'), $surface->textureId);
    }

    public function testPrewarmIgnoresTheBudget(): void
    {
        $world = new World();
        [$a] = $this->sign($world, 'sign.open');
        [$b] = $this->sign($world, 'sign.exit');

        $this->system(0.0)->prewarm($world);

        self::assertNotSame('placeholder', $a->materialId);
        self::assertNotSame('placeholder', $b->materialId);
    }
}
