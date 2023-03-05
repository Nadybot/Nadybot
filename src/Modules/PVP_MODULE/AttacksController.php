<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use Nadybot\Core\ParamClass\{PNonGreedy, PTowerSite};
use Nadybot\Core\Routing\{RoutableMessage, Source};
use Nadybot\Core\{Attributes as NCA, CmdContext, ConfigFile, DB, LoggerWrapper, MessageHub, ModuleInstance, QueryBuilder, Text, Util};
use Nadybot\Modules\HELPBOT_MODULE\{Playfield, PlayfieldController};
use Nadybot\Modules\LEVEL_MODULE\LevelController;
use Nadybot\Modules\PVP_MODULE\Event\TowerAttackInfo;

#[
	NCA\Instance,
	NCA\EmitsMessages("pvp", "tower-attack"),
	NCA\EmitsMessages("pvp", "tower-attack-own"),
	NCA\EmitsMessages("pvp", "tower-outcome"),
	NCA\EmitsMessages("pvp", "tower-outcome-own"),
	NCA\DefineCommand(
		command: AttacksController::CMD_ATTACKS,
		description: "Show the last Tower Attack messages",
		accessLevel: "guest",
	),
	NCA\DefineCommand(
		command: AttacksController::CMD_OUTCOMES,
		description: "Show the last tower outcomes",
		accessLevel: "guest",
	),
]
class AttacksController extends ModuleInstance {
	public const CMD_ATTACKS = "nw show attacks";
	public const CMD_OUTCOMES = "nw victory";

	private const ATT_FMT_NORMAL = "{?att-org:{c-att-org}}{!att-org:{c-att-name}} attacked {c-def-org} ".
		"{?att-org:- {c-att-name} }{?c-att-level:({c-att-level}/{c-att-ai-level}, {att-gender} {att-breed} {c-att-profession}{?att-org-rank:, {att-org-rank}})}";
	private const VICTORY_FMT_NORMAL = "{c-winning-org} won against {c-losing-org} in <highlight>{pf-short} {site-id}<end>";
	private const ABANDONED_FMT_NORMAL = "{c-losing-org} abandoned <highlight>{pf-short} {site-id}<end>";

	#[NCA\Inject]
	public PlayfieldController $pfCtrl;

	#[NCA\Inject]
	public NotumWarsController $nwCtrl;

	#[NCA\Inject]
	public MessageHub $msgHub;

	#[NCA\Inject]
	public LevelController $lvlCtrl;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** Display format for tower attacks */
	#[NCA\Setting\Template(
		options: [
			self::ATT_FMT_NORMAL,
			"{?att-org:{c-att-org}}{!att-org:{c-att-name}} attacked {c-def-org}",
			"{?att-org:{c-att-org}}{!att-org:{c-att-name}} attacked {c-def-org} CT {c-site-ql}",
		],
		exampleValues: [
			'att-name' => 'Nady',
			'c-att-name' => '<highlight>Nady<end>',
			'att-level' => '220',
			'c-att-level' => '<highlight>220<end>',
			'att-ai-level' => '30',
			'c-att-ai-level' => '<green>30<end>',
			'att-profession' => 'Bureaucrat',
			'c-att-profession' => '<highlight>Bureaucrat<end>',
			'att-prof' => 'Bureaucrat',
			'c-att-prof' => '<highlight>Bureaucrat<end>',
			'att-short-prof' => 'Crat',
			'c-att-short-prof' => '<highlight>Crat<end>',
			'att-org' => 'Team Rainbow',
			// 'att-org' => null,
			'c-att-org' => '<clan>Team Rainbow<end>',
			// 'c-att-org' => null,
			'att-gender' => 'Female',
			'att-breed' => 'Nanomage',
			'c-att-breed' => '<highlight>Nanomage<end>',
			'att-org-rank' => 'Advisor',
			'c-att-org-rank' => '<highlight>Advisor<end>',
			'def-org' => 'Troet',
			'c-def-org' => '<neutral>Troet<end>',
			'whois' => '<highlight>"Nady"<end> (<highlight>220<end>/<green>30<end>, Female Nanomage <highlight>Bureaucrat<end>, <clan>Clan<end>, Advisor of <clan>Team Rainbow<end>)',
			"def-faction" => "Neutral",
			"c-def-faction" => "<neutral>Neutral<end>",
			"pf-short" => "WW",
			"pf-long" => "Wailing Wastes",
			'site-num' => '1',
			'site-ql' => '33',
			'c-site-ql' => '<highlight>33<end>',
			"att-coord-x" => '1700',
			"att-coord-y" => '3700',
		],
	)]
	public string $towerAttackFormat = self::ATT_FMT_NORMAL;

	/** Display format for tower victories */
	#[NCA\Setting\Template(
		options: [
			self::VICTORY_FMT_NORMAL,
		],
		exampleValues: [
			"winning-faction" => "Neutral",
			"c-winning-faction" => "<neutral>Neutral<end>",
			"winning-org" => "Troet",
			"c-winning-org" => "<neutral>Troet<end>",
			"losing-faction" => "Clan",
			"c-losing-faction" => "<clan>Clan<clan>",
			"losing-org" => "Team Rainbow",
			"c-losing-org" => "<clan>Team Rainbow<end>",
			"site-name" => "Dome Ore",
			"pf-short" => "AV",
			"pf-long" => "Avalon",
			"pf-id" => "505",
			"site-min-ql" => "61",
			"site-max-ql" => "82",
			"site-id" => "8",
		]
	)]
	public string $towerVictoryFormat = self::VICTORY_FMT_NORMAL;

	/** Display format for towers being abandoned */
	#[NCA\Setting\Template(
		options: [
			self::ABANDONED_FMT_NORMAL,
			"{c-losing-org} abandoned their field at <highlight>{pf-short} {site-id}<end>",
		],
		exampleValues: [
			"losing-faction" => "Clan",
			"c-losing-faction" => "<clan>Clan<clan>",
			"losing-org" => "Team Rainbow",
			"c-losing-org" => "<clan>Team Rainbow<end>",
			"site-name" => "Dome Ore",
			"pf-short" => "AV",
			"pf-long" => "Avalon",
			"pf-id" => "505",
			"site-min-ql" => "61",
			"site-max-ql" => "82",
			"site-id" => "8",
		]
	)]
	public string $siteAbandonedFormat = self::ABANDONED_FMT_NORMAL;

	#[NCA\Event("tower-attack-info", "Announce tower attacks")]
	public function announceTowerAttack(TowerAttackInfo $event): void {
		if ($event->site === null) {
			$this->logger->error("ERROR! Could not find closest site for attack");
			return;
		}
		$site = $event->site;
		$pf = $this->pfCtrl->getPlayfieldById($site->playfield_id);
		if (!isset($pf)) {
			return;
		}
		$this->logger->info("Site being attacked: {$pf->short_name} {$site->site_id}");


		$details = $this->renderAttackInfo($event, $pf);
		$shortSite = "{$pf->short_name} {$site->site_id}";
		$detailsLink = $this->text->makeBlob(
			$shortSite,
			$details,
			"Attack on {$shortSite}",
		);
		$defFaction = strtolower($event->attack->defending_faction);
		$tokens = $event->attacker?->getTokens("att-") ?? [
			"att-name" => $event->attack->attacker,
			"c-att-name" => "<highlight>{$event->attack->attacker}<end>",
			"att-faction" => $event->attack->attacking_faction,
			"c-att-faction" => isset($event->attack->attacking_faction)
				? "<" . strtolower($event->attack->attacking_faction) . ">".
				  $event->attack->attacking_faction . "<end>"
				: null,
			"att-org" => $event->attack->attacking_org,
			"c-att-org" => isset($event->attack->attacking_org)
				? "<" . strtolower($event->attack->attacking_faction??"unknown") . ">".
				  $event->attack->attacking_org . "<end>"
				: null,
		];
		$tokens = array_merge(
			$tokens,
			[
				"def-org" => $event->attack->defending_org,
				"c-def-org" => "<{$defFaction}>{$event->attack->defending_org}<end>",
				"def-faction" => ucfirst($defFaction),
				"c-def-faction" => "<{$defFaction}>" . ucfirst($defFaction) . "<end>",
				"pf-short" => $pf->short_name,
				"pf-long" => $pf->long_name,
				"att-coord-x" => $event->attack->location->x,
				"att-coord-y" => $event->attack->location->y,
				"site-ql" => $event->site->ql,
				"site-num" => $event->site->site_id,
			]
		);
		if (!isset($event->attack->attacking_org)) {
			if (isset($event->attacker, $tokens['att-name'])) {
				$tokens["c-att-name"] = "<" . strtolower($event->attacker->faction) . ">".
					$tokens['att-name'] . "<end>";
			} elseif (isset($tokens['att-name'])) {
				$tokens["c-att-name"] = "<unknown>{$tokens['att-name']}<end>";
			}
		}
		$msg = $this->text->renderPlaceholders(
			$this->towerAttackFormat,
			$tokens
		);
		$msg = $this->text->blobWrap("{$msg} [", $detailsLink, "]");

		foreach ($msg as $page) {
			if (isset($site->org_id) && $this->config->orgId === $site->org_id) {
				$rMsg = new RoutableMessage($page);
				$rMsg->prependPath(new Source('pvp', "tower-attack-own"));
				$this->msgHub->handle($rMsg);
			}
			$rMsg = new RoutableMessage($page);
			$rMsg->prependPath(new Source('pvp', "tower-attack"));
			$this->msgHub->handle($rMsg);
		}
	}

	#[NCA\Event("tower-outcome", "Announce tower victories and abandoned sites")]
	public function announceTowerVictories(Event\TowerOutcome $event): void {
		$outcome = $event->outcome;
		$pf = $this->pfCtrl->getPlayfieldById($outcome->playfield_id);
		$site = $this->nwCtrl->state[$outcome->playfield_id][$outcome->site_id];
		if (!isset($pf) || !isset($site)) {
			return;
		}
		$tokens = [
			"winning-faction" => $outcome->attacking_faction,
			"winning-org" => $outcome->attacking_org,
			"losing-faction" => $outcome->losing_faction,
			"losing-org" => $outcome->losing_org,
			"c-losing-org" => "<" . strtolower($outcome->losing_faction) . ">".
				$outcome->losing_org . "<end>",
			"site-name" => $site->name,
			"pf-short" => $pf->short_name,
			"pf-long" => $pf->long_name,
			"pf-id" => $pf->id,
			"site-min-ql" => $site->min_ql,
			"site-max-ql" => $site->max_ql,
			"site-id" => $site->site_id,
		];
		$format = $this->siteAbandonedFormat;
		if (isset($tokens["winning-faction"])) {
			assert(isset($tokens['winning-org']));
			$winColor = strtolower($tokens['winning-faction']);
			$tokens['c-winning-faction'] = "<{$winColor}>{$tokens['winning-faction']}<end>";
			$tokens['c-winning-org'] = "<{$winColor}>{$tokens['winning-org']}<end>";
			$format = $this->towerVictoryFormat;
		}
		$msg = $this->text->renderPlaceholders($format, $tokens);

		$details = $this->nwCtrl->renderSite($site, $pf, false);
		$shortSite = "{$pf->short_name} {$site->site_id}";
		$detailsLink = $this->text->makeBlob(
			$shortSite,
			$details,
		);
		$msg = $this->text->blobWrap("{$msg} [", $detailsLink, "]");

		foreach ($msg as $page) {
			if (isset($site->org_id) && $this->config->orgId === $site->org_id) {
				$rMsg = new RoutableMessage($page);
				$rMsg->prependPath(new Source('pvp', "tower-outcome-own"));
				$this->msgHub->handle($rMsg);
			}
			$rMsg = new RoutableMessage($page);
			$rMsg->prependPath(new Source("pvp", "tower-outcome"));
			$this->msgHub->handle($rMsg);
		}
	}

	/** Show the last tower attack messages */
	#[NCA\HandlesCommand(self::CMD_ATTACKS)]
	public function nwAttacksAnywhereCommand(
		CmdContext $context,
		#[NCA\Str("attacks")] string $action,
		?int $page,
	): void {
		$query = $this->db->table($this->nwCtrl::DB_ATTACKS);
		$context->reply($this->nwAttacksCmd(
			$query,
			"Tower Attacks",
			"nw attacks",
			$page??1,
		));
	}

	/** Show the last tower attack messages for a site */
	#[NCA\HandlesCommand(self::CMD_ATTACKS)]
	public function nwAttacksForSiteCommand(
		CmdContext $context,
		#[NCA\Str("attacks")] string $action,
		PTowerSite $towerSite,
		?int $page,
	): void {
		$pf = $this->pfCtrl->getPlayfieldByName($towerSite->pf);
		if (!isset($pf)) {
			$msg = "Playfield <highlight>{$towerSite->pf}<end> could not be found.";
			$context->reply($msg);
			return;
		}
		$query = $this->db->table($this->nwCtrl::DB_ATTACKS)
			->where("playfield_id", $pf->id)
			->where("site_id", $towerSite->site);
		$context->reply($this->nwAttacksCmd(
			$query,
			"Tower Attacks on {$pf->short_name} {$towerSite->site}",
			"nw attacks",
			$page??1,
		));
	}

	/**
	 * Show the last tower attack messages involving a specific organization
	 *
	 * You can use '*' as a wildcard in org names
	 */
	#[NCA\HandlesCommand(self::CMD_ATTACKS)]
	#[NCA\Help\Example("<symbol>nw attacks org *sneak*")]
	#[NCA\Help\Example("<symbol>nw attacks org Komodo")]
	public function nwAttacksForOrgCommand(
		CmdContext $context,
		#[NCA\Str("attacks")] string $action,
		#[NCA\Str("org")] string $org,
		PNonGreedy $orgName,
		?int $page,
	): void {
		$search = str_replace("*", "%", $orgName());
		$query = $this->db->table($this->nwCtrl::DB_ATTACKS)
			->whereIlike("att_org", $search)
			->orWhereIlike("def_org", $search);
		$context->reply(
			$this->nwAttacksCmd(
				$query,
				"Tower Attacks on/by '{$orgName}'",
				"nw attacks org {$orgName}",
				$page??1
			)
		);
	}

	/**
	 * Show the last tower attack messages involving a given character
	 *
	 * You can use '%' as a wildcard in character names
	 */
	#[NCA\HandlesCommand(self::CMD_ATTACKS)]
	#[NCA\Help\Example("<symbol>nw attacks char nady%")]
	#[NCA\Help\Example("<symbol>nw attacks char nadyita")]
	public function nwAttacksForCharCommand(
		CmdContext $context,
		#[NCA\Str("attacks")] string $action,
		#[NCA\Str("char")] string $char,
		PNonGreedy $search,
		?int $page,
	): void {
		$query = $this->db->table($this->nwCtrl::DB_ATTACKS)
			->whereIlike("att_name", $search());
		$context->reply(
			$this->nwAttacksCmd(
				$query,
				"Tower Attacks by '{$search}'",
				"nw attacks char {$search}",
				$page??1
			)
		);
	}

	/** Show the last tower victories */
	#[NCA\HandlesCommand(self::CMD_OUTCOMES)]
	public function nwOutcomesAnywhereCommand(
		CmdContext $context,
		#[NCA\Str("victory")] string $action,
		?int $page,
	): void {
		$query = $this->db->table($this->nwCtrl::DB_OUTCOMES);
		$context->reply($this->nwOutcomesCmd(
			$query,
			"Tower Victories",
			"nw victory",
			$page??1,
		));
	}

	/** Show the last tower victories for a site */
	#[NCA\HandlesCommand(self::CMD_OUTCOMES)]
	public function nwOutcomesForSiteCommand(
		CmdContext $context,
		#[NCA\Str("victory")] string $action,
		PTowerSite $towerSite,
		?int $page,
	): void {
		$pf = $this->pfCtrl->getPlayfieldByName($towerSite->pf);
		if (!isset($pf)) {
			$msg = "Playfield <highlight>{$towerSite->pf}<end> could not be found.";
			$context->reply($msg);
			return;
		}
		$query = $this->db->table($this->nwCtrl::DB_OUTCOMES)
			->where("playfield_id", $pf->id)
			->where("site_id", $towerSite->site);
		$context->reply($this->nwOutcomesCmd(
			$query,
			"Tower Victories on {$pf->short_name} {$towerSite->site}",
			"nw victory",
			$page??1,
		));
	}

	/**
	 * Show the last tower victories involving a specific organization
	 *
	 * You can use '*' as a wildcard in org names
	 */
	#[NCA\HandlesCommand(self::CMD_ATTACKS)]
	#[NCA\Help\Example("<symbol>nw victory org *sneak*")]
	#[NCA\Help\Example("<symbol>nw victory org Komodo")]
	public function nwOutcomesForOrgCommand(
		CmdContext $context,
		#[NCA\Str("victory")] string $action,
		#[NCA\Str("org")] string $org,
		PNonGreedy $orgName,
		?int $page,
	): void {
		$search = str_replace("*", "%", $orgName());
		$query = $this->db->table($this->nwCtrl::DB_OUTCOMES)
			->whereIlike("attacking_org", $search)
			->orWhereIlike("losing_org", $search);
		$context->reply(
			$this->nwOutcomesCmd(
				$query,
				"Tower Attacks on/by '{$orgName}'",
				"nw attacks org {$orgName}",
				$page??1
			)
		);
	}

	private function renderAttackInfo(TowerAttackInfo $info, Playfield $pf): string {
		$attack = $info->attack;
		$whois = $info->attacker;
		$site = $info->site;
		assert(isset($site));
		$blob = "<header2>Attacker<end>\n";
		$blob .= "<tab>Name: <highlight>";
		if (!empty($whois->firstname)) {
			$blob .= $whois->firstname . " ";
		}
		if (!empty($whois->firstname) || !empty($whois->lastname)) {
			$blob .= '"' . $attack->attacker . '"';
		} else {
			$blob .= $attack->attacker;
		}
		if (!empty($whois->lastname)) {
			$blob .= " " . $whois->lastname;
		}
		$blob .= "<end>\n";

		if (isset($whois->breed) && strlen($whois->breed)) {
			$blob .= "<tab>Breed: <highlight>{$whois->breed}<end>\n";
		}
		if (isset($whois->gender) && strlen($whois->gender)) {
			$blob .= "<tab>Gender: <highlight>{$whois->gender}<end>\n";
		}

		if (isset($whois->profession) && strlen($whois->profession)) {
			$blob .= "<tab>Profession: <highlight>{$whois->profession}<end>\n";
		}
		if (isset($whois->level)) {
			$level_info = $this->lvlCtrl->getLevelInfo($whois->level);
			if (isset($level_info)) {
				$blob .= "<tab>Level: <highlight>{$whois->level}/<green>{$whois->ai_level}<end> ({$level_info->pvpMin}-{$level_info->pvpMax})<end>\n";
			}
		}

		$attFaction = $attack->attacking_faction ?? $whois?->faction ?? null;
		if (isset($attFaction)) {
			$blob .= "<tab>Alignment: <" . strtolower($attFaction) . ">{$attFaction}<end>\n";
		}

		if (isset($attack->attacking_org)) {
			$blob .= "<tab>Organization: <highlight>{$attack->attacking_org}<end>\n";
			if (isset($whois) && $attack->attacking_org === $whois->guild && isset($whois->guild_rank)) {
				$blob .= "<tab>Organization Rank: <highlight>{$whois->guild_rank}<end>\n";
			}
		}

		$blob .= "\n";

		$blob .= "<header2>Defender<end>\n";
		$blob .= "<tab>Organization: <highlight>{$attack->defending_org}<end>\n";
		$blob .= "<tab>Alignment: <" . strtolower($attack->defending_faction) . ">{$attack->defending_faction}<end>\n\n";

		$baseLink = $this->text->makeChatcmd("{$pf->short_name} {$site->site_id}", "/tell <myname> nw lc {$pf->short_name} {$site->site_id}");
		$attackWaypoint = $this->text->makeChatcmd(
			"{$attack->location->x}x{$attack->location->y}",
			"/waypoint {$attack->location->x} {$attack->location->y} {$pf->id}"
		);
		$blob .= "<header2>Waypoints<end>\n";
		$blob .= "<tab>Site: <highlight>{$baseLink} ({$site->min_ql}-{$site->max_ql})<end>\n";
		$blob .= "<tab>Attack: <highlight>{$pf->long_name} ({$attackWaypoint})<end>\n";

		return $blob;
	}

	private function renderDBOutcome(DBOutcome $outcome, FeedMessage\SiteUpdate $site, Playfield $pf): string {
		$blob = "Time: " . $this->util->date($outcome->timestamp) . " (".
			"<highlight>" . $this->util->unixtimeToReadable(time() - $outcome->timestamp).
			"<end> ago)\n";
		if (isset($outcome->attacking_org, $outcome->attacking_faction)) {
			$blob .= "Winner: <" . strtolower($outcome->attacking_faction) . ">".
				$outcome->attacking_org . "<end>\n";
		} else {
			$blob .= "Winner: <grey>abandoned<end>\n";
		}
		$blob .= "Loser: <" . strtolower($outcome->losing_faction) . ">".
			$outcome->losing_org . "<end>\n";
		$siteLink = $this->text->makeChatcmd(
			"{$pf->short_name} {$site->site_id}",
			"/tell <myname> <symbol>lc {$pf->short_name} {$site->site_id}"
		);
		$blob .= "Site: {$siteLink} (QL {$site->min_ql}-{$site->max_ql})";

		return $blob;
	}

	/** @return string[] */
	private function nwAttacksCmd(QueryBuilder $query, string $title, string $command, int $page): array {
		$numAttacks = $query->count();
		$attacks = $query
			->orderByDesc("timestamp")
			->limit(15)
			->offset(($page-1) * 15)
			->asObj(DBTowerAttack::class);
		return $this->renderAttackList(
			$command,
			$page,
			$numAttacks,
			$title,
			...$attacks->toArray()
		);
	}

	/** @return string[] */
	private function nwOutcomesCmd(QueryBuilder $query, string $title, string $command, int $page): array {
		$numOutcomes = $query->count();
		$attacks = $query
			->orderByDesc("timestamp")
			->limit(15)
			->offset(($page-1) * 15)
			->asObj(DBOutcome::class);
		return $this->renderOutcomeList(
			$command,
			$page,
			$numOutcomes,
			$title,
			...$attacks->toArray()
		);
	}

	/** @return string[] */
	private function renderAttackList(
		string $baseCommand,
		int $page,
		int $numAttacks,
		string $title,
		DBTowerAttack ...$attacks
	): array {
		if (empty($attacks)) {
			return ["No tower attacks found."];
		}
		$blocks = [];
		foreach ($attacks as $attack) {
			$blocks []= $this->renderDBAttack($attack);
		}
		$prevLink = "&lt;&lt;&lt;";
		if ($page > 1) {
			$prevLink = $this->text->makeChatcmd(
				$prevLink,
				"/tell <myname> {$baseCommand} " . ($page-1)
			);
		}
		$nextLink = "";
		if ($page * 15 < $numAttacks) {
			$nextLink = $this->text->makeChatcmd(
				"&gt;&gt;&gt;",
				"/tell <myname> {$baseCommand} " . ($page+1)
			);
		}
		$blob = "";
		if ($numAttacks > 15) {
			$blob = "{$prevLink}<tab>Page {$page}<tab>{$nextLink}\n\n";
		}
		$blob .= join("\n", $blocks);
		$msg = $this->text->makeBlob(
			$title,
			$blob
		);
		return (array)$msg;
	}

	/** @return string[] */
	private function renderOutcomeList(
		string $baseCommand,
		int $page,
		int $numAttacks,
		string $title,
		DBOutcome ...$outcomes
	): array {
		if (empty($outcomes)) {
			return ["No tower victories found."];
		}
		$blocks = [];
		foreach ($outcomes as $outcome) {
			$pf = $this->pfCtrl->getPlayfieldById($outcome->playfield_id);
			$site = $this->nwCtrl->state[$outcome->playfield_id][$outcome->site_id] ?? null;
			if (!isset($pf) || !isset($site)) {
				continue;
			}
			$blocks []= $this->renderDBOutcome($outcome, $site, $pf);
		}
		$prevLink = "&lt;&lt;&lt;";
		if ($page > 1) {
			$prevLink = $this->text->makeChatcmd(
				$prevLink,
				"/tell <myname> {$baseCommand} " . ($page-1)
			);
		}
		$nextLink = "";
		if ($page * 15 < $numAttacks) {
			$nextLink = $this->text->makeChatcmd(
				"&gt;&gt;&gt;",
				"/tell <myname> {$baseCommand} " . ($page+1)
			);
		}
		$blob = "";
		if ($numAttacks > 15) {
			$blob = "{$prevLink}<tab>Page {$page}<tab>{$nextLink}\n\n";
		}
		$blob .= join("\n\n", $blocks);
		$msg = $this->text->makeBlob(
			$title,
			$blob
		);
		return (array)$msg;
	}

	private function renderDBAttack(DBTowerAttack $attack): string {
		$defColor = strtolower($attack->def_faction);
		$attColor = strtolower($attack->att_faction ?? "Neutral");
		$blob = "Time: " . $this->util->date($attack->timestamp).
			" (<highlight>".
			$this->util->unixtimeToReadable(time() - $attack->timestamp).
			"<end> ago)\n";
		$blob .= "Attacker: <{$attColor}>{$attack->att_name}<end>";
		if (isset($attack->att_level, $attack->att_ai_level, $attack->att_profession)) {
			$blob .= " ({$attack->att_level}/<green>{$attack->att_ai_level}<end> ".
				"{$attack->att_profession}";
			if (isset($attack->att_org)) {
				$blob .= " of <{$attColor}>{$attack->att_org}<end>)";
			} else {
				$blob .= ")";
			}
		} elseif (isset($attack->att_org)) {
			$blob .= " <{$attColor}>{$attack->att_org}<end>";
		}
		$blob .= "\n";
		$blob .= "Defender: <{$defColor}>{$attack->def_org}<end>\n";
		$site = $this->nwCtrl->state[$attack->playfield_id][$attack->site_id] ?? null;
		$pf = $this->pfCtrl->getPlayfieldById($attack->playfield_id);
		if (isset($site, $pf)) {
			$blob .= "Site: " . $this->text->makeChatcmd(
				"{$pf->short_name} {$attack->site_id}",
				"/tell <myname> nw lc {$pf->short_name} {$attack->site_id}"
			) . " (QL {$site->min_ql}-{$site->max_ql})\n";
		}
		return $blob;
	}
}
