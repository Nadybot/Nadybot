<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE;

use function Safe\json_decode;
use Amp\Http\Client\{HttpClientBuilder, Request};
use Closure;
use EventSauce\ObjectHydrator\UnableToHydrateObject;
use Illuminate\Support\Collection;
use Nadybot\Core\Attributes\{HandlesCommand, HandlesEvent};
use Nadybot\Core\Events\ConnectEvent;
use Nadybot\Core\Routing\{RoutableMessage, Source};
use Nadybot\Core\{Attributes as NCA, CmdContext, Hydrator, MessageHub, ModuleInstance, Safe, Text, Util};
use Nadybot\Modules\WHEREIS_MODULE\{Whereis, WhereisController};
use Psr\Log\LoggerInterface;
use Safe\Exceptions\JsonException;

#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\EmitsMessages('mobs', '*'),
	NCA\DefineCommand(
		command: 'prisoners',
		alias: ['pris'],
		description: 'Get the status of all prisoners',
		accessLevel: 'guest',
	),
	NCA\DefineCommand(
		command: 'hags',
		description: 'Get the status of all Biodome hags',
		accessLevel: 'guest',
	),
	NCA\DefineCommand(
		command: 'dreads',
		description: 'Get the status of all Dreadlochs bosses',
		accessLevel: 'guest',
	),
	NCA\DefineCommand(
		command: 'ljotur',
		description: 'Get the status of Ljotur the Lunatic',
		accessLevel: 'guest',
	),
	NCA\DefineCommand(
		command: 'otacustes',
		alias: ['ota'],
		description: 'Get the status of Otacustes',
		accessLevel: 'guest',
	),
	NCA\DefineCommand(
		command: 'jack',
		alias: ['legchopper'],
		description: 'Get the status of Jack "Leg-chopper" Menendez and his clones',
		accessLevel: 'guest',
	),
	NCA\DefineCommand(
		command: 'reck',
		description: 'Get the status of mobs in The Reck',
		accessLevel: 'guest',
	),
	NCA\DefineCommand(
		command: 'hollowisland',
		alias: ['hollow', 'hi'],
		description: 'Get the status of Hollow Island',
		accessLevel: 'guest',
	),
]
class MobController extends ModuleInstance {
	public const MOB_API = 'https://mobs.aobots.org/api/';

	/** @var array<string,array<string,Mob>> */
	public array $mobs = [];

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Inject]
	private WhereisController $whereisCtrl;

	#[NCA\Inject]
	private MessageHub $msgHub;

	/** Load all mobs from the API */
	#[NCA\HandlesEvent]
	public function initMobsFromApi(?ConnectEvent $event=null): void {
		$client = $this->builder->build();

		$response = $client->request(new Request(self::MOB_API));
		if ($response->getStatus() !== 200) {
			$this->logger->error('Error calling the mob-api: HTTP-code {code}', [
				'code' => $response->getStatus(),
			]);
			return;
		}
		$body = $response->getBody()->buffer();

		try {
			/** @var array<string,list<array<string,mixed>>> */
			$json = json_decode($body, true);

			$this->mobs = [];
			foreach ($json as $type => $entries) {
				$this->mobs[$type] = [];

				$mobs = Hydrator::hydrateObjects(Mob::class, $entries)->getIterator();
				foreach ($mobs as $mob) {
					$this->mobs[$type][$mob->key] = $mob;
				}
			}
		} catch (JsonException $e) {
			$this->logger->error('Invalid mob-data received: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			return;
		} catch (UnableToHydrateObject $e) {
			$this->logger->error('Unable to parse mob-api: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}

	/** Load the data for a single mob from the API */
	public function loadMobFromApi(string $type, string $key): void {
		$client = $this->builder->build();

		$response = $client->request(new Request(self::MOB_API . $type));
		if ($response->getStatus() !== 200) {
			$this->logger->error('Error calling the mob-api: HTTP-code {code}', [
				'code' => $response->getStatus(),
			]);
			return;
		}
		$body = $response->getBody()->buffer();

		try {
			/** @var array<string,list<array<string,mixed>>> */
			$json = json_decode($body, true);

			foreach ($json as $entry) {
				$mobs = Hydrator::hydrateObjects(Mob::class, $entry)->getIterator();

				foreach ($mobs as $mob) {
					if ($mob->key === $key) {
						$this->mobs[$type] ??= [];
						$this->mobs[$type][$key] = $mob;
					}
				}
			}
		} catch (JsonException $e) {
			$this->logger->error('Invalid mob-data received: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			return;
		} catch (UnableToHydrateObject $e) {
			$this->logger->error('Unable to parse mob-api: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}

	/** Announce when a mob gets attacked as mob(&lt;type&gt;-&lt;key&gt;-attacked) */
	#[HandlesEvent]
	public function announceMobAttacked(MobAttackedEvent $event): void {
		$mob = $event->mob;
		$blob = Text::makeChatcmd(
			"{$mob->x}x{$mob->y} {$mob->playfield->short()}",
			"/waypoint {$mob->x} {$mob->y} {$mob->playfield->value}"
		);
		$msg = "<highlight>{$mob->name}<end> is being attacked in ".
			Text::makeBlob(
				$mob->playfield->long(),
				$blob,
				"{$mob->name} waypoint",
			) . '.';
		$rMsg = new RoutableMessage($msg);
		$rMsg->prependPath(new Source('mobs', "{$mob->type}-{$mob->key}-attacked"));
		$this->msgHub->handle($rMsg);
	}

	/** Announce when a new mob spawns as mob(&lt;type&gt;-&lt;key&gt;-spawn) */
	#[HandlesEvent]
	public function announceMobSpawn(MobSpawnEvent $event): void {
		$mob = $event->mob;
		$blob = Text::makeChatcmd(
			"{$mob->x}x{$mob->y} {$mob->playfield->short()}",
			"/waypoint {$mob->x} {$mob->y} {$mob->playfield->value}"
		);
		$msg = "<highlight>{$mob->name}<end> has spawned in ".
			Text::makeBlob(
				$mob->playfield->long(),
				$blob,
				"{$mob->name} waypoint",
			) . '.';
		$rMsg = new RoutableMessage($msg);
		$rMsg->prependPath(new Source('mobs', "{$mob->type}-{$mob->key}-spawn"));
		$this->msgHub->handle($rMsg);
	}

	/** Announce when a mob gets killed as mob(&lt;type&gt;-&lt;key&gt;-death) */
	#[HandlesEvent]
	public function announceMobDeath(MobDeathEvent $event): void {
		$mob = $event->mob;
		$blob = Text::makeChatcmd(
			"{$mob->x}x{$mob->y} {$mob->playfield->short()}",
			"/waypoint {$mob->x} {$mob->y} {$mob->playfield->value}"
		);
		$msg = "<highlight>{$mob->name}<end> was killed in ".
			Text::makeBlob(
				$mob->playfield->long(),
				$blob,
				"{$mob->name} waypoint",
			) . '.';
		if (isset($mob->respawn_timer)) {
			$msg .= ' Respawn will be in '.
				Util::unixtimeToReadable($mob->respawn_timer) . '.';
		}
		$rMsg = new RoutableMessage($msg);
		$rMsg->prependPath(new Source('mobs', "{$mob->type}-{$mob->key}-death"));
		$this->msgHub->handle($rMsg);
	}

	#[
		HandlesCommand('prisoners'),
		NCA\Help\Group('mobs'),
	]
	/** Show which of the prisoners in Milky Way is up or down */
	public function showPrisonersCommand(CmdContext $context): void {
		/** @var Collection<int,string> */
		$blobs = (new Collection(array_values($this->mobs[Mob::T_PRISONER]??[])))
			->sortBy('name')
			->map(Closure::fromCallable($this->renderMob(...)));
		if ($blobs->isEmpty()) {
			$context->reply('There is currently no data for any prisoner. Maybe the API is down.');
			return;
		}
		$msg = Text::makeBlob(
			'Status of all prisoners (' . $blobs->count() . ')',
			$blobs->join("\n\n")
		);
		$context->reply($msg);
	}

	#[
		HandlesCommand('hags'),
		NCA\Help\Group('mobs'),
	]
	/** Show which Biodome hag is up or down */
	public function showHagsCommand(
		CmdContext $context,
		#[NCA\Parameter\StrChoice('clan', 'omni')] ?string $type
	): void {
		/** @var Collection<string,Collection<int,Mob>> */
		$factions = (new Collection(array_values($this->mobs[Mob::T_HAG]??[])))
			->sortBy('name')
			->groupBy(static function (Mob $mob): string {
				return explode('-', $mob->key)[0];
			});

		if (isset($type)) {
			/** @var Collection<string,Collection<int,Mob>> */
			$factions = new Collection([$type => $factions->get($type)]);
			if ($factions->get($type, new Collection())->isEmpty()) {
				$context->reply("There is currently no data for any {$type} hags. Maybe the API is down.");
				return;
			}
		} elseif ($factions->isEmpty()) {
			$context->reply('There is currently no data for any hags. Maybe the API is down.');
			return;
		}

		/** @param Collection<int,Mob> $hags */
		$blobs = $factions->map(function (Collection $hags, string $faction): string {
			return Text::makeBlob(
				ucfirst($faction) . ' hags (' . $hags->count() . ')',
				$hags->map($this->renderMob(...))->join("\n\n")
			);
		});
		$msg = 'Status of all ' . $blobs->join(' and ') . '.';
		$context->reply($msg);
	}

	#[
		HandlesCommand('dreads'),
		NCA\Help\Group('mobs'),
	]
	/** Show which Dreadloch mob is up or down */
	public function showDreadsCommand(
		CmdContext $context,
		#[NCA\Parameter\StrChoice('clan', 'omni')] ?string $type
	): void {
		$sides = [
			'pthunder' => 'omni',
			'woon' => 'omni',
			'moxy' => 'omni',
			'frc-191' => 'omni',
			'pax' => 'omni',
			'crux' => 'clan',
			'sleek' => 'clan',
			'swan' => 'clan',
			'deko' => 'clan',
			'cthunder' => 'clan',
		];

		/** @var Collection<string,Collection<int,Mob>> */
		$factions = (new Collection(array_values($this->mobs[Mob::T_DREAD]??[])))
			->sortBy('name')
			->groupBy(static function (Mob $mob) use ($sides): string {
				return $sides[$mob->key] ?? 'unknown';
			});
		if (isset($type)) {
			/** @var Collection<string,Collection<int,Mob>> */
			$factions = new Collection([$type => $factions->get($type)]);
			if ($factions->get($type, new Collection())->isEmpty()) {
				$context->reply("There is currently no data for any {$type} Dreadloch camp. Maybe the API is down.");
				return;
			}
		} elseif ($factions->isEmpty()) {
			$context->reply('There is currently no data for any Dreadloch camp. Maybe the API is down.');
			return;
		}
		$blobs = $factions->map(function (Collection $dreads, string $faction): string {
			return Text::makeBlob(
				ucfirst($faction) . ' Dreadloch camps (' . $dreads->count() . ')',
				$dreads->map(Closure::fromCallable($this->renderMob(...)))->join("\n\n")
			);
		});
		$msg = 'Status of all ' . $blobs->join(' and ') . '.';
		$context->reply($msg);
	}

	#[
		HandlesCommand('jack'),
		NCA\Help\Group('mobs'),
	]
	/** Show which of Jack's clones is currently up */
	public function showLegchopperCommand(CmdContext $context): void {
		/** @var Collection<int,string> */
		$blobs = (new Collection(array_values($this->mobs[Mob::T_LEGCHOPPER]??[])))
			->sortBy('name')
			->sort(static function (Mob $a, Mob $b): int {
				return $a->key === 'jack'
					? -1
					: ($b->key === 'jack' ? 1 : 0);
			})
			->map(Closure::fromCallable($this->renderMob(...)));
		if ($blobs->isEmpty()) {
			$context->reply('There is currently no data for Jack Legchopper or his clones. Maybe the API is down.');
			return;
		}
		$msg = Text::makeBlob(
			'Status of Jack and his clones (' . $blobs->count() . ')',
			$blobs->join("\n\n")
		);
		$context->reply($msg);
	}

	#[
		HandlesCommand('ljotur'),
		NCA\Help\Group('mobs'),
	]
	/** Show whether Ljotur the Lunatic, or one of his placeholders are up */
	public function showLjoturCommand(CmdContext $context): void {
		$this->showUniqueCommand($context, 'ljotur', 'Ljtur the Lunatic');
	}

	#[
		HandlesCommand('otacustes'),
		NCA\Help\Group('mobs'),
	]
	/** Show whether Otacustes, or one of his placeholders are up */
	public function showOtacustesCommand(CmdContext $context): void {
		$this->showUniqueCommand($context, 'otacustes', 'Otacustes');
	}

	#[
		HandlesCommand('reck'),
		NCA\Help\Group('mobs'),
	]
	/** Show status of mobs in The Reck */
	public function showReckCommand(CmdContext $context): void {
		/** @var Collection<int,string> */
		$blobs = (new Collection(array_values($this->mobs[Mob::T_RECK]??[])))
			->sortBy('name')
			->map(Closure::fromCallable($this->renderMob(...)));
		if ($blobs->isEmpty()) {
			$context->reply('There is currently no data for mobs in The Reck. Maybe the API is down.');
			return;
		}
		$msg = Text::makeBlob(
			'Status of mobs in The Reck (' . $blobs->count() . ')',
			$blobs->join("\n\n")
		);
		$context->reply($msg);
	}

	#[
		HandlesCommand('hollowisland'),
		NCA\Help\Group('mobs'),
	]
	/** Show the current status of Hollow Island */
	public function showHollowIslandCommand(CmdContext $context): void {
		/** @var Collection<int,Mob> */
		$mobs = new Collection(array_values($this->mobs[Mob::T_HI]??[]));
		if ($mobs->isEmpty()) {
			$context->reply('There is currently no data for Hollow Island. Maybe the API is down.');
			return;
		}
		$mobs = $mobs->keyBy(static fn (Mob $mob): string => $mob->key)->toArray();

		$state = $this->getHiStatus($mobs);
		$blob = '<header2>Hollow Island<end> ['.
			Text::makeChatcmd(
				'2250x650 BF',
				'/waypoint 2250 650 605'
			) . "]\n".
			"<tab>{$state}";

		$msg = Text::makeBlob('Hollow Island', $blob);
		$context->reply($msg);
	}

	public function showUniqueCommand(CmdContext $context, string $key, string $name): void {
		/** @var ?Mob */
		$mob = (new Collection(array_values($this->mobs[Mob::T_UNIQUES]??[])))
			->where('key', $key)
			->first();
		if (!isset($mob)) {
			$context->reply("There is currently no data for {$name}. Maybe the API is down.");
			return;
		}
		$blob = $this->renderMob($mob);
		$msg = Text::makeBlob($mob->name, $blob) . ': ' . $this->renderMobStatus($mob);
		$context->reply($msg);
	}

	/** @param array<string,Mob> $mobs */
	private function getHiStatus(array $mobs): string {
		$sapling = $mobs['sapling'] ?? null;
		$sapKilled = $sapling?->last_killed;
		$nextSapling = null;
		if (isset($sapling, $sapKilled)) {
			$nextSapling = ($sapKilled + ($sapling->respawn_timer ?? 7 * 3_600)) - time();
			if ($nextSapling > 0) {
				$nextSapling = 'in ' . Util::unixtimeToReadable($nextSapling);
			} else {
				$nextSapling = 'any moment now';
			}
		}

		if (isset($sapling) && $sapling->status === Mob::STATUS_UP) {
			return "{$sapling->name}: <on>UP<end>";
		}
		if (
			isset($mobs['weed'])
			&& in_array($mobs['weed']->status, [Mob::STATUS_UP, Mob::STATUS_ATTACKED], true)
		) {
			return 'Weed: ' . $this->renderMobStatus($mobs['weed']);
		}
		for ($i = 10; $i >= 1; $i--) {
			if (isset($mobs["sapling-{$i}"]) && $mobs["sapling-{$i}"]->status === Mob::STATUS_UP) {
				return "Wave {$i} <yellow>RUNNING<end>";
			}
		}
		$state = '<off>COOLDOWN<end>';
		if (isset($nextSapling)) {
			return "{$state} (Sapling respawns {$nextSapling})";
		}

		$mob = $this->getMostRecentMobAction($mobs);

		if (!isset($mob) || $mob->status === Mob::STATUS_UNKNOWN) {
			return '<unknown>UNKNOWN<end>';
		}

		// We don't know when the sapling de-spawned, so we don't know when
		// a new one will spawn. Let's show the last state we're sure of

		// Weed de-spawned
		if ($mob->status === Mob::STATUS_OUT_OF_RANGE) {
			if (isset($mob->last_seen)) {
				return '{$state} (wiped at Weed '.
					Util::unixtimeToReadable(time() - $mob->last_seen).
					' ago)';
			}
			return $state;
		}
		// Weed killed
		if ($mob->key === 'weed' && isset($mob->last_killed)) {
			return "{$state} (Weed killed ".
				Util::unixtimeToReadable(time() - $mob->last_killed).
				' ago)';
		}
		// Abandoned during a wave
		if (!isset($mob->last_killed)) {
			return $state;
		}
		$extra = '';
		if (str_starts_with($mob->key, 'sapling-')) {
			$extra = ' at wave ' . substr($mob->key, 8) . ',';
		}
		return "{$state} (abandoned{$extra} ".
			Util::unixtimeToReadable(time() - $mob->last_killed).
			' ago)';
	}

	/** @param array<string,Mob> $mobs */
	private function getMostRecentMobAction(array $mobs): ?Mob {
		$mostRecent = 0;
		$match = null;
		foreach ($mobs as $mob) {
			if (isset($mob->last_seen) && $mob->last_seen > $mostRecent) {
				$mostRecent = $mob->last_seen;
				$match = $mob;
			}
			if (isset($mob->last_killed) && $mob->last_killed > $mostRecent) {
				$mostRecent = $mob->last_killed;
				$match = $mob;
			}
		}
		if (
			$match?->key === 'sapling-10'
			&& isset($mobs['weed'], $mobs['weed']->last_killed, $match->last_killed)
			&& ($match->last_killed - $mobs['weed']->last_killed) < 3_600
		) {
			return $mobs['weed'];
		}
		return $match;
	}

	private function renderMobStatus(Mob $mob): string {
		switch ($mob->status) {
			case $mob::STATUS_UNKNOWN:
				if (!isset($mob->last_seen)) {
					return '<unknown>UNKNOWN<end>';
				}
				// Otherwise, the mob is out of range
			case $mob::STATUS_OUT_OF_RANGE:
				$status = '<yellow>OUT OF RANGE<end>';
				if (!isset($mob->last_seen)) {
					return $status;
				}
				$hp = (int)round($mob->hp_percent??100, 0);
				$color = ($hp > 75) ? 'highlight' : (($hp <= 25) ? 'red' : 'yellow');
				return "{$status} (last seen ".
					Util::unixtimeToReadable(time() - $mob->last_seen).
					' ago with ' . Text::alignNumber($hp, 3, $color) . '% HP)';
			case $mob::STATUS_DOWN:
				$status = '<off>DEAD<end>';
				if (!isset($mob->last_killed)) {
					return $status;
				}
				if (isset($mob->respawn_timer)) {
					$spawn = $mob->last_killed + $mob->respawn_timer;
					$respawn = $spawn - time();
					$respawnTime = ($respawn > 0)
						? 'in ' . Util::unixtimeToReadable($respawn)
						: 'any moment now';
					return "{$status} (respawns {$respawnTime})";
				}
				return "{$status} (killed ".
					Util::unixtimeToReadable(time() - $mob->last_killed).
					'ago)';
			case $mob::STATUS_UP:
			case $mob::STATUS_ATTACKED:
				$hp = (int)round($mob->hp_percent??100, 0);
				$color = ($hp > 75) ? 'highlight' : (($hp <= 25) ? 'red' : 'yellow');
				return '<on>UP<end>, '.
					Text::alignNumber($hp, 3, $color) . '% HP';
			default:
				return '<unknown>UNKNOWN<end>';
		}
	}

	private function renderMob(Mob $mob): string {
		$status = $this->renderMobStatus($mob);

		/** @var string */
		$basename = Safe::pregReplace("/\s+\(placeholder\)/i", '', $mob->name);
		$whereis = $this->whereisCtrl->getByName($basename);
		if ($whereis->count() === 1) {
			/** @var Whereis */
			$whereMob = $whereis->firstOrFail();
			$mob->x = $whereMob->xcoord;
			$mob->y = $whereMob->ycoord;
		}
		return "<header2>{$mob->name}<end> [".
			Text::makeChatcmd(
				"{$mob->x}x{$mob->y} {$mob->playfield->short()}",
				"/waypoint {$mob->x} {$mob->y} {$mob->playfield->value}"
			) . "] - <i>{$mob->type}-{$mob->key}</i>\n".
			"<tab>{$status}";
	}
}
