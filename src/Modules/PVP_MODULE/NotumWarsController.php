<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use function Safe\{json_decode};
use Amp\Http\Client\{HttpClientBuilder, Request, Response};
use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject};
use Exception;
use Generator;
use Illuminate\Support\Collection;
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;
use Nadybot\Core\ParamClass\PTowerSite;
use Nadybot\Core\Routing\{RoutableMessage, Source};
use Nadybot\Core\{Attributes as NCA, CmdContext, ConfigFile, DB, EventManager, LoggerWrapper, MessageHub, ModuleInstance, Text, Util};
use Nadybot\Modules\HELPBOT_MODULE\{Playfield, PlayfieldController};
use Nadybot\Modules\LEVEL_MODULE\LevelController;
use Nadybot\Modules\PVP_MODULE\FeedMessage\{TowerAttack, TowerOutcome};
use Nadybot\Modules\PVP_MODULE\{FeedMessage};
use Nadybot\Modules\TIMERS_MODULE\{Alert, Timer, TimerController};
use Safe\Exceptions\JsonException;

#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\EmitsMessages("pvp", "gas-change-clan"),
	NCA\EmitsMessages("pvp", "gas-change-neutral"),
	NCA\EmitsMessages("pvp", "gas-change-omni"),
	NCA\EmitsMessages("pvp", "site-planted-clan"),
	NCA\EmitsMessages("pvp", "site-planted-neutral"),
	NCA\EmitsMessages("pvp", "site-planted-omni"),
	NCA\EmitsMessages("pvp", "site-destroyed-clan"),
	NCA\EmitsMessages("pvp", "site-destroyed-neutral"),
	NCA\EmitsMessages("pvp", "site-destroyed-omni"),
	NCA\EmitsMessages("pvp", "tower-destroyed-clan"),
	NCA\EmitsMessages("pvp", "tower-destroyed-neutral"),
	NCA\EmitsMessages("pvp", "tower-destroyed-omni"),
	NCA\EmitsMessages("pvp", "tower-planted-clan"),
	NCA\EmitsMessages("pvp", "tower-planted-neutral"),
	NCA\EmitsMessages("pvp", "tower-planted-omni"),
	NCA\EmitsMessages("pvp", "site-hot-clan"),
	NCA\EmitsMessages("pvp", "site-hot-neutral"),
	NCA\EmitsMessages("pvp", "site-hot-omni"),
	NCA\EmitsMessages("pvp", "site-cold-clan"),
	NCA\EmitsMessages("pvp", "site-cold-neutral"),
	NCA\EmitsMessages("pvp", "site-cold-omni"),
	NCA\ProvidesEvent(
		event: "tower-attack-info",
		desc: "Someone attacks a tower site, includes additional information"
	),
	NCA\DefineCommand(
		command: "nw",
		description: "Perform Notum Wars commands",
		accessLevel: "guest",
	),
	NCA\DefineCommand(
		command: "nw hot",
		description: "Show sites which are hot",
		accessLevel: "guest",
	),
	NCA\DefineCommand(
		command: "nw free",
		description: "Show all unplanted sites",
		accessLevel: "guest",
	),
	NCA\DefineCommand(
		command: "nw sites",
		description: "Show all sites of an org",
		accessLevel: "guest",
	),
	NCA\DefineCommand(
		command: "nw timer",
		description: "Start a plant timer for a site",
		accessLevel: "guest",
	),
]
class NotumWarsController extends ModuleInstance {
	public const TOWER_API = "http://10.200.200.2:8080";
	public const ATTACKS_API = "http://10.200.200.2:5151/attacks";
	public const OUTCOMES_API = "http://10.200.200.2:5151/outcomes";
	public const DB_ATTACKS = "nw_attacks_<myname>";
	public const DB_OUTCOMES = "nw_outcomes_<myname>";

	/** Breakpoints of QLs that start a new tower type */
	public const TOWER_TYPE_QLS = [
		34 => 2,
		82 => 3,
		129 => 4,
		177 => 5,
		201 => 6,
		226 => 7,
	];

	#[NCA\Inject]
	public HttpClientBuilder $builder;

	#[NCA\Inject]
	public PlayfieldController $pfCtrl;

	#[NCA\Inject]
	public MessageHub $msgHub;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public LevelController $lvlCtrl;

	#[NCA\Inject]
	public TimerController $timerController;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** @var array<int,PlayfieldState> */
	public array $state = [];

	/** @var TowerAttack[] */
	public array $attacks = [];

	/** @var TowerOutcome[] */
	public array $outcomes = [];

	/** By what to group hot/penaltized sites */
	#[NCA\Setting\Options(options: [
		'Playfield' => 1,
		'Title level' => 2,
		'Org' => 3,
		'Faction' => 4,
	])]
	public int $groupHotTowers = 1;

	/** Where to display tower plant timers */
	#[NCA\Setting\Options(options: [
		'priv' => 1,
		'org' => 2,
		'priv+org' => 3,
	])]
	public int $plantTimerChannel = 3;

	/** Automatically start a plant-timer when a site goes down */
	#[NCA\Setting\Boolean]
	public bool $autoPlantTimer = false;

	#[NCA\Event("connect", "Load all towers from the API")]
	public function initTowersFromApi(): Generator {
		$client = $this->builder->build();

		/** @var Response */
		$response = yield $client->request(new Request(self::TOWER_API));
		if ($response->getStatus() !== 200) {
			$this->logger->error("Error calling the tower-api: HTTP-code {code}", [
				"code" => $response->getStatus(),
			]);
			return;
		}
		$body = yield $response->getBody()->buffer();
		try {
			$json = json_decode($body, true);
			$mapper = new ObjectMapperUsingReflection();

			/** @psalm-suppress InternalMethod */
			$sites = $mapper->hydrateObjects(FeedMessage\SiteUpdate::class, $json)->getIterator();
			foreach ($sites as $site) {
				$this->updateSiteInfo($site);
			}
		} catch (JsonException $e) {
			$this->logger->error("Invalid tower-data received: {error}", [
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
			return;
		} catch (UnableToHydrateObject $e) {
			$this->logger->error("Unable to parse tower-api: {error}", [
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
		}
	}

	#[NCA\Event("connect", "Load all attacks from the API")]
	public function initAttacksFromApi(): Generator {
		$maxTS = $this->db->table(self::DB_ATTACKS)->max("timestamp");
		$client = $this->builder->build();
		$uri = self::ATTACKS_API;
		if (isset($maxTS)) {
			$uri .= "?" . http_build_query(["since" => $maxTS+1]);
		}

		/** @var Response */
		$response = yield $client->request(new Request($uri));
		if ($response->getStatus() !== 200) {
			$this->logger->error("Error calling the attacks-api: HTTP-code {code}", [
				"code" => $response->getStatus(),
			]);
			return;
		}
		$body = yield $response->getBody()->buffer();
		try {
			$json = json_decode($body, true);
			$mapper = new ObjectMapperUsingReflection();

			$attacks = $mapper->hydrateObjects(FeedMessage\TowerAttack::class, $json);

			/** @psalm-suppress InternalMethod */
			foreach ($attacks as $attack) {
				$this->attacks []= $attack;
				$player = yield $this->playerManager->lookupAsync2(
					$attack->attacker,
					$this->config->dimension,
				);
				$attInfo = DBTowerAttack::fromTowerAttack($attack, $player);
				$this->db->insert(self::DB_ATTACKS, $attInfo);
			}

			// All attacks from up to 6h ago can influence the current hot-duration
			$this->db->table(self::DB_ATTACKS)
				->where("timestamp", ">=", time() - 6 * 3600)
				->asObj(DBTowerAttack::class)
				->each(function (DBTowerAttack $attack): void {
					$this->registerAttack($attack->toTowerAttack());
				});
		} catch (JsonException $e) {
			$this->logger->error("Invalid attack-data received: {error}", [
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
			return;
		} catch (UnableToHydrateObject $e) {
			$this->logger->error("Unable to parse attack-api: {error}", [
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
		}
	}

	#[NCA\Event("connect", "Load all tower outcomes from the API")]
	public function initOutcomesFromApi(): Generator {
		$maxTS = $this->db->table(self::DB_OUTCOMES)->max("timestamp");
		$client = $this->builder->build();
		$uri = self::OUTCOMES_API;
		if (isset($maxTS)) {
			$uri .= "?" . http_build_query(["since" => $maxTS+1]);
		}

		/** @var Response */
		$response = yield $client->request(new Request($uri));
		if ($response->getStatus() !== 200) {
			$this->logger->error("Error calling the outcome-api: HTTP-code {code}", [
				"code" => $response->getStatus(),
			]);
			return;
		}
		$body = yield $response->getBody()->buffer();
		try {
			$json = json_decode($body, true);
			$mapper = new ObjectMapperUsingReflection();

			$outcomes = $mapper->hydrateObjects(FeedMessage\TowerOutcome::class, $json);

			/** @psalm-suppress InternalMethod */
			foreach ($outcomes as $outcome) {
				$this->db->insert(self::DB_OUTCOMES, DBOutcome::fromTowerOutcome($outcome));
				$this->outcomes []= $outcome;
			}
			$this->outcomes = $this->db->table(self::DB_OUTCOMES)
				->where("timestamp", ">=", time() - 7200)
				->orderByDesc("timestamp")
				->asObj(DBOutcome::class)
				->map(function (DBOutcome $outcome): TowerOutcome {
					return $outcome->toTowerOutcome();
				})
				->toArray();
		} catch (JsonException $e) {
			$this->logger->error("Invalid outcome-data received: {error}", [
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
			return;
		} catch (UnableToHydrateObject $e) {
			$this->logger->error("Unable to parse outcome-api: {error}", [
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
		}
	}

	/**
	 * Get a flat collection of all enable tower sites
	 *
	 * @return Collection<FeedMessage\SiteUpdate>
	 */
	public function getEnabledSites(): Collection {
		$result = new Collection();
		foreach ($this->state as $pfId => $sites) {
			foreach ($sites as $siteId => $site) {
				/** @var FeedMessage\SiteUpdate $site */
				if ($site->enabled) {
					$result->push($site);
				}
			}
		}
		return $result;
	}

	/** Update the state of a site with the data given */
	public function updateSiteInfo(FeedMessage\SiteUpdate $site): void {
		$playfield = $this->pfCtrl->getPlayfieldById($site->playfield_id);
		if (!isset($playfield)) {
			return;
		}
		$pfState = $this->state[$site->playfield_id] ?? new PlayfieldState($playfield);
		$pfState[$site->site_id] = clone $site;
		$this->state[$site->playfield_id] = $pfState;
	}

	/** Add the given attack to the attack cache */
	public function registerAttack(FeedMessage\TowerAttack $attack): void {
		$playfield = $this->pfCtrl->getPlayfieldById($attack->playfield_id);
		if (!isset($playfield)) {
			return;
		}
		$this->attacks = (new Collection([$attack, ...$this->attacks]))
			->where("timestamp", ">=", time() - 6 * 3600)
			->toArray();
	}

	#[NCA\Event("site-update", "Update tower information from the API")]
	public function updateSiteInfoFromFeed(Event\SiteUpdate $event): void {
		$oldSite = $this->state[$event->site->playfield_id][$event->site->site_id] ?? null;
		$this->updateSiteInfo($event->site);
		$site = $event->site;
		$pf = $this->pfCtrl->getPlayfieldById($site->playfield_id);
		if (!isset($pf)) {
			return;
		}
		if (!isset($oldSite->ct_pos) && isset($site->ct_pos)) {
			$this->handleSitePlanted($site, $pf);
		} elseif (isset($oldSite->ct_pos) && !isset($site->ct_pos)) {
			$this->handleSiteDestroyed($oldSite, $site, $pf);
		} elseif (isset($oldSite)) {
			$this->handleSiteTowerChange($oldSite, $site, $pf);
		}
	}

	#[NCA\Event("tower-outcome", "Update tower outcomes from the API")]
	public function updateTowerOutcomeInfoFromFeed(Event\TowerOutcome $event): void {
		$dbOutcome = DBOutcome::fromTowerOutcome($event->outcome);
		$this->db->insert(self::DB_OUTCOMES, $dbOutcome);
		$this->outcomes = (new Collection([$event->outcome, ...$this->outcomes]))
			->where("timestamp", ">=", time() - 3600)
			->toArray();
	}

	#[NCA\Event("tower-attack", "Update tower attacks from the API")]
	public function updateTowerAttackInfoFromFeed(Event\TowerAttack $event): Generator {
		$this->registerAttack($event->attack);
		$player = yield $this->playerManager->lookupAsync2(
			$event->attack->attacker,
			$this->config->dimension,
		);
		$site = $this->state[$event->attack->playfield_id][$event->attack->site_id]??null;
		$attInfo = DBTowerAttack::fromTowerAttack($event->attack, $player);
		$this->db->insert(self::DB_ATTACKS, $attInfo);
		$infoEvent = new Event\TowerAttackInfo($event->attack, $player, $site);
		$this->eventManager->fireEvent($infoEvent);
	}

	#[NCA\Event("gas-update", "Update gas information from the API")]
	public function updateGasInfoFromFeed(Event\GasUpdate $event): void {
		$site = $this->state[$event->gas->playfield_id][$event->gas->site_id] ?? null;
		if (!isset($site)) {
			return;
		}
		$pf = $this->pfCtrl->getPlayfieldById($site->playfield_id);
		if (!isset($pf)) {
			return;
		}
		$oldGas = isset($site->gas) ? new Gas($site->gas) : null;
		$newGas = new Gas($event->gas->gas);
		$site->gas = $event->gas->gas;
		$blob = $this->renderSite($site, $pf);
		$color = strtolower($site->org_faction ?? "neutral");
		$msg = "<{$color}>{$pf->short_name} {$site->site_id}<end> ".
			(isset($oldGas) ? ($oldGas->colored() . " -> ") : "").
			$newGas->colored() . " [".
			((array)$this->text->makeBlob(
				"details",
				$blob,
				"{$pf->short_name} {$site->site_id} ({$site->name})",
			))[0] . "]";
		$rMessage = new RoutableMessage($msg);
		$rMessage->prependPath(new Source("pvp", "gas-change-{$color}"));
		$this->msgHub->handle($rMessage);

		if ($newGas->gas === 75 && $oldGas?->gas !== 75 && isset($site->org_faction)) {
			$source = "site-cold-{$site->org_faction}";
		} elseif ($newGas->gas !== 75 && $oldGas?->gas === 75 && isset($site->org_faction)) {
			$source = "site-hot-{$site->org_faction}";
		} else {
			return;
		}
		$rMessage = new RoutableMessage($msg);
		$rMessage->prependPath(new Source("pvp", $source));
		$this->msgHub->handle($rMessage);
	}

	/** Get the current gas for a site and information */
	public function getSiteGasInfo(FeedMessage\SiteUpdate $site): ?GasInfo {
		$lastAttack = $this->getLastAttackFrom($site);
		return new GasInfo($site, $lastAttack);
	}

	/** Get the Tower Site Type (1-7) for a given CT-QL */
	public function qlToSiteType(int $qlCT): int {
		foreach (static::TOWER_TYPE_QLS as $ql => $level) {
			if ($qlCT < $ql) {
				return $level - 1;
			}
		}
		return 7;
	}

	/** Render the Popup-block for a single given site */
	public function renderSite(FeedMessage\SiteUpdate $site, Playfield $pf, bool $showOrgLinks=true): string {
		$lastOutcome = $this->getLastSiteOutcome($site);
		$centerWaypointLink = $this->text->makeChatcmd(
			"Center",
			"/waypoint {$site->center->x} {$site->center->y} {$pf->id}"
		);
		if (isset($site->ct_pos)) {
			$ctWaypointLink = $this->text->makeChatcmd(
				"CT",
				"/waypoint {$site->ct_pos->x} {$site->ct_pos->y} {$pf->id}"
			);
		}
		$attacksLink = $this->text->makeChatcmd(
			"Attacks",
			"/tell <myname> nw attacks {$pf->short_name} {$site->site_id}"
		);
		$outcomesLink = $this->text->makeChatcmd(
			"Victories",
			"/tell <myname> nw victory {$pf->short_name} {$site->site_id}"
		);

		$blob = "<header2>{$pf->short_name} {$site->site_id} ({$site->name})<end>\n";
		if ($site->enabled === false) {
			$blob .= "<tab><grey><i>disabled</i><end>\n";
			return $blob;
		}
		$blob .= "<tab>Level range: <highlight>{$site->min_ql}<end>-<highlight>{$site->max_ql}<end>\n";
		if (isset($site->plant_time, $site->ql, $site->org_faction, $site->org_name, $site->org_id)) {
			$blob .= "<tab>Planted: <highlight>".
				$this->util->date($site->plant_time) . "<end>\n";
		}
		if (isset($site->ql, $site->org_faction, $site->org_name, $site->org_id)) {
			// If the site is planted, show gas information
			$blob .= "<tab>CT: QL <highlight>{$site->ql}<end>, Type " . $this->qlToSiteType($site->ql) . " ".
				"(<" . strtolower($site->org_faction) .">{$site->org_name}<end>)";
			if ($showOrgLinks) {
				$orgLink = $this->text->makeChatcmd(
					"show sites",
					"/tell <myname> nw sites {$site->org_id}"
				);
				$blob .= " [{$orgLink}]";
			}
			$blob .= "\n";
			$gasInfo = $this->getSiteGasInfo($site);
			if (isset($gasInfo)) {
				$gas = $gasInfo->currentGas();
				if (isset($gas) && $gas->gas === 75) {
					$secsToHot = ($gasInfo->goesHot()??time()) - time();
					$blob .= "<tab>Gas: " . $gas->colored() . ", opens in ".
						$this->util->unixtimeToReadable($secsToHot) . "\n";
				} elseif (isset($gas)) {
					$secsToCold = ($gasInfo->goesCold()??time()) - time();
					$coldIn = ($secsToCold > 0)
						? "in " . $this->util->unixtimeToReadable($secsToCold)
						: "any time now";
					$blob .= "<tab>Gas: " . $gas->colored() . ", closes {$coldIn}\n";
				} else {
					$blob .= "<tab>Gas: N/A\n";
				}
			}
			$blob .= "<tab>Towers: 1 CT".
				", " . $site->num_turrets . " turrets".
				", " . $site->num_conductors . " conductors\n";
		} else {
			// If the site is unplanted, show destruction information and links to plant
			$blob .= "<tab>Planted: <highlight>No<end>\n";
			// If the site was destroyed less than 1 hour ago, show by who
			if (isset($lastOutcome) && $lastOutcome->timestamp + 3600 > time()) {
				if (isset($lastOutcome->attacking_org, $lastOutcome->attacking_faction)) {
					$blob .= "<tab>Destroyed by: ".
						"<" . strtolower($lastOutcome->attacking_faction) . ">".
						$lastOutcome->attacking_org . "<end>";
				} else {
					$blob .= "<tab>Abandoned by: ".
						"<" . strtolower($lastOutcome->losing_faction) . ">".
						$lastOutcome->losing_org . "<end>";
				}
				$blob .= " " . $this->util->unixtimeToReadable(time() - $lastOutcome->timestamp).
					" ago\n";
				$plantTs = $lastOutcome->timestamp + 20 * 60;
				$blob .= "<tab>Plantable: ";
				$plantIn = ($plantTs <= time())
					? "Now"
					: $this->util->unixtimeToReadable($plantTs - time());
				$blob .= "<highlight>{$plantIn}<end>";
				if (!$this->autoPlantTimer && $plantTs > time()) {
					$blob .= " [" . $this->text->makeChatcmd(
						"timer",
						"/tell <myname> <symbol>nw timer {$pf->short_name} {$site->site_id} {$plantTs}",
					) . "]";
				}
				$blob .= "\n";
			} else {
				$blob .= "<tab>Plantable: <highlight>Now<end>\n";
			}
		}
		$blob .= "<tab>Coordinates: [{$centerWaypointLink}]";
		if (isset($ctWaypointLink)) {
			$blob .= " [{$ctWaypointLink}]";
		}
		$blob .= "\n<tab>Stats: [{$attacksLink}] [{$outcomesLink}]\n";

		return $blob;
	}

	#[NCA\HandlesCommand("nw")]
	public function overviewCommand(CmdContext $context): void {
		$context->reply("Try <highlight><symbol>help nw<end> for a list of commands.");
		return;
	}

	/** Start a plant timer for a given site */
	#[NCA\Help\Hide]
	#[NCA\HandlesCommand("nw timer")]
	public function plantTimerCommand(
		CmdContext $context,
		#[NCA\Str("timer")] string $action,
		PTowerSite $site,
		int $timestamp,
	): void {
		$pf = $this->pfCtrl->getPlayfieldByName($site->pf);
		if (!isset($pf)) {
			$context->reply("Unknown playfield {$site->pf}.");
			return;
		}
		$towerSite = $this->state[$pf->id][$site->site] ?? null;
		if (!isset($towerSite)) {
			$context->reply("No tower field {$pf->short_name} {$site->site} found.");
			return;
		}
		if ($timestamp <= time()) {
			$context->reply("Plant {$pf->short_name} {$site->site} <highlight>NOW<end>!");
			return;
		}
		$timer = $this->getPlantTimer($towerSite, $timestamp);

		// Sometimes, they overlap, so make sure any previous timer
		// is removed first
		$this->timerController->remove($timer->name);

		$this->timerController->add(
			$timer->name,
			$context->char->name,
			$timer->mode,
			$timer->alerts,
			'timercontroller.timerCallback'
		);
		return;
	}

	/** See which sites are currently unplanted. */
	#[NCA\HandlesCommand("nw free")]
	public function unplantedSitesCommand(
		CmdContext $context,
		#[NCA\StrChoice("unplanted", "free")] string $action,
	): void {
		$unplantedSites = [];
		foreach ($this->state as $pfId => $sites) {
			$pf = $this->pfCtrl->getPlayfieldById($pfId);
			assert(isset($pf));
			foreach ($sites as $siteId => $site) {
				/** @var FeedMessage\SiteUpdate $site */
				if ($site->enabled && !isset($site->ct_pos)) {
					$unplantedSites []= $this->renderSite($site, $pf);
				}
			}
		}
		if (empty($unplantedSites)) {
			$context->reply("No unplanted sites.");
			return;
		}
		$msg = $this->text->makeBlob(
			"Unplanted sites (" . count($unplantedSites) . ")",
			join("\n", $unplantedSites)
		);
		$context->reply($msg);
	}

	/** See which orgs have the highest contract points. */
	#[NCA\HandlesCommand("nw")]
	public function highContractsCommand(
		CmdContext $context,
		#[NCA\Str("top", "highcontracts", "highcontract")] string $action,
	): void {
		$orgQls = [];
		$orgFaction = [];
		$this->getEnabledSites()
			->whereNotNull("org_name")
			->each(function (FeedMessage\SiteUpdate $site) use (&$orgQls, &$orgFaction): void {
				assert(isset($site->ql));
				assert(isset($site->org_faction));
				assert(isset($site->org_name));
				$orgQls[$site->org_name] ??= 0;
				$orgQls[$site->org_name] += $site->ql * 2;
				$orgFaction[$site->org_name] = $site->org_faction;
			});
		uasort($orgQls, fn (int $a, int $b): int => $b <=> $a);
		$top = array_slice($orgQls, 0, 20);
		$blob = "<header2>Top contract points<end>\n";
		$rank = 1;
		foreach ($top as $orgName => $points) {
			$sitesLink = $this->text->makeChatcmd(
				"sites",
				"/tell <myname> <symbol>nw sites {$orgName}"
			);
			$blob .= "\n<tab>" . $this->text->alignNumber($rank, 2).
				". " . $this->text->alignNumber($points, 4, "highlight", true).
				" <" . strtolower($orgFaction[$orgName] ?? "unknown") . ">{$orgName}<end>".
				" [{$sitesLink}]";
			$rank++;
		}
		$msg = $this->text->makeBlob(
			"Top " . count($top) . " contracts",
			$blob
		);
		$context->reply($msg);
	}

	/**
	 * See which sites are currently hot.
	 * You can limit this by any combination of
	 * faction, playfield, ql and level range and "soon"
	 */
	#[NCA\HandlesCommand("nw hot")]
	#[NCA\Help\Example("<symbol>nw hot clan")]
	#[NCA\Help\Example("<symbol>nw hot pw")]
	#[NCA\Help\Example("<symbol>nw hot 60", "Only those in PvP-range for a level 60 char")]
	#[NCA\Help\Example("<symbol>nw hot 99-110", "Only where the CT is between QL 99 and 110")]
	#[NCA\Help\Example("<symbol>nw hot omni pw 180-300")]
	#[NCA\Help\Example("<symbol>nw hot soon")]
	public function hotSitesCommand(
		CmdContext $context,
		#[NCA\Str("hot")] string $action,
		?string $search
	): void {
		$search ??= "";
		if (substr($search, 0, 1) !== " ") {
			$search = " {$search}";
		}
		$hotSites = $this->getEnabledSites()
			->whereNotNull("gas")
			->whereNotNull("ql");
		$search = preg_replace("/\s+soon\b/i", "", $search, -1, $soon);
		if ($soon) {
			$hotSites = $hotSites->filter(
				function (FeedMessage\SiteUpdate $site): bool {
					if ($site->gas !== 75) {
						return false;
					}
					return $this->getSiteGasInfo($site)?->gasAt(time() + 3600)?->gas === 25;
				}
			);
		} else {
			$hotSites = $hotSites->where("gas", "<", 75);
		}
		if (preg_match("/\s+(neutral|omni|clan|neut)\b/i", $search, $matches)) {
			$faction = strtolower($matches[1]);
			$search = preg_replace("/\s+(neutral|omni|clan|neut)\b/i", "", $search);
			if ($faction === "neut") {
				$faction = "neutral";
			}
			$hotSites = $hotSites->where("org_faction", ucfirst($faction));
		}
		if (preg_match("/\s+(\d+)\s*-\s*(\d+)\b/", $search, $matches)) {
			$hotSites = $hotSites->where("ql", ">=", (int)$matches[1])
				->where("ql", "<=", (int)$matches[2]);
			$search = preg_replace("/\s+(\d+)\s*-\s*(\d+)\b/", "", $search);
		}
		if (preg_match("/\s+(\d+)\b/", $search, $matches)) {
			$lvlInfo = $this->lvlCtrl->getLevelInfo((int)$matches[1]);
			if (!isset($lvlInfo)) {
				$context->reply("<highlight>{$matches[1]}<end> is an invalid level.");
				return;
			}
			$hotSites = $hotSites->where("ql", ">=", $lvlInfo->pvpMin);
			$hotSites = $hotSites->where("ql", "<=", ($lvlInfo->pvpMax === 220) ? 300 : $lvlInfo->pvpMax);
			$search = preg_replace("/\s+(\d+)\b/", "", $search);
		}
		if (preg_match("/\s+([a-z]{2,}|\d[a-z]{2,})\b/i", $search, $matches)) {
			$pf = $this->pfCtrl->getPlayfieldByName($matches[1]);
			if (!isset($pf)) {
				$context->reply("Unable to find playfield <highlight>{$matches[1]}<end>.");
				return;
			}
			$hotSites = $hotSites->where("playfield_id", $pf->id);
			$search = preg_replace("/\s+([a-z]{2,}|\d[a-z]{2,})\b/i", "", $search);
		}
		$search = trim($search);
		if ($hotSites->isEmpty()) {
			if ($soon) {
				$context->reply("No sites are going hot soon.");
			} else {
				$context->reply("No sites are currently hot.");
			}
			return;
		}
		$blob = $this->renderHotSites(...$hotSites->toArray());
		if ($soon) {
			$sitesLabel = isset($faction) ? ucfirst(strtolower($faction)) . " sites" : "Sites";
			$msg = $this->text->makeBlob("{$sitesLabel} going hot soon ({$hotSites->count()})", $blob);
		} else {
			$faction = isset($faction) ? " " . strtolower($faction) : "";
			$msg = $this->text->makeBlob("Hot{$faction} sites ({$hotSites->count()})", $blob);
		}

		$context->reply($msg);
	}

	/** List all tower sites matching an org ID */
	#[NCA\HandlesCommand("nw sites")]
	public function listOrgSitesByIDCommand(
		CmdContext $context,
		#[NCA\Str("sites")] string $action,
		int $orgID
	): void {
		$matches = $this->getEnabledSites()->whereStrict("org_id", $orgID);
		if ($matches->isEmpty()) {
			$context->reply("No tower sites match your search criteria.");
			return;
		}
		$blob = $this->renderOrgSites(...$matches->toArray());
		$msg = $this->text->makeBlob(
			"All tower sites of " . $matches->firstOrFail()?->org_name,
			$blob
		);
		$context->reply($msg);
	}

	/**
	 * List all tower sites matching an org name
	 *
	 * You can use * as a wildcard match
	 */
	#[NCA\HandlesCommand("nw sites")]
	#[NCA\Help\Example("<symbol>nw sites troet")]
	#[NCA\Help\Example("<symbol>nw sites angel*")]
	public function listOrgSitesCommand(
		CmdContext $context,
		#[NCA\Str("sites")] string $action,
		string $search
	): void {
		$matches = $this->getEnabledSites()
			->filter(function (FeedMessage\SiteUpdate $site) use ($search): bool {
				if (!isset($site->org_name)) {
					return false;
				}
				return fnmatch($search, $site->org_name, FNM_CASEFOLD);
			});
		if ($matches->isEmpty()) {
			$context->reply("No tower sites match your search criteria.");
			return;
		}
		$blob = $this->renderOrgSites(...$matches->toArray());
		$msg = $this->text->makeBlob(
			"All tower sites of '{$search}'",
			$blob
		);
		$context->reply($msg);
	}

	/** Handle whatever is necessary when a site gets newly planted */
	private function handleSitePlanted(FeedMessage\SiteUpdate $site, Playfield $pf): void {
		$timerName = $this->getPlantTimerName($site, $pf);
		$this->timerController->remove($timerName);
		$blob = $this->renderSite($site, $pf);
		$color = strtolower($site->org_faction ?? "neutral");
		$msg = "<{$color}>{$pf->short_name} {$site->site_id}<end> ".
			"@ QL <highlight>{$site->ql}<end> planted [".
			((array)$this->text->makeBlob(
				"details",
				$blob,
				"{$pf->short_name} {$site->site_id} ({$site->name})",
			))[0] . "]";
		$rMessage = new RoutableMessage($msg);
		$rMessage->prependPath(new Source("pvp", "site-planted-{$color}"));
		$this->msgHub->handle($rMessage);
	}

	/** Handle whatever is necessary when a site gets destroyed */
	private function handleSiteDestroyed(FeedMessage\SiteUpdate $oldSite, FeedMessage\SiteUpdate $site, Playfield $pf): void {
		if ($this->autoPlantTimer) {
			$timer = $this->getPlantTimer($site, time() + 20 * 60);

			$this->timerController->remove($timer->name);

			$this->timerController->add(
				$timer->name,
				$this->config->name,
				$timer->mode,
				$timer->alerts,
				'timercontroller.timerCallback'
			);
		}
		$blob = $this->renderSite($oldSite, $pf);
		$color = strtolower($oldSite->org_faction ?? "neutral");
		$msg = "<{$color}>{$pf->short_name} {$site->site_id}<end> ".
			"@ QL <highlight>{$oldSite->ql}<end> destroyed [".
			((array)$this->text->makeBlob(
				"details",
				$blob,
				"{$pf->short_name} {$site->site_id} ({$site->name})",
			))[0] . "]";
		$rMessage = new RoutableMessage($msg);
		$rMessage->prependPath(new Source("pvp", "site-destroyed-{$color}"));
		$this->msgHub->handle($rMessage);
	}

	/** Handle whatever is necessary when a site gets or loses a non-CT-tower */
	private function handleSiteTowerChange(FeedMessage\SiteUpdate $oldSite, FeedMessage\SiteUpdate $site, Playfield $pf): void {
		if ($oldSite->num_conductors < $site->num_conductors) {
			$subType = "planted";
			$incDec = "<green>+1<end>";
			$towerType = "conductor";
		} elseif ($oldSite->num_turrets < $site->num_turrets) {
			$subType = "planted";
			$incDec = "<green>+1<end>";
			$towerType = "turret";
		} elseif ($oldSite->num_conductors > $site->num_conductors) {
			$subType = "destroyed";
			$incDec = "<red>-1<end>";
			$towerType = "conductor";
		} elseif ($oldSite->num_turrets > $site->num_turrets) {
			$subType = "destroyed";
			$incDec = "<red>-1<end>";
			$towerType = "turret";
		} else {
			return;
		}
		$blob = $this->renderSite($site, $pf);
		$color = strtolower($site->org_faction ?? "neutral");
		$msg = "<{$color}>{$pf->short_name} {$site->site_id}<end> ".
			"{$towerType}s {$incDec} [".
			((array)$this->text->makeBlob(
				"details",
				$blob,
				"{$pf->short_name} {$site->site_id} ({$site->name})",
			))[0] . "]";
		$rMessage = new RoutableMessage($msg);
		$rMessage->prependPath(new Source("pvp", "tower-{$subType}-{$color}"));
		$this->msgHub->handle($rMessage);
	}

	/** Get the name of the plant timer for the given site */
	private function getPlantTimerName(FeedMessage\SiteUpdate $site, Playfield $pf): string {
		$siteShort = "{$pf->short_name} {$site->site_id}";
		return "plant_" . strtolower(str_replace(" ", "_", $siteShort));
	}

	/** Get the plant timer for a given site and time */
	private function getPlantTimer(FeedMessage\SiteUpdate $site, int $timestamp): Timer {
		$pf = $this->pfCtrl->getPlayfieldById($site->playfield_id);
		assert(isset($pf));

		/** @var Alert[] */
		$alerts = [];

		$siteShort = "{$pf->short_name} {$site->site_id}";
		$siteLong = "{$siteShort} (QL {$site->min_ql}-{$site->max_ql})";
		$alert = new Alert();
		$alert->time = time();
		$duration = $this->util->unixtimeToReadable($timestamp - time());
		$alert->message = "Started {$duration} countdown for planting {$siteLong}";
		$alerts []= $alert;

		if ($timestamp - 60 > time()) {
			$alert = new Alert();
			$alert->time = $timestamp - 60;
			$alert->message = "<highlight>1 minute<end> remaining to plant {$siteLong}";
			$alerts []= $alert;
		}

		$countdown = [3, 2, 1];
		foreach ($countdown as $remaining) {
			$alert = new Alert();
			$alert->time = $timestamp - $remaining;
			$alert->message = "<highlight>{$remaining}s<end> remaining to plant {$siteShort}: <highlight>{$remaining}s<end>";
			$alerts []= $alert;
		}

		$alertPlant = new Alert();
		$alertPlant->time = $timestamp;
		$alertPlant->message = "Plant {$siteShort} <highlight>NOW<end>!";
		$alerts []= $alertPlant;

		$modes = [];
		if ($this->plantTimerChannel & 1) {
			$modes []= "priv";
		}
		if ($this->plantTimerChannel & 2) {
			$modes []= "org";
		}

		$timer = new Timer();
		$timer->alerts = $alerts;
		$timer->endtime = $timestamp;
		$timer->name = $this->getPlantTimerName($site, $pf);
		$timer->mode = join(",", $modes);

		return $timer;
	}

	/**
	 * Get the last registered victory/abandoning of a field from the cache
	 *
	 * @return ?FeedMessage\TowerOutcome outcome or null if none, or none recently
	 */
	private function getLastSiteOutcome(FeedMessage\SiteUpdate $site): ?FeedMessage\TowerOutcome {
		foreach ($this->outcomes as $outcome) {
			if ($outcome->playfield_id !== $site->playfield_id
				|| $outcome->site_id !== $site->site_id) {
				continue;
			}
			return $outcome;
		}
		return null;
	}

	/** Render a list of tower sites and group by owning org name */
	private function renderOrgSites(FeedMessage\SiteUpdate ...$sites): string {
		$matches = (new Collection($sites))
			->sortBy("ql")
			->sortBy("org_name")
			->groupBy("org_name");

		$blob = $matches->map(function (Collection $sites, string $orgName): string {
			$faction = strtolower($sites->first()?->org_faction ?? "unknown");
			$ctPts = $sites->pluck("ql")->sum() * 2;
			return "<{$faction}>{$orgName}<end> (QL {$ctPts} contracts)\n\n".
				$sites->map(function (FeedMessage\SiteUpdate $site): string {
					$pf = $this->pfCtrl->getPlayfieldById($site->playfield_id);
					assert(isset($pf));
					return $this->renderSite($site, $pf);
				})->join("\n");
		})->join("\n");
		return trim($blob);
	}

	/** Render a bunch of sites, all hot, for the !hot-command */
	private function renderHotSites(FeedMessage\SiteUpdate ...$sites): string {
		$sites = new Collection($sites);

		$grouping = $this->groupHotTowers;
		if ($grouping === 1) {
			$sites = $sites->sortBy("site_id");
			$grouped = $sites->groupBy(function (FeedMessage\SiteUpdate $site): string {
				$pf = $this->pfCtrl->getPlayfieldById($site->playfield_id);
				return $pf?->long_name ?? "Unknown";
			});
		} elseif ($grouping === 2) {
			$sites = $sites->sortBy("ql");
			$grouped = $sites->groupBy(function (FeedMessage\SiteUpdate $site): string {
				return "TL" . $this->util->levelToTL($site->ql??1);
			});
		} elseif ($grouping === 3) {
			$sites = $sites->sortBy("ql");
			$grouped = $sites->groupBy("org_name");
		} elseif ($grouping === 4) {
			$sites = $sites->sortBy("ql");
			$grouped = $sites->groupBy("org_faction");
		} else {
			throw new Exception("Invalid grouping found");
		}

		$grouped = $grouped->sortKeys();
		$blob = $grouped->map(function (Collection $sites, string $short): string {
			return "<pagebreak><header2>{$short}<end>\n".
				$sites->map(function (FeedMessage\SiteUpdate $site): string {
					return $this->renderHotSite($site);
				})->join("\n");
		})->join("\n\n");
		return $blob;
	}

	/** Render the line of a single site for the !hot-command */
	private function renderHotSite(FeedMessage\SiteUpdate $site): string {
		$pf = $this->pfCtrl->getPlayfieldById($site->playfield_id);
		assert($pf !== null);
		assert(isset($site->gas));
		$shortName = "{$pf->short_name} {$site->site_id}";
		$line = "<tab>".
			$this->text->makeChatcmd(
				$shortName,
				"/tell <myname> <symbol>nw lc {$shortName}"
			);
		$line .= " QL {$site->min_ql}/<highlight>{$site->ql}<end>/{$site->max_ql} -";
		$factionColor = "";
		if (isset($site->org_faction)) {
			$factionColor = "<" . strtolower($site->org_faction) . ">";
			$org = $site->org_name ?? $site->org_faction;
			$line .= " {$factionColor}{$org}<end>";
		} else {
			$line .= " &lt;Free or unknown planter&gt;";
		}
		$gas = $this->getSiteGasInfo($site);
		assert(isset($gas));
		$currentGas = $gas->currentGas();
		assert(isset($currentGas));
		$line .= " " . $currentGas->colored();
		$goesHot = $gas->goesHot();
		$goesCold = $gas->goesCold();
		$regularGas = $gas->regularGas();
		$note = "";
		if (isset($regularGas) && $regularGas->gas !== $site->gas) {
			if ($goesCold > time()) {
				$note = ' the earliest';
			}
		}
		if (isset($goesHot)) {
			$line .= ", opens in " . $this->util->unixtimeToReadable($goesHot - time());
		} elseif (isset($goesCold)) {
			$goesColdText = ($goesCold <= time())
				? "any time now"
				: "in " . $this->util->unixtimeToReadable($goesCold - time());
			$line .= ", closes {$goesColdText}{$note}";
		}
		return $line;
	}

	/**
	 * Get the time the last attack was made from the org owning this site
	 *
	 * @return ?int Unix timestamp, or null if no recent attacks were made from here
	 */
	private function getLastAttackFrom(FeedMessage\SiteUpdate $site): ?int {
		if (!isset($site->ct_pos)) {
			return null;
		}

		/** @var ?FeedMessage\TowerAttack */
		$lastAttack = null;
		foreach ($this->attacks as $attack) {
			if ($attack->timestamp < $site->plant_time) {
				continue;
			}
			if (
				$attack->attacking_org !== $site->org_name
				|| $attack->attacking_faction !== $site->org_faction
			) {
				continue;
			}
			if (!isset($lastAttack) || $lastAttack->timestamp < $attack->timestamp) {
				$lastAttack = $attack;
			}
		}
		return $lastAttack?->timestamp;
	}
}
