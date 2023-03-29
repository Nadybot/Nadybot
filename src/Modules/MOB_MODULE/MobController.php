<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE;

use function Safe\json_decode;
use Amp\Http\Client\{HttpClientBuilder, Request, Response};
use Closure;
use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject};
use Generator;
use Illuminate\Support\Collection;
use Nadybot\Core\Attributes\{Event, HandlesCommand};
use Nadybot\Core\Routing\{RoutableMessage, Source};
use Nadybot\Core\{Attributes as NCA, CmdContext, LoggerWrapper, MessageHub, ModuleInstance, Text, Util};
use Nadybot\Modules\HELPBOT_MODULE\PlayfieldController;

use Safe\Exceptions\JsonException;

#[
	NCA\Instance,
	NCA\EmitsMessages("mobs", "*"),
	NCA\DefineCommand(
		command: "pris",
		description: "Get the status of all prisoners",
		accessLevel: "guest",
	),
	NCA\DefineCommand(
		command: "hags",
		description: "Get the status of all Biodome hags",
		accessLevel: "guest",
	),
]
class MobController extends ModuleInstance {
	public const MOB_API = "https://mobs.aobots.org/api/";

	#[NCA\Inject]
	public HttpClientBuilder $builder;

	#[NCA\Inject]
	public PlayfieldController $pfCtrl;

	#[NCA\Inject]
	public MessageHub $msgHub;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** @var array<string,array<string,Mob>> */
	public array $mobs = [];

	#[NCA\Event("connect", "Load all mobs from the API")]
	public function initMobsFromApi(): Generator {
		$client = $this->builder->build();

		/** @var Response */
		$response = yield $client->request(new Request(self::MOB_API));
		if ($response->getStatus() !== 200) {
			$this->logger->error("Error calling the mob-api: HTTP-code {code}", [
				"code" => $response->getStatus(),
			]);
			return;
		}
		$body = yield $response->getBody()->buffer();

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

	#[Event(
		name: "mob-spawn",
		description: "Announce when a new mob spawns as mob(&lt;type&gt;-&lt;key&gt;-spawn)",
	)]
	public function announceMobSpawn(MobEvent $event): void {
		$mob = $event->mob;
		$pf = $this->pfCtrl->getPlayfieldById($mob->playfield_id);
		assert(isset($pf));
		$blob = $this->text->makeChatcmd(
			"get waypoint",
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
		name: "mob-death",
		description: "Announce when a mob gets killed as mob(&lt;type&gt;-&lt;key&gt;-death)",
	)]
	public function announceMobDeath(MobEvent $event): void {
		$mob = $event->mob;
		$pf = $this->pfCtrl->getPlayfieldById($mob->playfield_id);
		assert(isset($pf));
		$blob = $this->text->makeChatcmd(
			"get waypoint",
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

	#[HandlesCommand("pris")]
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

	#[HandlesCommand("hags")]
	public function showHagsCommand(CmdContext $context): void {
		/** @var Collection<string> */
		$blobs = (new Collection(array_values($this->mobs[Mob::T_HAG]??[])))
			->sortBy("name")
			->map(Closure::fromCallable([$this, "renderMob"]));
		if ($blobs->isEmpty()) {
			$context->reply("There is currently no data for any hag. Maybe the API is down.");
			return;
		}
		$msg = $this->text->makeBlob(
			"Status of all hags (" . $blobs->count() . ")",
			$blobs->join("\n\n")
		);
		$context->reply($msg);
	}

	private function renderMob(Mob $mob): string {
		$pf = $this->pfCtrl->getPlayfieldById($mob->playfield_id);
		assert(isset($pf));
		switch ($mob->status) {
			case $mob::STATUS_DOWN:
				$status = "<off>DEAD<end>";
				if (isset($mob->last_killed)) {
					if (isset($mob->respawn_timer)) {
						$spawn = $mob->last_killed + $mob->respawn_timer;
						$respawn = $spawn - time();
						$respawnTime = ($respawn > 0)
							? "in " . $this->util->unixtimeToReadable($respawn)
							: "any moment now";
						$status .= " (respawns {$respawnTime})";
					} else {
						$status .= " (killed ".
							$this->util->unixtimeToReadable(time() - $mob->last_killed).
							"ago)";
					}
				}
				break;
			case $mob::STATUS_UP:
			case $mob::STATUS_ATTACKED:
				$hp = (int)round($mob->hp_percent??100, 0);
				$color = ($hp > 75) ? "highlight" : (($hp <= 25) ? "red" : "yellow");
				$status = "<on>UP<end>, ".
					$this->text->alignNumber($hp, 3, $color) . "% HP";
				break;
			default:
				$status = "<unknown>UNKNOWN<end>";
		}
		return "<header2>{$mob->name}<end> [".
			$this->text->makeChatcmd(
				"{$mob->x}x{$mob->y} {$pf->short_name}",
				"/waypoint {$mob->x} {$mob->y} {$mob->playfield_id}"
			) . "] - <i>{$mob->type}-{$mob->key}</i>\n".
			"<tab>{$status}";
	}
}
