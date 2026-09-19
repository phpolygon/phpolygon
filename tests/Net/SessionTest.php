<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Net;

use PHPUnit\Framework\TestCase;
use PHPolygon\Command\Command;
use PHPolygon\Command\CommandBus;
use PHPolygon\Command\CommandRegistry;
use PHPolygon\Command\Refusal;
use PHPolygon\Net\HostSession;
use PHPolygon\Net\ClientSession;
use PHPolygon\Net\LoopbackTransport;
use PHPolygon\Net\Player;
use PHPolygon\Net\StateChannel;

/** The little game these sessions play: a number and what it was told. */
final class Tally
{
    public int $value = 0;

    /** @var list<string> */
    public array $told = [];

    /** @var list<string> news not yet passed on */
    public array $news = [];
}

final class Add extends Command
{
    public function __construct(public readonly int $amount) {}

    public static function type(): string { return 'tally.add'; }
}

/** The game's side of a session: what a state is, and what a player is told. */
final class TallyChannel implements StateChannel
{
    public function capture(object $state): array
    {
        assert($state instanceof Tally);
        return ['value' => $state->value];
    }

    public function restore(object $state, array $data): void
    {
        assert($state instanceof Tally);
        $state->value = is_int($data['value'] ?? null) ? $data['value'] : 0;
    }

    public function news(object $state): array
    {
        assert($state instanceof Tally);
        $news = $state->news;
        $state->news = [];
        return $news === [] ? [] : ['lines' => $news];
    }

    public function showNews(object $state, array $news): void
    {
        assert($state instanceof Tally);
        foreach (is_array($news['lines'] ?? null) ? $news['lines'] : [] as $line) {
            $state->told[] = (string) $line;
        }
    }

    public function refused(object $state, Refusal $refusal): void
    {
        assert($state instanceof Tally);
        $state->told[] = $refusal->key;
    }
}

/**
 * A host and its partners over the in-process network: a partner's click
 * becomes a command, the host decides, and everyone sees the same game.
 */
final class SessionTest extends TestCase
{
    private Tally $hostState;
    private LoopbackTransport $hostEnd;
    private HostSession $host;
    private CommandBus $bus;
    private CommandRegistry $registry;

    protected function setUp(): void
    {
        $this->hostState = new Tally();
        $this->registry = new CommandRegistry([Add::class]);
        $this->bus = $this->makeBus();
        $this->hostEnd = LoopbackTransport::host();
        $this->host = new HostSession(
            transport: $this->hostEnd,
            state: $this->hostState,
            hostName: 'Host',
            bus: $this->bus,
            registry: $this->registry,
            channel: new TallyChannel(),
            version: '1.0.0',
            maxPlayers: 3,
        );
    }

    private function makeBus(): CommandBus
    {
        return new CommandBus(
            check: static fn (object $state, Command $c): ?Refusal => $c instanceof Add && $c->amount > 100
                ? new Refusal('tally.too_much')
                : null,
            apply: static function (object $state, Command $c): void {
                assert($state instanceof Tally && $c instanceof Add);
                $state->value += $c->amount;
            },
            refused: static function (object $state, Refusal $r): void {
                assert($state instanceof Tally);
                $state->told[] = $r->key;
            },
        );
    }

    /** @param array<string, mixed> $hello */
    private function join(string $name = 'Ada', int $account = 42, array $hello = [], string $version = '1.0.0'): ClientSession
    {
        $client = new ClientSession(
            transport: $this->hostEnd->connectClient($account),
            state: new Tally(),
            name: $name,
            bus: $this->makeBus(),
            registry: $this->registry,
            channel: new TallyChannel(),
            version: $version,
            hello: $hello,
        );
        $this->settle($client);
        return $client;
    }

    private function settle(ClientSession ...$clients): void
    {
        for ($i = 0; $i < 6; $i++) {
            foreach ($clients as $client) {
                $client->tick(0.1);
            }
            $this->host->tick(HostSession::STATE_INTERVAL);
        }
        foreach ($clients as $client) {
            $client->tick(0.1);
        }
    }

    public function testAPartnerJoinsAndSeesTheHostsGame(): void
    {
        $this->hostState->value = 7;
        $client = $this->join();

        $this->assertTrue($client->isReady());
        $this->assertSame('Host', $client->hostName());
        $this->assertSame(7, $client->state()->value);
        $this->assertCount(1, $this->host->players());
        $this->assertSame('Ada', $this->host->players()[0]->name);
        $this->assertSame(42, $this->host->players()[0]->account);
    }

    public function testWhatAPartnerSaidWhenJoiningReachesTheHost(): void
    {
        $this->join('Ada', 42, ['company' => 'Ada Soft']);

        $this->assertSame(['company' => 'Ada Soft'], $this->host->players()[0]->hello);
    }

    public function testAPartnersCommandIsAppliedByTheHost(): void
    {
        $client = $this->join();

        $this->assertTrue($this->bus->isRemote() === false, 'the host applies its own');
        $client->sendCommand(new Add(5));
        $this->settle($client);

        $this->assertSame(5, $this->hostState->value);
        $this->assertSame(5, $client->state()->value);
    }

    public function testARefusalReachesOnlyThePartnerWhoAsked(): void
    {
        $a = $this->join('Ada', 1);
        $b = $this->join('Bo', 2);

        $a->sendCommand(new Add(500));
        $this->settle($a, $b);

        $this->assertSame(['tally.too_much'], $a->state()->told);
        $this->assertSame([], $b->state()->told);
        $this->assertSame(0, $this->hostState->value);
    }

    public function testNewsGoesOutOnceToEveryone(): void
    {
        $a = $this->join('Ada', 1);
        $b = $this->join('Bo', 2);
        $this->hostState->news[] = 'a day passed';

        $this->settle($a, $b);
        $this->settle($a, $b);

        $this->assertSame(['a day passed'], $a->state()->told);
        $this->assertSame(['a day passed'], $b->state()->told);
    }

    public function testAPartnerOnAnotherVersionIsTurnedAway(): void
    {
        $client = $this->join('Ada', 1, [], '0.9.0');

        $this->assertSame(ClientSession::CLOSED, $client->status());
        $this->assertSame('version', $client->closeReason());
        $this->assertSame([], $this->host->players());
    }

    public function testAFullSessionTurnsTheNextOneAway(): void
    {
        $this->join('Ada', 1);
        $this->join('Bo', 2);
        $late = $this->join('Cy', 3);

        $this->assertSame('full', $late->closeReason());
        $this->assertCount(2, $this->host->players());
    }

    public function testTheHostAndItsPartnersSpeakTheirOwnMessages(): void
    {
        $client = $this->join();
        $seen = [];
        $client->on('roles', static function (array $message) use (&$seen): void { $seen[] = $message['yours'] ?? null; });
        $votes = [];
        $this->host->on('vote', static function (Player $player, array $message) use (&$votes): void {
            $votes[] = [$player->name, $message['approve'] ?? null];
        });

        $this->host->sendTo($this->host->players()[0]->peer, ['t' => 'roles', 'yours' => ['people']]);
        $this->settle($client);
        $client->send('vote', ['approve' => true]);
        $this->settle($client);

        $this->assertSame([['people']], $seen);
        $this->assertSame([['Ada', true]], $votes);
    }

    public function testTheWelcomeCarriesWhatTheHostAddsToIt(): void
    {
        $this->host->setWelcome(static fn (Player $player): array => ['mode' => 'competitive']);
        $client = $this->join();

        $this->assertSame('competitive', $client->welcome()['mode'] ?? null);
        $this->assertSame($client->you(), $this->host->players()[0]->peer);
    }

    public function testEveryPartnerMayPlayAGameOfTheirOwn(): void
    {
        $others = [];
        $this->host->setStates(function (Player $player) use (&$others): object {
            return $others[$player->peer] ??= new Tally();
        });
        $client = $this->join();
        $mine = $this->host->stateOf($this->host->players()[0]);

        $client->sendCommand(new Add(3));
        $this->settle($client);

        $this->assertSame(3, $mine->value);
        $this->assertSame(3, $client->state()->value);
        $this->assertSame(0, $this->hostState->value, 'not the host\'s game');
    }

    public function testTheHostHearsWhatWasApplied(): void
    {
        $applied = [];
        $this->host->setStates(null, static function (Player $player, Command $c) use (&$applied): void {
            $applied[] = [$player->name, $c::type()];
        });
        $client = $this->join();

        $client->sendCommand(new Add(1));
        $this->settle($client);

        $this->assertSame([['Ada', 'tally.add']], $applied);
    }

    public function testWhoMayDoWhatIsTheGamesToSay(): void
    {
        $this->host->setAuthorizer(static fn (Player $player, Command $c): ?Refusal => $player->roles === []
            ? new Refusal('tally.not_yours')
            : null);
        $client = $this->join();

        $client->sendCommand(new Add(2));
        $this->settle($client);

        $this->assertSame(['tally.not_yours'], $client->state()->told);
        $this->assertSame(0, $this->hostState->value);
    }

    public function testAPartnerLeavingIsAnEventTheGameCanSee(): void
    {
        $client = $this->join();
        $this->assertSame('joined', $this->host->drainEvents()[0]['kind']);

        $client->close();
        $this->settle();

        $events = $this->host->drainEvents();
        $this->assertSame('left', $events[0]['kind'] ?? null);
        $this->assertSame([], $this->host->players());
    }
}
