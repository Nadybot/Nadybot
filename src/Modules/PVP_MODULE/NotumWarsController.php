<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use function Safe\json_decode;
use Amp\Http\Client\{HttpClientBuilder, Request};
use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject};
use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;
use Nadybot\Core\ParamClass\{PDuration, PTowerSite};
use Nadybot\Core\Routing\{RoutableMessage, Source};
use Nadybot\Core\{Attributes as NCA, CmdContext, Config\BotConfig, DB, EventManager, MessageHub, ModuleInstance, Nadybot, Safe, Text, Util};
use Nadybot\Modules\HELPBOT_MODULE\{Playfield, PlayfieldController};
use Nadybot\Modules\LEVEL_MODULE\LevelController;
use Nadybot\Modules\PVP_MODULE\FeedMessage\{TowerAttack, TowerOutcome};
use Nadybot\Modules\TIMERS_MODULE\{Alert, Timer, TimerController};
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
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
	NCA\EmitsMessages("pvp", "unplanted-sites"),
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
	NCA\DefineCommand(
		command: "nw towerqty",
		description: "Show how many towers each level is allowed to plant",
		accessLevel: "guest",
	),
	NCA\DefineCommand(
		command: "nw types",
		description: "Show the level ranges for tower types",
		accessLevel: "guest",
	),
]
/*#[
	NCA\DefineCommand(
		command: "nw test",
		description: "Run some sanity tests",
		accessLevel: "admin",
	),
]*/
class NotumWarsController extends ModuleInstance {
	public const TOWER_API = "https://towers.aobots.org/api/sites/";
	public const ATTACKS_API = "https://towers.aobots.org/api/attacks/";
	public const OUTCOMES_API = "https://towers.aobots.org/api/outcomes/";
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

	/** Show hot sites in the future as if it was that time */
	#[NCA\Setting\Boolean]
	public bool $hotFutureSitesAssume = true;

	/** Automatically fetch breed and gender of attackers from PORK (slow) */
	#[NCA\Setting\Boolean]
	public bool $towerAttackExtraInfo = false;

	/** Limit "Most Recent Attacks" to this duration */
	#[NCA\Setting\TimeOrOff(options: [
		'off', '1d', '3d', '7d', '14d', '31d',
	])]
	public int $mostRecentAttacksAge = 7 * 24 * 3600;

	/** Limit "Most Recent Victories" to this duration */
	#[NCA\Setting\TimeOrOff(options: [
		'off', '1d', '3d', '7d', '14d', '31d',
	])]
	public int $mostRecentOutcomesAge = 14 * 24 * 3600;

	/** Format of gas-change-messages */
	#[NCA\Setting\Template(
		exampleValues: [
			// ...FeedMessage\SiteUpdate::EXAMPLE_TOKENS,
			'site-pf-id' => 660,
			'site-id' => 6,
			'site-nr' => 6,
			'site-number' => 6,
			'site-enabled' => 1,
			'site-min-ql' => 20,
			'site-max-ql' => 30,
			'site-name' => 'Charred Groove',
			'site-num-conductors' => 0,
			'site-num-turrets' => 5,
			'site-num-cts' => 1,
			'site-gas' => '75%',
			'c-site-gas' => '<red>75%<end>',
			'site-faction' => 'Neutral',
			'c-site-faction' => '<neutral>Neutral<clan>',
			'site-org-id' => 1,
			'site-org-name' => 'Troet',
			'c-site-org-name' => '<neutral>Troet<end>',
			'site-plant-time' => '13-Jan-2023 17:07 UTC',
			'site-ct-ql' => 25,
			// ...Playfield::EXAMPLE_TOKENS,
			"pf-id" => 551,
			"pf-long" => "Wailing Wastes",
			"pf-short" => "WW",

			'gas-old' => '5%',
			'c-gas-old' => '<green>5%<end>',
			'gas-new' => '75%',
			'c-gas-new' => '<red>75%<end>',
			'details' => "<a href='itemref://301560/301560/30'>details</a>",
			'site-short' => Playfield::EXAMPLE_TOKENS['pf-short'] . ' ' .FeedMessage\SiteUpdate::EXAMPLE_TOKENS['site-id'],
			'c-site-short' => "<" . FeedMessage\SiteUpdate::EXAMPLE_TOKENS['site-faction'] . ">".
				Playfield::EXAMPLE_TOKENS['pf-short'] . ' ' .FeedMessage\SiteUpdate::EXAMPLE_TOKENS['site-id'].
				"<end>",
		],
		options: [
			"{c-site-short} {?gas-old:{c-gas-old} -> }{c-gas-new} [{details}]",
			"{c-site-short} {?gas-old:{c-gas-old} -> }{c-gas-new}",
			"{c-site-short} {site-min-ql}{?site-ct-ql:/<highlight>{site-ct-ql}<end>}/{site-max-ql} {?gas-old:{c-gas-old} -> }{c-gas-new}",
			"{c-site-short} {?site-org-name:({c-site-org-name}, QL <highlight>{site-ct-ql}<end>) }{?gas-old:{c-gas-old} -> }{c-gas-new}",
			"<highlight>{site-short}<end> {?gas-old:{c-gas-old} -> }{c-gas-new}{?site-org-name: ({c-site-org-name}, QL <highlight>{site-ct-ql}<end>)}",
			"{c-site-short} went {?gas-old:from {c-gas-old} }to {c-gas-new} [{details}]",
			"{c-site-short} went {?gas-old:from {c-gas-old} }to {c-gas-new}",
		],
		help: 'gas_change_format.txt',
	)]
	public string $gasChangeFormat = "{c-site-short} {?gas-old:{c-gas-old} -> }{c-gas-new}";

	/** Format of site-cold messages */
	#[NCA\Setting\Template(
		exampleValues: [
			// ...FeedMessage\SiteUpdate::EXAMPLE_TOKENS,
			'site-pf-id' => 660,
			'site-id' => 6,
			'site-nr' => 6,
			'site-number' => 6,
			'site-enabled' => 1,
			'site-min-ql' => 20,
			'site-max-ql' => 30,
			'site-name' => 'Charred Groove',
			'site-num-conductors' => 0,
			'site-num-turrets' => 5,
			'site-num-cts' => 1,
			'site-gas' => '75%',
			'c-site-gas' => '<red>75%<end>',
			'site-faction' => 'Neutral',
			'c-site-faction' => '<neutral>Neutral<clan>',
			'site-org-id' => 1,
			'site-org-name' => 'Troet',
			'c-site-org-name' => '<neutral>Troet<end>',
			'site-plant-time' => '13-Jan-2023 17:07 UTC',
			'site-ct-ql' => 25,
			// ...Playfield::EXAMPLE_TOKENS,
			"pf-id" => 551,
			"pf-long" => "Wailing Wastes",
			"pf-short" => "WW",

			'gas-old' => '5%',
			'c-gas-old' => '<green>5%<end>',
			'gas-new' => '75%',
			'c-gas-new' => '<red>75%<end>',
			'details' => "<a href='itemref://301560/301560/30'>details</a>",
			'site-short' => Playfield::EXAMPLE_TOKENS['pf-short'] . ' ' .FeedMessage\SiteUpdate::EXAMPLE_TOKENS['site-id'],
			'c-site-short' => "<" . FeedMessage\SiteUpdate::EXAMPLE_TOKENS['site-faction'] . ">".
				Playfield::EXAMPLE_TOKENS['pf-short'] . ' ' .FeedMessage\SiteUpdate::EXAMPLE_TOKENS['site-id'].
				"<end>",
		],
		options: [
			"{c-site-short} {?gas-old:{c-gas-old} -> }{c-gas-new}",
			"{c-site-short} {?gas-old:{c-gas-old} -> }{c-gas-new} [{details}]",
			"{c-site-short} is now <red>cold<end>",
			"{c-site-short} is now <red>cold<end> [{details}]",
			"{c-site-short} went {?gas-old:from {c-gas-old} }to {c-gas-new}",
			"{c-site-short} went {?gas-old:from {c-gas-old} }to {c-gas-new} [{details}]",
		],
		help: 'site_goes_cold_format.txt',
	)]
	public string $siteGoesColdFormat = "{c-site-short} is now <red>cold<end> [{details}]";

	/** Format of site-hot messages */
	#[NCA\Setting\Template(
		exampleValues: [
			// ...FeedMessage\SiteUpdate::EXAMPLE_TOKENS,
			'site-pf-id' => 660,
			'site-id' => 6,
			'site-nr' => 6,
			'site-number' => 6,
			'site-enabled' => 1,
			'site-min-ql' => 20,
			'site-max-ql' => 30,
			'site-name' => 'Charred Groove',
			'site-num-conductors' => 0,
			'site-num-turrets' => 5,
			'site-num-cts' => 1,
			'site-gas' => '75%',
			'c-site-gas' => '<red>75%<end>',
			'site-faction' => 'Neutral',
			'c-site-faction' => '<neutral>Neutral<clan>',
			'site-org-id' => 1,
			'site-org-name' => 'Troet',
			'c-site-org-name' => '<neutral>Troet<end>',
			'site-plant-time' => '13-Jan-2023 17:07 UTC',
			'site-ct-ql' => 25,
			// ...Playfield::EXAMPLE_TOKENS,
			"pf-id" => 551,
			"pf-long" => "Wailing Wastes",
			"pf-short" => "WW",

			'gas-old' => '75%',
			'c-gas-old' => '<red>75%<end>',
			'gas-new' => '25%',
			'c-gas-new' => '<green>25%<end>',
			'details' => "<a href='itemref://301560/301560/30'>details</a>",
			'site-short' => Playfield::EXAMPLE_TOKENS['pf-short'] . ' ' .FeedMessage\SiteUpdate::EXAMPLE_TOKENS['site-id'],
			'c-site-short' => "<" . FeedMessage\SiteUpdate::EXAMPLE_TOKENS['site-faction'] . ">".
				Playfield::EXAMPLE_TOKENS['pf-short'] . ' ' .FeedMessage\SiteUpdate::EXAMPLE_TOKENS['site-id'].
				"<end>",
		],
		options: [
			"{c-site-short} {?gas-old:{c-gas-old} -> }{c-gas-new}",
			"{c-site-short} {?gas-old:{c-gas-old} -> }{c-gas-new} [{details}]",
			"{c-site-short} is now <green>hot<end>",
			"{c-site-short} is now <green>hot<end> [{details}]",
			"{c-site-short} {?site-org-name:({c-site-org-name}, QL <highlight>{site-ct-ql}<end>) }is now <green>hot<end>",
			"{c-site-short} {?site-org-name:({c-site-org-name}, QL <highlight>{site-ct-ql}<end>) }is now <green>hot<end> [{details}]",
			"{c-site-short} {site-min-ql}{?site-ct-ql:/<highlight>{site-ct-ql}<end>}/{site-max-ql} is now <green>hot<end>",
			"{c-site-short} {site-min-ql}{?site-ct-ql:/<highlight>{site-ct-ql}<end>}/{site-max-ql} is now <green>hot<end> [{details}]",
			"{c-site-short} went {?gas-old:from {c-gas-old} }to {c-gas-new}",
			"{c-site-short} went {?gas-old:from {c-gas-old} }to {c-gas-new} [{details}]",
		],
		help: 'site_goes_cold_format.txt',
	)]
	public string $siteGoesHotFormat = "{c-site-short} is now <green>hot<end> [{details}]";

	/** Format of site-planted messages */
	#[NCA\Setting\Template(
		exampleValues: [
			// ...FeedMessage\SiteUpdate::EXAMPLE_TOKENS,
			'site-pf-id' => 660,
			'site-id' => 6,
			'site-nr' => 6,
			'site-number' => 6,
			'site-enabled' => 1,
			'site-min-ql' => 20,
			'site-max-ql' => 30,
			'site-name' => 'Charred Groove',
			'site-num-conductors' => 0,
			'site-num-turrets' => 5,
			'site-num-cts' => 1,
			'site-gas' => '75%',
			'c-site-gas' => '<red>75%<end>',
			'site-faction' => 'Neutral',
			'c-site-faction' => '<neutral>Neutral<clan>',
			'site-org-id' => 1,
			'site-org-name' => 'Troet',
			'c-site-org-name' => '<neutral>Troet<end>',
			'site-plant-time' => '13-Jan-2023 17:07 UTC',
			'site-ct-ql' => 25,
			// ...Playfield::EXAMPLE_TOKENS,
			"pf-id" => 551,
			"pf-long" => "Wailing Wastes",
			"pf-short" => "WW",

			'details' => "<a href='itemref://301560/301560/30'>details</a>",
			'site-short' => Playfield::EXAMPLE_TOKENS['pf-short'] . ' ' .FeedMessage\SiteUpdate::EXAMPLE_TOKENS['site-id'],
			'c-site-short' => "<" . FeedMessage\SiteUpdate::EXAMPLE_TOKENS['site-faction'] . ">".
				Playfield::EXAMPLE_TOKENS['pf-short'] . ' ' .FeedMessage\SiteUpdate::EXAMPLE_TOKENS['site-id'].
				"<end>",
		],
		options: [
			"{c-site-short} (QL <highlight>{site-ct-ql}<end>, {c-site-org-name}) planted [{details}]",
			"<highlight>{site-short}<end> (QL <highlight>{site-ct-ql}<end>, {c-site-org-name}) planted [{details}]",
			"{c-site-short} (QL {site-min-ql}/<highlight>{site-ct-ql}<end>/{site-max-ql}, {c-site-org-name}) planted [{details}]",
			"<highlight>{site-short}<end> (QL {site-min-ql}/<highlight>{site-ct-ql}<end>/{site-max-ql}, {c-site-org-name}) planted [{details}]",
			"{c-site-short} @ QL <highlight>{site-ct-ql}<end> planted by {c-site-org-name} [{details}]",
			"<highlight>{site-short}<end> @ QL <highlight>{site-ct-ql}<end> planted by {c-site-org-name} [{details}]",
			"{c-site-short} {site-min-ql}/<highlight>{site-ct-ql}<end>/{site-max-ql} planted by {c-site-org-name} [{details}]",
			"<highlight>{site-short}<end> {site-min-ql}/<highlight>{site-ct-ql}<end>/{site-max-ql} planted by {c-site-org-name} [{details}]",
		],
		help: 'site_planted_format.txt',
	)]
	public string $sitePlantedFormat = "{c-site-short} (QL <highlight>{site-ct-ql}<end>, {c-site-org-name}) planted [{details}]";

	/** Format of site-destroyed messages */
	#[NCA\Setting\Template(
		exampleValues: [
			// ...FeedMessage\SiteUpdate::EXAMPLE_TOKENS,
			'site-pf-id' => 660,
			'site-id' => 6,
			'site-nr' => 6,
			'site-number' => 6,
			'site-enabled' => 1,
			'site-min-ql' => 20,
			'site-max-ql' => 30,
			'site-name' => 'Charred Groove',
			'site-num-conductors' => 0,
			'site-num-turrets' => 5,
			'site-num-cts' => 1,
			'site-gas' => '75%',
			'c-site-gas' => '<red>75%<end>',
			'site-faction' => 'Neutral',
			'c-site-faction' => '<neutral>Neutral<clan>',
			'site-org-id' => 1,
			'site-org-name' => 'Troet',
			'c-site-org-name' => '<neutral>Troet<end>',
			'site-plant-time' => '13-Jan-2023 17:07 UTC',
			'site-ct-ql' => 25,
			// ...Playfield::EXAMPLE_TOKENS,
			"pf-id" => 551,
			"pf-long" => "Wailing Wastes",
			"pf-short" => "WW",

			'details' => "<a href='itemref://301560/301560/30'>details</a>",
			'site-short' => Playfield::EXAMPLE_TOKENS['pf-short'] . ' ' .FeedMessage\SiteUpdate::EXAMPLE_TOKENS['site-id'],
			'c-site-short' => "<" . FeedMessage\SiteUpdate::EXAMPLE_TOKENS['site-faction'] . ">".
				Playfield::EXAMPLE_TOKENS['pf-short'] . ' ' .FeedMessage\SiteUpdate::EXAMPLE_TOKENS['site-id'].
				"<end>",
		],
		options: [
			"{c-site-short} (QL <highlight>{site-ct-ql}<end>, {c-site-org-name}) destroyed [{details}]",
			"<highlight>{site-short}<end> (QL <highlight>{site-ct-ql}<end>, {c-site-org-name}) destroyed [{details}]",
			"{c-site-short} @ QL <highlight>{site-ct-ql}<end> destroyed [{details}]",
			"<highlight>{site-short}<end> @ QL <highlight>{site-ct-ql}<end> destroyed [{details}]",
			"{c-site-short} {site-min-ql}/<highlight>{site-ct-ql}<end>/{site-max-ql} destroyed [{details}]",
			"<highlight>{site-short}<end> {site-min-ql}/<highlight>{site-ct-ql}<end>/{site-max-ql} destroyed [{details}]",
			"{c-site-short} (QL {site-min-ql}/<highlight>{site-ct-ql}<end>/{site-max-ql}, {c-site-org-name}) was destroyed [{details}]",
			"<highlight>{site-short}<end> (QL {site-min-ql}/<highlight>{site-ct-ql}<end>/{site-max-ql}, {c-site-org-name}) was destroyed [{details}]",
		],
		help: 'site_destroyed_format.txt',
	)]
	public string $siteDestroyedFormat = "{c-site-short} (QL <highlight>{site-ct-ql}<end>, {c-site-org-name}) destroyed [{details}]";

	/** Format of tower-destroyed/tower-planted messages */
	#[NCA\Setting\Template(
		exampleValues: [
			// ...FeedMessage\SiteUpdate::EXAMPLE_TOKENS,
			'site-pf-id' => 660,
			'site-id' => 6,
			'site-nr' => 6,
			'site-number' => 6,
			'site-enabled' => 1,
			'site-min-ql' => 20,
			'site-max-ql' => 30,
			'site-name' => 'Charred Groove',
			'site-num-conductors' => 0,
			'site-num-turrets' => 5,
			'site-num-cts' => 1,
			'site-gas' => '75%',
			'c-site-gas' => '<red>75%<end>',
			'site-faction' => 'Neutral',
			'c-site-faction' => '<neutral>Neutral<clan>',
			'site-org-id' => 1,
			'site-org-name' => 'Troet',
			'c-site-org-name' => '<neutral>Troet<end>',
			'site-plant-time' => '13-Jan-2023 17:07 UTC',
			'site-ct-ql' => 25,
			// ...Playfield::EXAMPLE_TOKENS,
			"pf-id" => 551,
			"pf-long" => "Wailing Wastes",
			"pf-short" => "WW",

			'details' => "<a href='itemref://301560/301560/30'>details</a>",
			'site-short' => Playfield::EXAMPLE_TOKENS['pf-short'] . ' ' .FeedMessage\SiteUpdate::EXAMPLE_TOKENS['site-id'],
			'c-site-short' => "<" . FeedMessage\SiteUpdate::EXAMPLE_TOKENS['site-faction'] . ">".
				Playfield::EXAMPLE_TOKENS['pf-short'] . ' ' .FeedMessage\SiteUpdate::EXAMPLE_TOKENS['site-id'].
				"<end>",
			'tower-action' => 'plant',
			'tower-type' => 'turret',
			'tower-delta' => '+1',
			'c-tower-delta' => '<green>+1<end>',
			'c-site-num-turrets' => '<green>5 turrets<end>',
			'c-site-num-conductors' => '0 conductors',
		],
		options: [
			"{c-site-short} {tower-type}s {c-tower-delta} [{details}]",
			"{c-site-short} (QL <highlight>{site-ct-ql}<end>, {c-site-org-name}) {tower-action}ed 1 {tower-type}",
			"{c-site-short} (QL <highlight>{site-ct-ql}<end>, {c-site-org-name}) {c-tower-delta} {tower-type}",
			"{c-site-short} (QL <highlight>{site-ct-ql}<end>, {c-site-org-name}) {c-site-num-turrets}, {c-site-num-conductors}",
		],
		help: 'site_tower_change_format.txt',
	)]
	public string $siteTowerChangeFormat = "{c-site-short} {tower-type}s {c-tower-delta} [{details}]";

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Inject]
	private PlayfieldController $pfCtrl;

	#[NCA\Inject]
	private SiteTrackerController $siteTracker;

	#[NCA\Inject]
	private MessageHub $msgHub;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private LevelController $lvlCtrl;

	#[NCA\Inject]
	private TimerController $timerController;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private Util $util;

	#[NCA\Event("timer(1h)", "Announce unplanted sites via pvp(unplanted-sites)")]
	public function announceUnplantedSites(): void {
		$unplantedSites = $this->getUnplantedSites();
		if (empty($unplantedSites)) {
			return;
		}
		$announcementPre = "There is ";
		$announcement = "1 unplanted site";
		if (count($unplantedSites) > 1) {
			$announcementPre = "There are ";
			$announcement = count($unplantedSites) . " unplanted sites";
		}

		$msgs = $this->text->blobWrap(
			$announcementPre,
			$this->text->makeBlob(
				$announcement,
				join("\n\n", $unplantedSites),
			),
			" for grab"
		);
		foreach ($msgs as $msg) {
			$rMsg = new RoutableMessage($msg);
			$rMsg->prependPath(new Source("pvp", "unplanted-sites"));
			$this->msgHub->handle($rMsg);
		}
	}

	#[NCA\Event("connect", "Load all towers from the API")]
	public function initTowersFromApi(): void {
		$client = $this->builder->build();

		$response = $client->request(new Request(self::TOWER_API));
		if ($response->getStatus() !== 200) {
			$this->logger->error("Error calling the tower-api: HTTP-code {code}", [
				"code" => $response->getStatus(),
			]);
			return;
		}
		$body = $response->getBody()->buffer();
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

	#[NCA\Setup]
	public function setup(): void {
		$this->attacks = $this->db->table(self::DB_ATTACKS)
			->where("timestamp", ">=", time() - 6 * 3600)
			->orderByDesc("timestamp")
			->asObj(DBTowerAttack::class)
			->map(function (DBTowerAttack $attack): FeedMessage\TowerAttack {
				return $attack->toTowerAttack();
			})
			->toArray();

		$this->outcomes = $this->db->table(self::DB_OUTCOMES)
			->where("timestamp", ">=", time() - 7200)
			->orderByDesc("timestamp")
			->asObj(DBOutcome::class)
			->map(function (DBOutcome $outcome): TowerOutcome {
				return $outcome->toTowerOutcome();
			})
			->toArray();
	}

	#[NCA\Event("connect", "Load all attacks from the API")]
	public function initAttacksFromApi(): void {
		$maxTS = $this->db->table(self::DB_ATTACKS)->max("timestamp");
		$client = $this->builder->build();
		$uri = self::ATTACKS_API;
		if (isset($maxTS)) {
			$uri .= "?" . http_build_query(["since" => $maxTS+1]);
		}

		$response = $client->request(new Request($uri));
		if ($response->getStatus() !== 200) {
			$this->logger->error("Error calling the attacks-api: HTTP-code {code}", [
				"code" => $response->getStatus(),
			]);
			return;
		}
		$body = $response->getBody()->buffer();
		try {
			$json = json_decode($body, true);
			$mapper = new ObjectMapperUsingReflection();

			$attacks = $mapper->hydrateObjects(FeedMessage\TowerAttack::class, $json);

			/** @psalm-suppress InternalMethod */
			foreach ($attacks as $attack) {
				$breedRequired = !isset($attack->attacker->breed)
					&& $this->towerAttackExtraInfo;
				$infoMissing = !isset($attack->attacker->level)
					|| !isset($attack->attacker->faction);
				if (
					isset($attack->attacker->character_id)
					&& ($breedRequired || $infoMissing)
				) {
					$player = $this->playerManager->byName($attack->attacker->name);
					$attack->addLookups($player);
				}
				$attInfo = DBTowerAttack::fromTowerAttack($attack);
				$this->db->insert(self::DB_ATTACKS, $attInfo, null);
			}

			$this->attacks = [];
			// All attacks from up to 6h ago that penalize can influence the current hot-duration
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
	public function initOutcomesFromApi(): void {
		$maxTS = $this->db->table(self::DB_OUTCOMES)->max("timestamp");
		$client = $this->builder->build();
		$uri = self::OUTCOMES_API;
		if (isset($maxTS)) {
			$uri .= "?" . http_build_query(["since" => $maxTS+1]);
		}

		$response = $client->request(new Request($uri));
		if ($response->getStatus() !== 200) {
			$this->logger->error("Error calling the outcome-api: HTTP-code {code}", [
				"code" => $response->getStatus(),
			]);
			return;
		}
		$body = $response->getBody()->buffer();
		try {
			$json = json_decode($body, true);
			$mapper = new ObjectMapperUsingReflection();

			$outcomes = $mapper->hydrateObjects(FeedMessage\TowerOutcome::class, $json);

			/** @psalm-suppress InternalMethod */
			foreach ($outcomes as $outcome) {
				$this->db->insert(self::DB_OUTCOMES, DBOutcome::fromTowerOutcome($outcome), null);
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
	public function updateSiteInfoFromFeed(Event\SiteUpdateEvent $event): void {
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
	public function updateTowerOutcomeInfoFromFeed(Event\TowerOutcomeEvent $event): void {
		$dbOutcome = DBOutcome::fromTowerOutcome($event->outcome);
		$this->db->insert(self::DB_OUTCOMES, $dbOutcome);
		$this->outcomes = (new Collection([$event->outcome, ...$this->outcomes]))
			->where("timestamp", ">=", time() - 3600)
			->toArray();
	}

	#[NCA\Event("tower-attack", "Update tower attacks from the API")]
	public function updateTowerAttackInfoFromFeed(Event\TowerAttackEvent $event): void {
		$attack = $event->attack;
		$attacker = $attack->attacker;
		$this->registerAttack($attack);
		$breedRequired = !isset($attacker->breed) && $this->towerAttackExtraInfo;
		$infoMissing = !isset($attacker->level) || !isset($attacker->faction);
		$player = $this->playerManager->findInDb($attacker->name, $this->config->main->dimension);
		if (isset($player)) {
			$attack->addLookups($player);
		} elseif (isset($attacker->character_id) && ($breedRequired || $infoMissing)) {
			$player = $this->playerManager->byName($attacker->name);
			$attack->addLookups($player);
		}
		$site = $this->state[$attack->playfield_id][$attack->site_id]??null;
		if (isset($site)) {
			$attack->ql ??= $site->ql;
		}
		$attInfo = DBTowerAttack::fromTowerAttack($attack);
		$this->db->insert(self::DB_ATTACKS, $attInfo);
		$infoEvent = new Event\TowerAttackInfoEvent($attack, $site);
		$this->eventManager->fireEvent($infoEvent);
		if (isset($player)) {
			return;
		}
		// If we're still missing whois-data, fill it in 1s-30s later
		// so we don't flood PORK
		EventLoop::delay(random_int(1, 30), function () use ($attack): void {
			$player = $this->playerManager->byName($attack->attacker->name);
			$attack->addLookups($player);
			$attInfo = DBTowerAttack::fromTowerAttack($attack);
			$this->db->update(
				self::DB_ATTACKS,
				["playfield_id", "site_id", "timestamp", "def_org", "att_name"],
				$attInfo
			);
		});
	}

	#[NCA\Event("gas-update", "Update gas information from the API")]
	public function updateGasInfoFromFeed(Event\GasUpdateEvent $event): void {
		$site = $this->state[$event->gas->playfield_id][$event->gas->site_id] ?? null;
		if (!isset($site)) {
			return;
		}
		$pf = $this->pfCtrl->getPlayfieldById($site->playfield_id);
		if (!isset($pf)) {
			return;
		}
		if ($site->gas === $event->gas->gas) {
			return;
		}
		$oldGas = isset($site->gas) ? new Gas($site->gas) : null;
		$newGas = new Gas($event->gas->gas);
		$site->gas = $event->gas->gas;
		$this->unpenalizeAttacksFromSiteOwner($site);
		$tokens = array_merge(
			$site->getTokens(),
			$pf->getTokens(),
			[
				'gas-old' => isset($oldGas) ? "{$oldGas->gas}%" : null,
				'gas-new' => "{$event->gas->gas}%",
				'c-gas-new' => $newGas->colored(),
				'c-gas-old' => isset($oldGas) ? $oldGas->colored() : null,
				'site-short' => "{$pf->short_name} {$site->site_id}",
				'c-site-short' => isset($site->org_faction)
					? "<" . strtolower($site->org_faction) . ">{$pf->short_name} {$site->site_id}<end>"
					: "<highlight>{$pf->short_name} {$site->site_id}<end>",
			]
		);
		$tokens['details'] = ((array)$this->text->makeBlob(
			"details",
			$this->renderSite($site, $pf),
			"{$pf->short_name} {$site->site_id} ({$site->name})",
		))[0];
		$color = strtolower($site->org_faction ?? "neutral");
		$msg = $this->text->renderPlaceholders($this->gasChangeFormat, $tokens);
		$rMessage = new RoutableMessage($msg);
		$rMessage->prependPath(new Source("pvp", "gas-change-{$color}"));
		$this->msgHub->handle($rMessage);
		$this->siteTracker->fireEvent(new RoutableMessage($msg), $site, "gas-change");

		if ($newGas->gas === 75 && $oldGas?->gas !== 75 && isset($site->org_faction)) {
			$source = "site-cold-{$site->org_faction}";
			$trackerSource = "site-cold";
			$msg = $this->text->renderPlaceholders($this->siteGoesColdFormat, $tokens);
		} elseif ($newGas->gas !== 75 && $oldGas?->gas === 75 && isset($site->org_faction)) {
			$source = "site-hot-{$site->org_faction}";
			$trackerSource = "site-hot";
			$msg = $this->text->renderPlaceholders($this->siteGoesHotFormat, $tokens);
		} else {
			return;
		}
		$rMessage = new RoutableMessage($msg);
		$rMessage->prependPath(new Source("pvp", $source));
		$this->msgHub->handle($rMessage);
		$this->siteTracker->fireEvent(new RoutableMessage($msg), $site, $trackerSource);
	}

	/** Get the current gas for a site and information */
	public function getSiteGasInfo(FeedMessage\SiteUpdate $site, ?int $time=null): ?GasInfo {
		$lastAttack = $this->getLastAttackFrom($site);
		return new GasInfo($site, $lastAttack, $time);
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
	public function renderSite(
		FeedMessage\SiteUpdate $site,
		Playfield $pf,
		bool $showOrgLinks=true,
		bool $showPlantInfo=true,
		?FeedMessage\TowerOutcome $outcome=null,
	): string {
		$lastOutcome = $outcome ?? $this->getLastSiteOutcome($site);
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
				", {$site->num_turrets} ".
				$this->text->pluralize("turret", $site->num_turrets).
				", {$site->num_conductors} ".
				$this->text->pluralize("conductor", $site->num_conductors).
				"\n";
		} else {
			// If the site is unplanted, show destruction information and links to plant
			$blob .= "<tab>Planted: <highlight>No<end>\n";
			// If the site was destroyed less than 1 hour ago, show by who
			if (isset($lastOutcome) && $lastOutcome->timestamp + 3600 > time()) {
				if (isset($lastOutcome->attacker_org, $lastOutcome->attacker_faction)) {
					$blob .= "<tab>Destroyed by: ".
						"<" . strtolower($lastOutcome->attacker_faction) . ">".
						$lastOutcome->attacker_org . "<end>";
				} else {
					$blob .= "<tab>Abandoned by: ".
						"<" . strtolower($lastOutcome->losing_faction) . ">".
						$lastOutcome->losing_org . "<end>";
				}
				if ($showPlantInfo) {
					$blob .= " " . $this->util->unixtimeToReadable(time() - $lastOutcome->timestamp).
						" ago\n";
				} else {
					$blob .= " on " . $this->util->date($lastOutcome->timestamp) . "\n";
				}
				if ($showPlantInfo) {
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
				}
			} elseif ($showPlantInfo) {
				$blob .= "<tab>Plantable: <highlight>Now<end>\n";
			}
		}
		$blob .= "<tab>Coordinates: [{$centerWaypointLink}]";
		if (isset($ctWaypointLink)) {
			$blob .= " [{$ctWaypointLink}]";
		}
		$links = [];
		$numRecentAttacks = $this->countRecentAttacks($site);
		if ($numRecentAttacks > 0) {
			$links []= $this->text->makeChatcmd(
				"{$numRecentAttacks} ".
				(($this->mostRecentAttacksAge > 0) ? "recent " : "").
				$this->text->pluralize("attack", $numRecentAttacks),
				"/tell <myname> nw attacks {$pf->short_name} {$site->site_id}"
			);
		}
		$numRecentOutcomes = $this->countRecentOutcomes($site);
		if ($numRecentOutcomes > 0) {
			$links []= $this->text->makeChatcmd(
				"{$numRecentOutcomes} ".
				(($this->mostRecentOutcomesAge > 0) ? "recent " : "").
				$this->text->pluralize("victory", $numRecentOutcomes),
				"/tell <myname> nw victory {$pf->short_name} {$site->site_id}"
			);
		}
		if (count($links) > 0) {
			$blob .= "\n<tab>Stats: [" . join("] [", $links) . "]";
		}

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
		#[NCA\Str("timer")]
		string $action,
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
		#[NCA\StrChoice("unplanted", "free")]
		string $action,
	): void {
		$unplantedSites = $this->getUnplantedSites();
		if (empty($unplantedSites)) {
			$context->reply("No unplanted sites.");
			return;
		}
		$msg = $this->text->makeBlob(
			"Unplanted sites (" . count($unplantedSites) . ")",
			join("\n\n", $unplantedSites)
		);
		$context->reply($msg);
	}

	/** See which orgs have the highest contract points. */
	#[NCA\HandlesCommand("nw")]
	public function highContractsCommand(
		CmdContext $context,
		#[NCA\Str("top", "highcontracts", "highcontract")]
		string $action,
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
	 * faction, playfield, ql, penalty, level range and "soon"
	 */
	#[NCA\HandlesCommand("nw hot")]
	#[NCA\Help\Example("<symbol>nw hot clan")]
	#[NCA\Help\Example("<symbol>nw hot pw")]
	#[NCA\Help\Example("<symbol>nw hot 60", "Only those in PvP-range for a level 60 char")]
	#[NCA\Help\Example("<symbol>nw hot 99-110", "Only where the CT is between QL 99 and 110")]
	#[NCA\Help\Example("<symbol>nw hot omni pw 180-300")]
	#[NCA\Help\Example("<symbol>nw hot soon")]
	#[NCA\Help\Example("<symbol>nw hot penalty")]
	public function hotSitesCommand(
		CmdContext $context,
		#[NCA\Str("hot")]
		string $action,
		?string $search
	): void {
		$search ??= "";
		if (substr($search, 0, 1) !== " ") {
			$search = " {$search}";
		}
		$hotSites = $this->getEnabledSites()
			->whereNotNull("gas")
			->whereNotNull("ql");
		$search = Safe::pregReplace("/\s+soon\b/i", "", $search, -1, $soon);
		$time = null;
		if ($soon > 0) {
			$this->logger->info("Found <soon> keyword");
			$hotSites = $hotSites->filter(
				function (FeedMessage\SiteUpdate $site): bool {
					if ($site->gas !== 75) {
						return false;
					}
					return $this->getSiteGasInfo($site)?->gasAt(time() + 3600)?->gas === 25;
				}
			);
		} else {
			$durationRegexp = PDuration::getStrictRegexp();
			if (
				count($future = Safe::pregMatch(chr(1) . '\s+' . $durationRegexp . '\b' . chr(1), $search))
			) {
				$search = Safe::pregReplace('/\s+' . preg_quote($future[0], "/") . '\b/', "", $search, -1);
				$this->logger->info("Found duration <{duration}>", [
					"duration" => trim($future[0]),
				]);
				$time = time() + (new PDuration($future[0]))->toSecs();
				$hotSites = $hotSites->filter(
					function (FeedMessage\SiteUpdate $site) use ($time): bool {
						$gas = $this->getSiteGasInfo($site)?->gasAt($time)?->gas;
						return isset($gas) && $gas < 75;
					}
				);
				if (!$this->hotFutureSitesAssume) {
					$time = null;
				}
			} else {
				$hotSites = $hotSites->where("gas", "<", 75);
			}
		}
		$search = Safe::pregReplace("/\s+penalty\b/i", "", $search, -1, $penalty);
		if ($penalty > 0) {
			$this->logger->info("Found <penalty> keyword");
			$hotSites = $hotSites->filter(function (FeedMessage\SiteUpdate $site): bool {
				$gas = $this->getSiteGasInfo($site);
				if (!isset($gas)) {
					return false;
				}
				return $gas->inPenalty();
			});
		}
		if (count($matches = Safe::pregMatch("/\s+(neutral|omni|clan|neut)\b/i", $search)) === 2) {
			$this->logger->info("Found <{side}> keyword", [
				"side" => $matches[1],
			]);
			$faction = strtolower($matches[1]);
			$search = Safe::pregReplace("/\s+(neutral|omni|clan|neut)\b/i", "", $search);
			if ($faction === "neut") {
				$faction = "neutral";
			}
			$hotSites = $hotSites->where("org_faction", ucfirst($faction));
		}
		if (count($matches = Safe::pregMatch("/\s+(\d+)\s*-\s*(\d+)\b/", $search)) === 3) {
			$this->logger->info("Found level range <{from}>-<{to}>", [
				"from" => $matches[1],
				"to" => $matches[2],
			]);
			$hotSites = $hotSites->where("ql", ">=", (int)$matches[1])
				->where("ql", "<=", (int)$matches[2]);
			$search = Safe::pregReplace("/\s+(\d+)\s*-\s*(\d+)\b/", "", $search);
		}
		if (count($matches = Safe::pregMatch("/\s+(\d+)\b/", $search)) === 2) {
			$this->logger->info("Found level <{level}>", [
				"level" => $matches[1],
			]);
			$lvlInfo = $this->lvlCtrl->getLevelInfo((int)$matches[1]);
			if (!isset($lvlInfo)) {
				$context->reply("<highlight>{$matches[1]}<end> is an invalid level.");
				return;
			}
			$hotSites = $hotSites->where("ql", ">=", $lvlInfo->pvpMin);
			$hotSites = $hotSites->where("ql", "<=", ($lvlInfo->pvpMax === 220) ? 300 : $lvlInfo->pvpMax);
			$search = Safe::pregReplace("/\s+(\d+)\b/", "", $search);
		}
		if (count($matches = Safe::pregMatch("/\s+([a-z]{2,}|\d[a-z]{2,})\b/i", $search)) >= 2) {
			$this->logger->info("Found playfield search for <{pf}>", [
				"pf" => $matches[1],
			]);
			$pf = $this->pfCtrl->getPlayfieldByName($matches[1]);
			if (!isset($pf)) {
				$context->reply("Unable to find playfield <highlight>{$matches[1]}<end>.");
				return;
			}
			$hotSites = $hotSites->where("playfield_id", $pf->id);
			$search = Safe::pregReplace("/\s+([a-z]{2,}|\d[a-z]{2,})\b/i", "", $search);
		}
		$search = trim($search);
		if ($hotSites->isEmpty()) {
			if ($soon > 0) {
				$context->reply("No sites are going hot soon.");
			} elseif ($penalty > 0) {
				$context->reply("No sites are currently in penalty.");
			} else {
				$context->reply("No sites are currently hot.");
			}
			return;
		}
		$blob = $this->renderHotSites($time, ...$hotSites->toArray());
		if ($soon > 0) {
			$sitesLabel = isset($faction) ? ucfirst(strtolower($faction)) . " sites" : "Sites";
			$msg = $this->text->makeBlob("{$sitesLabel} going hot soon ({$hotSites->count()})", $blob);
		} else {
			$faction = isset($faction) ? " " . strtolower($faction) : "";
			$inPenalty = ($penalty > 0) ? " in penalty" : "";
			$msg = $this->text->makeBlob("Hot{$faction} sites{$inPenalty} ({$hotSites->count()})", $blob);
		}

		$context->reply($msg);
	}

	/** List all your org's tower sites */
	#[NCA\HandlesCommand("nw sites")]
	public function listMyOrgsSitesCommand(
		CmdContext $context,
		#[NCA\Str("sites")]
		string $action,
	): void {
		$player = $this->playerManager->byName($context->char->name);
		if (!isset($player) || !isset($player->guild_id)) {
			$context->reply("You are currently not in an org.");
			return;
		}
		assert(isset($player->guild));
		$matches = $this->getEnabledSites()->whereStrict("org_id", $player->guild_id);
		$orgColor = strtolower($player->faction);
		if ($matches->isEmpty()) {
			$context->reply("<{$orgColor}>{$player->guild}<end> currently don't have any tower fields.");
			return;
		}
		$blob = $this->renderOrgSites(...$matches->toArray());
		$msg = $this->text->makeBlob(
			"All tower sites of {$player->guild}",
			$blob
		);
		$context->reply($msg);
	}

	/** List all tower sites matching an org ID */
	#[NCA\HandlesCommand("nw sites")]
	public function listOrgSitesByIDCommand(
		CmdContext $context,
		#[NCA\Str("sites")]
		string $action,
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
	 * List all tower sites matching an org name or a character's org
	 *
	 * You can use * as a wildcard match for the org name
	 * You can put "org" in front of an org name to search in org names only
	 */
	#[NCA\HandlesCommand("nw sites")]
	#[NCA\Help\Example("<symbol>nw sites troet")]
	#[NCA\Help\Example("<symbol>nw sites org goblinz")]
	#[NCA\Help\Example("<symbol>nw sites angel*")]
	#[NCA\Help\Example("<symbol>nw sites nady")]
	public function listOrgSitesCommand(
		CmdContext $context,
		#[NCA\Str("sites")]
		string $action,
		#[NCA\Str("org")]
		?string $forceOrg,
		string $search
	): void {
		$searchTerm = $search;
		$player = null;
		if (!isset($forceOrg)) {
			$uid = $this->chatBot->getUid($search);
			if (isset($uid)) {
				$player = $this->playerManager->byName($search);
				if (isset($player, $player->guild_id)) {
					$searchTerm = "{$search}/{$player->guild}";
				}
			}
		}
		$matches = $this->getEnabledSites()
			->filter(function (FeedMessage\SiteUpdate $site) use ($search, $player): bool {
				if (!isset($site->org_name)) {
					return false;
				}
				if (isset($player, $player->guild_id)   && $player->guild_id === $site->org_id) {
					return true;
				}
				return fnmatch($search, $site->org_name, FNM_CASEFOLD);
			});
		if ($matches->isEmpty()) {
			$context->reply("No tower sites match your search criteria.");
			return;
		}
		$blob = $this->renderOrgSites(...$matches->toArray());
		$msg = $this->text->makeBlob(
			"All tower sites of '{$searchTerm}'",
			$blob
		);
		$context->reply($msg);
	}

	/** Show how many towers you are allowed to plant. Add 'all' to show it generally */
	#[NCA\HandlesCommand("nw towerqty")]
	public function towerQtyCommand(
		CmdContext $context,
		#[NCA\Str("towerqty")]
		string $action,
		#[NCA\Str("all")]
		?string $all,
	): void {
		if (isset($all)) {
			$msg = $this->text->makeBlob("Allowed number of towers", $this->getAllTowerQuantitiesBlob());
			$context->reply($msg);
			return;
		}

		$player = $this->playerManager->byName($context->char->name);
		$blob = $this->getAllTowerQuantitiesBlob();
		if (!isset($player)) {
			$msg = $this->text->makeBlob("Allowed number of towers", $blob);
			$context->reply($msg);
			return;
		}
		if ($player->level < 15) {
			$msg = "Your level is too low to have any towers.";
		} elseif ($player->level < 75) {
			$msg = "Your level ({$player->level}) allows you to have <highlight>1<end> tower.";
		} elseif ($player->level < 150) {
			$msg = "Your level ({$player->level}) allows you to have <highlight>2<end> towers.";
		} elseif ($player->level < 200) {
			$msg = "Your level ({$player->level}) allows you to have <highlight>3<end> towers.";
		} else {
			$msg = "Your level ({$player->level}) allows you to have <highlight>4<end> towers.";
		}
		$msg = $this->text->blobWrap(
			$msg . " ",
			$this->text->makeBlob("See full list", $blob, "Towers by level")
		);
		$context->reply($msg);
	}

	/** Show the tower types by QL of the tower */
	#[NCA\HandlesCommand("nw types")]
	public function towerTypeCommand(
		CmdContext $context,
		#[NCA\Str("types", "towertype", "towertypes", "towers")]
		string $action,
	): void {
		$blob = "<header2>Tower types by QL<end>";
		$minQL = 1;
		$roman = ["I", "II", "III", "IV", "V", "VI", "VII"];
		$qls = static::TOWER_TYPE_QLS;
		$qls[301] = 8;
		foreach ($qls as $ql => $type) {
			$maxQL = $ql - 1;
			$blob .= "\n<tab>" . $this->text->alignNumber($minQL, 3).
				" - ".
				$this->text->alignNumber($maxQL, 3).
				": Type " . $roman[$type-2];
			$minQL = $ql;
		}
		$msg = $this->text->makeBlob("Tower types by QL", $blob);
		$context->reply($msg);
	}

	/** Run some tests to test the hot/cold/penalty logic */
	/*
		#[NCA\HandlesCommand("nw test")]
		public function towerTestCommand(
			CmdContext $context,
			#[NCA\Str("test", "tests")] string $action,
		): void {
			$blobs = [];
			$site = new FeedMessage\SiteUpdate(
				playfield_id: 585,
				site_id: 6,
				enabled: true,
				min_ql: 30,
				max_ql: 45,
				name: "Giant Green River Bank South",
				timing: FeedMessage\SiteUpdate::TIMING_US,
				center: new FeedMessage\Coordinates(x: 2100, y: 1340),
				num_conductors: 1,
				ct_pos: new FeedMessage\Coordinates(x: 2099, y: 1330),
				num_turrets: 10,
				gas: 25,
				org_id: 1329153,
				org_name: "Team Rainbow",
				plant_time: 1679782200 - 50 * 3600,
				ql: 45
			);
			// Case 1: Going hot too early
			$gasTest1 = new GasInfo(
				site: $site,
				lastAttack: null,
				time: 1679782194,
			);
			$blobs []= "<highlight>Going hot too early<end>".
				$gasTest1->dump();

			// Case 2: Going hot 2s too late
			$gasTest2 = new GasInfo(
				site: $site,
				lastAttack: null,
				time: 1679782202,
			);
			$blobs []= "<highlight>Going hot 2s too late<end>".
				$gasTest2->dump();

			$attack = new TowerAttack(
				playfield_id: 110,
				site_id: 1,
				ql: null,
				attacker: new FeedMessage\Attacker(
					name: "Nady",
					character_id: 1082663656,
					level: 220,
					ai_level: 30,
					profession: "Bureaucrat",
					org_rank: "Advisor",
					gender: "Female",
					breed: "Nano",
					faction: "Clan",
					org: new FeedMessage\AttackerOrg(
						name: "Team Rainbow",
						faction: "Clan",
						id: 1329153,
					),
				),
				location: new FeedMessage\Coordinates(100, 100),
				defender: new FeedMessage\DefenderOrg(
					name: "Troet",
					faction: "Neutral",
				),
				timestamp: 1679782200 - 3600*4 + 15,
				penalizing_ended: null,
			);

			// Case 3: Site 25% in penalty
			$gasTest3 = new GasInfo(
				site: $site,
				time: 1679782200 - 3600*3,
				lastAttack: $attack,
			);
			$blobs []= "<highlight>Site in 25% because of penalty<end>".
				$gasTest3->dump();

			// Case 4: Site 25% in penalty which needs to be prolonged
			$gasTest4 = new GasInfo(
				site: $site,
				time: 1679782200 - 15,
				lastAttack: $attack,
			);
			$blobs []= "<highlight>Site in 25% and penalty needs to be prolonged<end>".
				$gasTest4->dump();

			$attack->timestamp -= 3600;
			$attack->penalizing_ended = $attack->timestamp + 3900;
			// Case 5: Site 25% but penalty not affecting the site anymore
			$gasTest5 = new GasInfo(
				site: $site,
				time: 1679782200 + 14,
				lastAttack: $attack,
			);
			$blobs []= "<highlight>Site in 25%, but penalty not affecting site anymore<end>".
				$gasTest5->dump();

			$site->gas = 75;
			// Case 6: Site at 75%, all in time
			$gasTest6 = new GasInfo(
				site: $site,
				time: 1679782200 + 8*3600,
				lastAttack: null,
			);
			$blobs []= "<highlight>Site at 75%, all in time<end>".
				$gasTest6->dump();

			// Case 7: Site at 75%, too early
			$gasTest7 = new GasInfo(
				site: $site,
				time: 1679782200 + 6*3600-1000,
				lastAttack: null,
			);
			$blobs []= "<highlight>Site at 75%, too early<end>".
				$gasTest7->dump();

			$msg = $this->text->makeBlob("Test results", join("\n\n", $blobs));
			$context->reply($msg);
		}
	*/

	/** Render a bunch of sites, all hot, for the !hot-command */
	public function renderHotSites(?int $time, FeedMessage\SiteUpdate ...$sites): string {
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
		$blob = $grouped->map(function (Collection $sites, string $short) use ($time): string {
			return "<pagebreak><header2>{$short}<end>\n".
				$sites->map(function (FeedMessage\SiteUpdate $site) use ($time): string {
					return $this->renderHotSite($site, $time);
				})->join("\n");
		})->join("\n\n");
		return $blob;
	}

	/**
	 * If gas goes to 75%, all attacks from the org who owns that
	 * site are now definitely over
	 */
	private function unpenalizeAttacksFromSiteOwner(FeedMessage\SiteUpdate $site): void {
		if ($site->gas !== 75 || !isset($site->org_id, $site->org_name)) {
			return;
		}
		(new Collection($this->attacks))
			->whereNull("penalizing_ended")
			->where("att_org_id", $site->org_id)
			->each(function (FeedMessage\TowerAttack &$attack): void {
				$pf = $this->pfCtrl->getPlayfieldById($attack->playfield_id);
				assert(isset($pf));
				$this->logger->notice("Unpenalizing attack {from} -> {site}", [
					"from" => $attack->attacker->org?->name,
					"site" => "{$pf->short_name} {$attack->site_id}",
				]);
				$attack->penalizing_ended = time();
			});
		$this->db->table(self::DB_ATTACKS)
			->whereNull("penalizing_ended")
			->where("att_org_id", $site->org_id)
			->update(["penalizing_ended" => time()]);
	}

	/** Get a full overview, how many towers you can have at which level */
	private function getAllTowerQuantitiesBlob(): string {
		return "<header2>Number of towers by level<end>\n".
			"<tab>Level <black>00<end>1 - <black>0<end>14: <highlight>0<end> towers\n".
			"<tab>Level <black>0<end>15 - <black>0<end>74: <highlight>1<end> tower\n".
			"<tab>Level <black>0<end>75 - 149: <highlight>2<end> towers\n".
			"<tab>Level 150 - 199: <highlight>3<end> towers\n".
			"<tab>Level 200 - 220: <highlight>4<end> towers\n";
	}

	/** @return string[] */
	private function getUnplantedSites(): array {
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
		return $unplantedSites;
	}

	/** Count how many attacks on a field occurred in the recent past */
	private function countRecentAttacks(FeedMessage\SiteUpdate $site): int {
		$query = $this->db->table(self::DB_ATTACKS)
			->where("playfield_id", $site->playfield_id)
			->where("site_id", $site->site_id);
		if ($this->mostRecentAttacksAge > 0) {
			$query =$query
				->where("timestamp", ">", time() - $this->mostRecentAttacksAge);
		}
		return $query->count();
	}

	/** Count how many victories/abandonments on a field occurred in the recent past */
	private function countRecentOutcomes(FeedMessage\SiteUpdate $site): int {
		$query = $this->db->table(self::DB_OUTCOMES)
			->where("playfield_id", $site->playfield_id)
			->where("site_id", $site->site_id);
		if ($this->mostRecentOutcomesAge > 0) {
			$query =$query
				->where("timestamp", ">", time() - $this->mostRecentOutcomesAge);
		}
		return $query->count();
	}

	/** Handle whatever is necessary when a site gets newly planted */
	private function handleSitePlanted(FeedMessage\SiteUpdate $site, Playfield $pf): void {
		// Remove any plant timers for this site
		$timerName = $this->getPlantTimerName($site, $pf);
		$this->timerController->remove($timerName);
		// Send "WW 6 @ QL 112 planted by Orgname [details]"-message
		$tokens = array_merge(
			$site->getTokens(),
			$pf->getTokens(),
			[
				'site-short' => "{$pf->short_name} {$site->site_id}",
				'c-site-short' => isset($site->org_faction)
					? "<" . strtolower($site->org_faction) . ">{$pf->short_name} {$site->site_id}<end>"
					: "<highlight>{$pf->short_name} {$site->site_id}<end>",
			]
		);
		$tokens['details'] = ((array)$this->text->makeBlob(
			"details",
			$this->renderSite($site, $pf),
			"{$pf->short_name} {$site->site_id} ({$site->name})",
		))[0];
		$color = strtolower($site->org_faction ?? "neutral");
		$msg = $this->text->renderPlaceholders($this->sitePlantedFormat, $tokens);
		$rMessage = new RoutableMessage($msg);
		$rMessage->prependPath(new Source("pvp", "site-planted-{$color}"));
		$this->msgHub->handle($rMessage);
		$this->siteTracker->fireEvent(new RoutableMessage($msg), $site, 'site-planted');
	}

	/** Handle whatever is necessary when a site gets destroyed */
	private function handleSiteDestroyed(FeedMessage\SiteUpdate $oldSite, FeedMessage\SiteUpdate $site, Playfield $pf): void {
		// If automatic plan timers are enabled,
		// remove old one and set a new one for this site
		if ($this->autoPlantTimer) {
			$timer = $this->getPlantTimer($site, time() + 20 * 60);

			$this->timerController->remove($timer->name);

			$this->timerController->add(
				$timer->name,
				$this->config->main->character,
				$timer->mode,
				$timer->alerts,
				'timercontroller.timerCallback'
			);
		}
		// Send "WW 6 @ QL 112 destroyed [details]"-message
		$tokens = array_merge(
			$oldSite->getTokens(),
			$pf->getTokens(),
			[
				'site-short' => "{$pf->short_name} {$site->site_id}",
				'c-site-short' => isset($oldSite->org_faction)
					? "<" . strtolower($oldSite->org_faction) . ">{$pf->short_name} {$site->site_id}<end>"
					: "<highlight>{$pf->short_name} {$site->site_id}<end>",
			]
		);
		$tokens['details'] = ((array)$this->text->makeBlob(
			"details",
			$this->renderSite($site, $pf),
			"{$pf->short_name} {$site->site_id} ({$site->name})",
		))[0];
		$color = strtolower($oldSite->org_faction ?? "neutral");
		$msg = $this->text->renderPlaceholders($this->siteDestroyedFormat, $tokens);
		$rMessage = new RoutableMessage($msg);
		$rMessage->prependPath(new Source("pvp", "site-destroyed-{$color}"));
		$this->msgHub->handle($rMessage);
		$this->siteTracker->fireEvent(new RoutableMessage($msg), $site, 'site-destroyed');
	}

	/** Handle whatever is necessary when a site gets or loses a non-CT-tower */
	private function handleSiteTowerChange(FeedMessage\SiteUpdate $oldSite, FeedMessage\SiteUpdate $site, Playfield $pf): void {
		$tokens = array_merge(
			$site->getTokens(),
			$pf->getTokens(),
			[
				'site-short' => "{$pf->short_name} {$site->site_id}",
				'c-site-short' => isset($site->org_faction)
					? "<" . strtolower($site->org_faction) . ">{$pf->short_name} {$site->site_id}<end>"
					: "<highlight>{$pf->short_name} {$site->site_id}<end>",
			]
		);
		$tokens['details'] = ((array)$this->text->makeBlob(
			"details",
			$this->renderSite($site, $pf),
			"{$pf->short_name} {$site->site_id} ({$site->name})",
		))[0];
		$tokens['c-site-num-turrets'] = $site->num_turrets . " ".
			$this->text->pluralize('turret', $site->num_turrets);
		$tokens['c-site-num-conductors'] = $site->num_conductors . " ".
			$this->text->pluralize('conductor', $site->num_conductors);
		if ($oldSite->num_conductors < $site->num_conductors) {
			$tokens['tower-delta'] = '+1';
			$tokens['c-tower-delta'] = '<green>+1<end>';
			$tokens['tower-action'] = 'plant';
			$tokens['tower-type'] = 'conductor';
			$tokens['c-site-num-conductors'] = '<green>' . $tokens['c-site-num-conductors'] . '<end>';
		} elseif ($oldSite->num_turrets < $site->num_turrets) {
			$tokens['tower-delta'] = '+1';
			$tokens['c-tower-delta'] = '<green>+1<end>';
			$tokens['tower-action'] = 'plant';
			$tokens['tower-type'] = 'turret';
			$tokens['c-site-num-turrets'] = '<green>' . $tokens['c-site-num-turrets'] . '<end>';
		} elseif ($oldSite->num_conductors > $site->num_conductors) {
			$tokens['tower-delta'] = '-1';
			$tokens['c-tower-delta'] = '<red>-1<end>';
			$tokens['tower-action'] = 'destroy';
			$tokens['tower-type'] = 'conductor';
			$tokens['c-site-num-conductors'] = '<red>' . $tokens['c-site-num-conductors'] . '<end>';
		} elseif ($oldSite->num_turrets > $site->num_turrets) {
			$tokens['tower-delta'] = '-1';
			$tokens['c-tower-delta'] = '<red>-1<end>';
			$tokens['tower-action'] = 'destroy';
			$tokens['tower-type'] = 'turret';
			$tokens['c-site-num-turrets'] = '<red>' . $tokens['c-site-num-turrets'] . '<end>';
		} else {
			return;
		}

		$subType = $tokens['tower-action'] . 'ed';
		// Send "WW 6 conductors ±1 [details]"-message
		$color = strtolower($site->org_faction ?? "neutral");
		$msg = $this->text->renderPlaceholders($this->siteTowerChangeFormat, $tokens);
		$rMessage = new RoutableMessage($msg);
		$rMessage->prependPath(new Source("pvp", "tower-{$subType}-{$color}"));
		$this->msgHub->handle($rMessage);
		$this->siteTracker->fireEvent(new RoutableMessage($msg), $site, "tower-{$subType}");
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

		$siteDetails = $this->renderSite($site, $pf, false, false);
		$siteShort = "{$pf->short_name} {$site->site_id}";
		$siteLink = ((array)$this->text->makeBlob(
			$siteShort,
			$siteDetails,
			"{$siteShort} ({$site->name})",
		))[0];
		$alert = new Alert();
		$alert->time = time();
		$duration = $this->util->unixtimeToReadable($timestamp - time());
		$alert->message = "Started {$duration} countdown for planting {$siteLink}";
		$alerts []= $alert;

		if ($timestamp - 60 > time()) {
			$alert = new Alert();
			$alert->time = $timestamp - 60;
			$alert->message = "<highlight>1 minute<end> remaining to plant {$siteLink}";
			$alerts []= $alert;
		}

		$countdown = [3, 2, 1];
		foreach ($countdown as $remaining) {
			$alert = new Alert();
			$alert->time = $timestamp - $remaining;
			$alert->message = "Plant {$siteShort} in <highlight>{$remaining}s<end>";
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
					return $this->renderSite($site, $pf, false);
				})->join("\n\n");
		})->join("\n");
		return trim($blob);
	}

	/** Render the line of a single site for the !hot-command */
	private function renderHotSite(FeedMessage\SiteUpdate $site, ?int $time=null): string {
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
		$gas = $this->getSiteGasInfo($site, $time);
		assert(isset($gas));
		if (isset($time)) {
			$currentGas = $gas->gasAt($time);
		} else {
			$currentGas = $gas->currentGas();
		}
		assert(isset($currentGas));
		$line .= " " . $currentGas->colored();
		if (isset($site->ct_pos)) {
			$numTowers = $site->num_conductors + $site->num_turrets + 1;
			$line .= ", {$numTowers} " . $this->text->pluralize("tower", $numTowers);
		}
		$goesHot = $gas->goesHot($time);
		$goesCold = $gas->goesCold($time);
		$note = "";
		if ($gas->inPenalty()) {
			$note = ' or later';
		}
		if (isset($goesHot)) {
			if (!isset($time)) {
				$line .= ", opens in " . $this->util->unixtimeToReadable($goesHot - time());
			} elseif ($time > $goesHot) {
				$line .= ", opens in " . $this->util->unixtimeToReadable($goesHot - $time);
			}
		} elseif (isset($goesCold)) {
			if (!isset($time)) {
				$goesColdText = ($goesCold <= time())
					? "any time now"
					: "in " . $this->util->unixtimeToReadable($goesCold - time());
				$line .= ", closes {$goesColdText}{$note}";
			} elseif ($goesCold && $goesCold > $time) {
				$goesColdText = "in " . $this->util->unixtimeToReadable($goesCold - $time);
				$line .= ", closes {$goesColdText}{$note}";
			}
		}
		return $line;
	}

	/** Get the last attack that was made from the org owning this site */
	private function getLastAttackFrom(FeedMessage\SiteUpdate $site): ?FeedMessage\TowerAttack {
		if (!isset($site->ct_pos)) {
			return null;
		}

		/** @var ?FeedMessage\TowerAttack */
		$lastAttack = null;
		foreach ($this->attacks as $attack) {
			if ($attack->timestamp < $site->plant_time) {
				continue;
			}
			if (!isset($attack->attacker->org)) {
				continue;
			}
			$orgDiffers = $attack->attacker->org->name !== $site->org_name;
			if (isset($attack->attacker->org->id, $site->org_id)) {
				$orgDiffers = $attack->attacker->org->id !== $site->org_id;
			}
			$factionDiffers = $attack->attacker->org->faction !== $site->org_faction;
			if ($orgDiffers || $factionDiffers) {
				continue;
			}
			if (!isset($lastAttack) || $lastAttack->timestamp < $attack->timestamp) {
				$lastAttack = $attack;
			}
		}
		return $lastAttack;
	}
}
