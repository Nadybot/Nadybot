<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE;

use function Safe\json_decode;
use Amp\Http\Client\{HttpClientBuilder, Request};
use Closure;
use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject};
use Illuminate\Support\Collection;
use Nadybot\Core\Attributes\{Event, HandlesCommand};
use Nadybot\Core\Routing\{RoutableMessage, Source};
use Nadybot\Core\{Attributes as NCA, CmdContext, MessageHub, ModuleInstance, Safe, Text, Util};
use Nadybot\Modules\HELPBOT_MODULE\PlayfieldController;
use Nadybot\Modules\WHEREIS_MODULE\{WhereisController, WhereisResult};
use Psr\Log\LoggerInterface;
use Safe\Exceptions\JsonException;

#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\EmitsMessages("mobs", "*"),
	NCA\DefineCommand(
		command: "prisoners",
		alias: ["pris"],
		description: "Get the status of all prisoners",
		accessLevel: "guest",
	),
	NCA\DefineCommand(
		command: "hags",
		description: "Get the status of all Biodome hags",
		accessLevel: "guest",
	),
	NCA\DefineCommand(
		command: "dreads",
		description: "Get the status of all Dreadlochs bosses",
		accessLevel: "guest",
	),
	NCA\DefineCommand(
		command: "ljotur",
		description: "Get the status of Ljotur the Lunatic",
		accessLevel: "guest",
	),
	NCA\DefineCommand(
		command: "otacustes",
		alias: ["ota"],
		description: "Get the status of Otacustes",
		accessLevel: "guest",
	),
	NCA\DefineCommand(
		command: "jack",
		alias: ["legchopper"],
		description: "Get the status of Jack \"Leg-chopper\" Menendez and his clones",
		accessLevel: "guest",
	),
]
class MobController extends ModuleInstance {
	public const MOB_API = "https://mobs.aobots.org/api/";

	/** @var array<string,array<string,Mob>> */
	public array $mobs = [];

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Inject]
	private PlayfieldController $pfCtrl;

	#[NCA\Inject]
	private WhereisController $whereisCtrl;

	#[NCA\Inject]
	private MessageHub $msgHub;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private Util $util;

	#[NCA\Event("connect", "Load all mobs from the API")]
	public function initMobsFromApi(): void {
		$client = $this->builder->build();

		$response = $client->request(new Request(self::MOB_API));
		if ($response->getStatus() !== 200) {
			$this->logger->error("Error calling the mob-api: HTTP-code {code}", [
				"code" => $response->getStatus(),
			]);
			return;
		}
		$body = $response->getBody()->buffer();

		try {
			/** @var array<string,array<mixed>> */
			$json = json_decode($body, true);
			$mapper = new ObjectMapperUsingReflection();

			$this->mobs = [];
			foreach ($json as $type => $entries) {
				$this->mobs[$type] = [];

				/** @psalm-suppress InternalMethod */
				$mobs = $mapper->hydrateObjects(Mob::class, $entries)->getIterator();
				foreach ($mobs as $mob) {
					$this->mobs[$type][$mob->key] = $mob;
				}
			}
		} catch (JsonException $e) {
			$this->logger->error("Invalid mob-data received: {error}", [
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
			return;
		} catch (UnableToHydrateObject $e) {
			$this->logger->error("Unable to parse mob-api: {error}", [
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
		}
	}

	/** Load the data for a single mob from the API */
	public function loadMobFromApi(string $type, string $key): void {
		$client = $this->builder->build();

		$response = $client->request(new Request(self::MOB_API . $type));
		if ($response->getStatus() !== 200) {
			$this->logger->error("Error calling the mob-api: HTTP-code {code}", [
				"code" => $response->getStatus(),
			]);
			return;
		}
		$body = $response->getBody()->buffer();

		try {
			/** @var array<string,array<mixed>> */
			$json = json_decode($body, true);
			$mapper = new ObjectMapperUsingReflection();

			foreach ($json as $entry) {
				/** @psalm-suppress InternalMethod */
				$mob = $mapper->hydrateObjects(Mob::class, $entry)->getIterator();

				/** @var Mob $mob */
				if ($mob->key === $key) {
					$this->mobs[$type] ??= [];
					$this->mobs[$type][$key] = $mob;
				}
			}
		} catch (JsonException $e) {
			$this->logger->error("Invalid mob-data received: {error}", [
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
			return;
		} catch (UnableToHydrateObject $e) {
			$this->logger->error("Unable to parse mob-api: {error}", [
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
		}
	}

	#[Event(
		name: MobAttackedEvent::EVENT_MASK,
		description: "Announce when a mob gets attacked as mob(&lt;type&gt;-&lt;key&gt;-attacked)",
	)]
	public function announceMobAttacked(MobAttackedEvent $event): void {
		$mob = $event->mob;
		$pf = $this->pfCtrl->getPlayfieldById($mob->playfield_id);
		assert(isset($pf));
		$blob = $this->text->makeChatcmd(
			"{$mob->x}x{$mob->y} {$pf->short_name}",
			"/waypoint {$mob->x} {$mob->y} {$mob->playfield_id}"
		);
		$msg = "<highlight>{$mob->name}<end> is being attacked in ".
			((array)$this->text->makeBlob(
				$pf->long_name,
				$blob,
				"{$mob->name} waypoint",
			))[0] . ".";
		$rMsg = new RoutableMessage($msg);
		$rMsg->prependPath(new Source("mobs", "{$mob->type}-{$mob->key}-attacked"));
		$this->msgHub->handle($rMsg);
	}

	#[Event(
		name: MobSpawnEvent::EVENT_MASK,
		description: "Announce when a new mob spawns as mob(&lt;type&gt;-&lt;key&gt;-spawn)",
	)]
	public function announceMobSpawn(MobSpawnEvent $event): void {
		$mob = $event->mob;
		$pf = $this->pfCtrl->getPlayfieldById($mob->playfield_id);
		assert(isset($pf));
		$blob = $this->text->makeChatcmd(
			"{$mob->x}x{$mob->y} {$pf->short_name}",
			"/waypoint {$mob->x} {$mob->y} {$mob->playfield_id}"
		);
		$msg = "<highlight>{$mob->name}<end> has spawned in ".
			((array)$this->text->makeBlob(
				$pf->long_name,
				$blob,
				"{$mob->name} waypoint",
			))[0] . ".";
		$rMsg = new RoutableMessage($msg);
		$rMsg->prependPath(new Source("mobs", "{$mob->type}-{$mob->key}-spawn"));
		$this->msgHub->handle($rMsg);
	}

	#[Event(
		name: MobDeathEvent::EVENT_MASK,
		description: "Announce when a mob gets killed as mob(&lt;type&gt;-&lt;key&gt;-death)",
	)]
	public function announceMobDeath(MobDeathEvent $event): void {
		$mob = $event->mob;
		$pf = $this->pfCtrl->getPlayfieldById($mob->playfield_id);
		assert(isset($pf));
		$blob = $this->text->makeChatcmd(
			"{$mob->x}x{$mob->y} {$pf->short_name}",
			"/waypoint {$mob->x} {$mob->y} {$mob->playfield_id}"
		);
		$msg = "<highlight>{$mob->name}<end> was killed in ".
			((array)$this->text->makeBlob(
				$pf->long_name,
				$blob,
				"{$mob->name} waypoint",
			))[0] . ".";
		if (isset($mob->respawn_timer)) {
			$msg .= " Respawn will be in ".
				$this->util->unixtimeToReadable($mob->respawn_timer) . ".";
		}
		$rMsg = new RoutableMessage($msg);
		$rMsg->prependPath(new Source("mobs", "{$mob->type}-{$mob->key}-death"));
		$this->msgHub->handle($rMsg);
	}

	#[
		HandlesCommand("prisoners"),
		NCA\Help\Group("mobs"),
	]
	/** Show which of the prisoners in Milky Way is up or down */
	public function showPrisonersCommand(CmdContext $context): void {
		/** @var Collection<string> */
		$blobs = (new Collection(array_values($this->mobs[Mob::T_PRISONER]??[])))
			->sortBy("name")
			->map(Closure::fromCallable([$this, "renderMob"]));
		if ($blobs->isEmpty()) {
			$context->reply("There is currently no data for any prisoner. Maybe the API is down.");
			return;
		}
		$msg = $this->text->makeBlob(
			"Status of all prisoners (" . $blobs->count() . ")",
			$blobs->join("\n\n")
		);
		$context->reply($msg);
	}

	#[
		HandlesCommand("hags"),
		NCA\Help\Group("mobs"),
	]
	/** Show which Biodome hag is up or down */
	public function showHagsCommand(
		CmdContext $context,
		#[NCA\StrChoice("clan", "omni")]
		?string $type
	): void {
		/** @var Collection<string> */
		$factions = (new Collection(array_values($this->mobs[Mob::T_HAG]??[])))
			->sortBy("name")
			->groupBy(function (Mob $mob): string {
				return explode("-", $mob->key)[0];
			});

		if (isset($type)) {
			$factions = new Collection([$type => $factions->get($type)]);
			if ($factions->get($type)->isEmpty()) {
				$context->reply("There is currently no data for any {$type} hags. Maybe the API is down.");
				return;
			}
		} elseif ($factions->isEmpty()) {
			$context->reply("There is currently no data for any hags. Maybe the API is down.");
			return;
		}

		$blobs = $factions->map(function (Collection $hags, string $faction): string {
			return ((array)$this->text->makeBlob(
				ucfirst($faction) . " hags (" . $hags->count() . ")",
				$hags->map(Closure::fromCallable([$this, "renderMob"]))->join("\n\n")
			))[0];
		});
		$msg = "Status of all " . $blobs->join(" and ") . ".";
		$context->reply($msg);
	}

	#[
		HandlesCommand("dreads"),
		NCA\Help\Group("mobs"),
	]
	/** Show which Dreadloch mob is up or down */
	public function showDreadsCommand(
		CmdContext $context,
		#[NCA\StrChoice("clan", "omni")]
		?string $type
	): void {
		$sides = [
			"pthunder" => "omni",
			"woon" => "omni",
			"moxy" => "omni",
			"frc-191" => "omni",
			"pax" => "omni",
			"crux" => "clan",
			"sleek" => "clan",
			"swan" => "clan",
			"deko" => "clan",
			"cthunder" => "clan",
		];

		/** @var Collection<string> */
		$factions = (new Collection(array_values($this->mobs[Mob::T_DREAD]??[])))
			->sortBy("name")
			->groupBy(function (Mob $mob) use ($sides): string {
				return $sides[$mob->key] ?? "unknown";
			});
		if (isset($type)) {
			$factions = new Collection([$type => $factions->get($type)]);
			if ($factions->get($type)->isEmpty()) {
				$context->reply("There is currently no data for any {$type} Dreadloch camp. Maybe the API is down.");
				return;
			}
		} elseif ($factions->isEmpty()) {
			$context->reply("There is currently no data for any Dreadloch camp. Maybe the API is down.");
			return;
		}
		$blobs = $factions->map(function (Collection $dreads, string $faction): string {
			return ((array)$this->text->makeBlob(
				ucfirst($faction) . " Dreadloch camps (" . $dreads->count() . ")",
				$dreads->map(Closure::fromCallable([$this, "renderMob"]))->join("\n\n")
			))[0];
		});
		$msg = "Status of all " . $blobs->join(" and ") . ".";
		$context->reply($msg);
	}

	#[
		HandlesCommand("jack"),
		NCA\Help\Group("mobs"),
	]
	/** Show which of Jack's clones is currently up */
	public function showLegchopperCommand(CmdContext $context): void {
		/** @var Collection<string> */
		$blobs = (new Collection(array_values($this->mobs[Mob::T_LEGCHOPPER]??[])))
			->sortBy("name")
			->sort(function (Mob $a, Mob $b): int {
				return $a->key === "jack"
					? -1
					: ($b->key === "jack" ? 1 : 0);
			})
			->map(Closure::fromCallable([$this, "renderMob"]));
		if ($blobs->isEmpty()) {
			$context->reply("There is currently no data for Jack Legchopper or his clones. Maybe the API is down.");
			return;
		}
		$msg = $this->text->makeBlob(
			"Status of Jack and his clones (" . $blobs->count() . ")",
			$blobs->join("\n\n")
		);
		$context->reply($msg);
	}

	#[
		HandlesCommand("ljotur"),
		NCA\Help\Group("mobs"),
	]
	/** Show whether Ljotur the Lunatic, or one of his placeholders are up */
	public function showLjoturCommand(CmdContext $context): void {
		$this->showUniqueCommand($context, "ljotur", "Ljtur the Lunatic");
	}

	#[
		HandlesCommand("otacustes"),
		NCA\Help\Group("mobs"),
	]
	/** Show whether Otacustes, or one of his placeholders are up */
	public function showOtacustesCommand(CmdContext $context): void {
		$this->showUniqueCommand($context, "otacustes", "Otacustes");
	}

	public function showUniqueCommand(CmdContext $context, string $key, string $name): void {
		/** @var ?Mob */
		$mob = (new Collection(array_values($this->mobs[Mob::T_UNIQUES]??[])))
			->where("key", $key)
			->first();
		if (!isset($mob)) {
			$context->reply("There is currently no data for {$name}. Maybe the API is down.");
			return;
		}
		$blob = $this->renderMob($mob);
		$msg = $this->text->blobWrap(
			"",
			$this->text->makeBlob($mob->name, $blob),
			": " . $this->renderMobStatus($mob)
		);
		$context->reply($msg);
	}

	private function renderMobStatus(Mob $mob): string {
		switch ($mob->status) {
			case $mob::STATUS_UNKNOWN:
				if (!isset($mob->last_seen)) {
					return "<unknown>UNKNOWN<end>";
				}
				// Otherwise, the mob is out of range
			case $mob::STATUS_OUT_OF_RANGE:
				$status = "<yellow>OUT OF RANGE<end>";
				if (!isset($mob->last_seen)) {
					return $status;
				}
				$hp = (int)round($mob->hp_percent??100, 0);
				$color = ($hp > 75) ? "highlight" : (($hp <= 25) ? "red" : "yellow");
				return "{$status} (last seen ".
					$this->util->unixtimeToReadable(time() - $mob->last_seen).
					" ago with " . $this->text->alignNumber($hp, 3, $color) . "% HP)";
			case $mob::STATUS_DOWN:
				$status = "<off>DEAD<end>";
				if (!isset($mob->last_killed)) {
					return $status;
				}
				if (isset($mob->respawn_timer)) {
					$spawn = $mob->last_killed + $mob->respawn_timer;
					$respawn = $spawn - time();
					$respawnTime = ($respawn > 0)
						? "in " . $this->util->unixtimeToReadable($respawn)
						: "any moment now";
					return "{$status} (respawns {$respawnTime})";
				}
				return "{$status} (killed ".
					$this->util->unixtimeToReadable(time() - $mob->last_killed).
					"ago)";
			case $mob::STATUS_UP:
			case $mob::STATUS_ATTACKED:
				$hp = (int)round($mob->hp_percent??100, 0);
				$color = ($hp > 75) ? "highlight" : (($hp <= 25) ? "red" : "yellow");
				return "<on>UP<end>, ".
					$this->text->alignNumber($hp, 3, $color) . "% HP";
			default:
				return "<unknown>UNKNOWN<end>";
		}
	}

	private function renderMob(Mob $mob): string {
		$pf = $this->pfCtrl->getPlayfieldById($mob->playfield_id);
		assert(isset($pf));
		$status = $this->renderMobStatus($mob);

		/** @var string */
		$basename = Safe::pregReplace("/\s+\(placeholder\)/i", "", $mob->name);
		$whereis = $this->whereisCtrl->getByName($basename);
		if ($whereis->count() === 1) {
			/** @var WhereisResult */
			$whereMob = $whereis->firstOrFail();
			$mob->x = $whereMob->xcoord;
			$mob->y = $whereMob->ycoord;
		}
		return "<header2>{$mob->name}<end> [".
			$this->text->makeChatcmd(
				"{$mob->x}x{$mob->y} {$pf->short_name}",
				"/waypoint {$mob->x} {$mob->y} {$mob->playfield_id}"
			) . "] - <i>{$mob->type}-{$mob->key}</i>\n".
			"<tab>{$status}";
	}
}
