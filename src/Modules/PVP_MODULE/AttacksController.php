<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;
use Nadybot\Core\{
	Attributes as NCA,
	Attributes\Parameter\NonGreedy,
	Attributes\Parameter\Str,
	CmdContext,
	Config\BotConfig,
	DB,
	DBSchema\Player,
	Events\OrgMsgChannelMsgEvent,
	MessageHub,
	ModuleInstance,
	ParamClass\PDuration,
	ParamClass\PTowerSite,
	QueryBuilder,
	Routing\RoutableMessage,
	Routing\Source,
	Safe,
	Text,
	Types\Faction,
	Types\Playfield,
	Util
};
use Nadybot\Modules\{
	LEVEL_MODULE\LevelController,
	PVP_MODULE\Event\TowerAttackInfoEvent,
	PVP_MODULE\FeedMessage\SiteUpdate,
	PVP_MODULE\FeedMessage\TowerAttack,
	PVP_MODULE\FeedMessage\TowerOutcome
};
use Psr\Log\LoggerInterface;

use Throwable;

#[
	NCA\Instance,
	NCA\EmitsMessages('pvp', 'tower-hit-own'),
	NCA\EmitsMessages('pvp', 'tower-shield-own'),
	NCA\EmitsMessages('pvp', 'tower-attack'),
	NCA\EmitsMessages('pvp', 'tower-attack-own'),
	NCA\EmitsMessages('pvp', 'tower-outcome'),
	NCA\EmitsMessages('pvp', 'tower-outcome-own'),
	NCA\DefineCommand(
		command: AttacksController::CMD_ATTACKS,
		alias: 'attacks',
		description: 'Show the last Tower Attack messages',
		accessLevel: 'guest',
	),
	NCA\DefineCommand(
		command: AttacksController::CMD_OUTCOMES,
		alias: 'outcomes',
		description: 'Show the last tower outcomes',
		accessLevel: 'guest',
	),
	NCA\DefineCommand(
		command: AttacksController::CMD_STATS,
		alias: 'towerstats',
		description: 'Show how many towers each faction has lost',
		accessLevel: 'guest',
	),
]
class AttacksController extends ModuleInstance {
	public const CMD_ATTACKS = 'nw attacks';
	public const CMD_OUTCOMES = 'nw victory';
	public const CMD_STATS = 'nw stats';

	private const ATT_FMT_NORMAL = '{?att-org:{c-att-org}}{!att-org:{c-att-name}} attacked {c-def-org} '.
		'{?att-org:- {c-att-name} }{?att-level:({c-att-level}/{c-att-ai-level},{?att-gender: {att-gender} {att-breed}} {c-att-profession}{?att-org-rank:, {att-org-rank}})}';
	private const VICTORY_FMT_NORMAL = '{c-winning-org} won against {c-losing-org} in <highlight>{pf-short} {site-id}<end>';
	private const ABANDONED_FMT_NORMAL = '{c-losing-org} abandoned <highlight>{pf-short} {site-id}<end>';

	/** Display format for tower attacks */
	#[NCA\Setting\Template(
		options: [
			self::ATT_FMT_NORMAL,
			'{?att-org:{c-att-org}}{!att-org:{c-att-name}} attacked {c-def-org}',
			'{?att-org:{c-att-org}}{!att-org:{c-att-name}} attacked {c-def-org} CT <highlight>{site-ct-ql}<end>',
		],
		exampleValues: [
			...TowerAttack::EXAMPLE_TOKENS,
			...SiteUpdate::EXAMPLE_TOKENS,
			...Playfield::EXAMPLE_TOKENS,
		],
	)]
	public string $towerAttackFormat = self::ATT_FMT_NORMAL;

	/** Display format for tower victories */
	#[NCA\Setting\Template(
		options: [
			self::VICTORY_FMT_NORMAL,
		],
		exampleValues: [
			...TowerOutcome::EXAMPLE_TOKENS,
			...SiteUpdate::EXAMPLE_TOKENS,
			...Playfield::EXAMPLE_TOKENS,
		],
		help: 'tower_victory_format.txt',
	)]
	public string $towerVictoryFormat = self::VICTORY_FMT_NORMAL;

	/** Display format for towers being abandoned */
	#[NCA\Setting\Template(
		options: [
			self::ABANDONED_FMT_NORMAL,
			'{c-losing-org} abandoned their field at <highlight>{pf-short} {site-id}<end>',
		],
		exampleValues: [
			...TowerOutcome::EXAMPLE_ABANDON_TOKENS,
			...SiteUpdate::EXAMPLE_TOKENS,
			...Playfield::EXAMPLE_TOKENS,
		],
		help: 'site_abandoned_format.txt',
	)]
	public string $siteAbandonedFormat = self::ABANDONED_FMT_NORMAL;

	/** Display format when one of our org's towers is being hit */
	#[NCA\Setting\Template(
		options: [
			'{att-whois} reduced the {tower-type} health to {tower-health}%'.
				' in {site-details}',
			'{att-whois} reduced the {tower-type} health to {tower-health}%'.
				'{?pf-id: in {pf-short}{?site-id: {site-id}}}',
			'{?att-faction:<{att-faction}>{att-name}<end>}'.
				'{!att-faction:<highlight>{c-att-name}<end>} '.
				'reduced the <highlight>{tower-type}<end> health to '.
				'<highlight>{tower-health}%<end> '.
				'in {site-details}',
			'{?att-faction:<{att-faction}>{att-name}<end>}'.
				'{!att-faction:<highlight>{c-att-name}<end>} '.
				'reduced the <highlight>{tower-type}<end> health to '.
				'<highlight>{tower-health}%<end>'.
				'{?pf-id: in {pf-short}{?site-id: {site-id}}}',
			'{tower-type} health reduced to <highlight>{tower-health}%<end> '.
				'in {site-details}',
			'{tower-type} health reduced to <highlight>{tower-health}%<end>'.
				'{?pf-id: in {pf-short}{?site-id: {site-id}}}',
		],
		exampleValues: [
			'tower-health' => '75',
			'tower-type' => 'Control Tower - Neutral',
			'site-details' => "<a href='itemref://301560/301560/30'>WW 6</a>",
			'att-name' => 'Nady',
			'c-att-name' => '<highlight>Nady<end>',
			'att-first-name' => null,
			'att-last-name' => null,
			'att-level' => 220,
			'c-att-level' => '<highlight>220<end>',
			'att-ai-level' => 30,
			'c-att-ai-level' => '<green>30<end>',
			'att-prof' => 'Bureaucrat',
			'c-att-prof' => '<highlight>Bureaucrat<end>',
			'att-profession' => 'Bureaucrat',
			'c-att-profession' => '<highlight>Bureaucrat<end>',
			'att-org' => 'Team Rainbow',
			'c-att-org' => '<clan>Team Rainbow<end>',
			'att-org-rank' => 'Advisor',
			'att-breed' => 'Nano',
			'c-att-breed' => '<highlight>Nano<end>',
			'att-faction' => 'Clan',
			'c-att-faction' => '<clan>Clan<end>',
			'att-gender' => 'Female',
			'att-whois' => '"Nady" (<highlight>220<end>/<green>30<end>, '.
				'Female Nano <highlight>Bureaucrat<end>, '.
				'<clan>Clan<end>, Advisor of <clan>Team Rainbow<end>)',
			'att-short-prof' => 'Crat',
			'c-att-short-prof' => '<highlight>Crat<end>',
			...Playfield::EXAMPLE_TOKENS,
			...SiteUpdate::EXAMPLE_TOKENS,
		],
		help: 'own_tower_hit_format.txt',
	)]
	public string $ownTowerHitFormat = '{tower-type} health reduced to <highlight>{tower-health}%<end> in {site-details}';

	/** Display format when the defense shield on one of our sites is disabled */
	#[NCA\Setting\Template(
		options: [
			'{att-name} ({c-att-org}) disabled the defense shield in {site-details}',
			'{att-name} ({?att-level:{c-att-level}/{c-att-ai-level} {c-att-short-prof}, }{c-att-org}) disabled the defense shield in {site-details}',
			'{att-whois} disabled the defense shield in {site-details}',
		],
		exampleValues: [
			'tower-type' => 'Control Tower - Neutral',
			'site-details' => "<a href='itemref://301560/301560/30'>WW 6</a>",
			'att-name' => 'Nady',
			'c-att-name' => '<highlight>Nady<end>',
			'att-first-name' => null,
			'att-last-name' => null,
			'att-level' => 220,
			'c-att-level' => '<highlight>220<end>',
			'att-ai-level' => 30,
			'c-att-ai-level' => '<green>30<end>',
			'att-prof' => 'Bureaucrat',
			'c-att-prof' => '<highlight>Bureaucrat<end>',
			'att-profession' => 'Bureaucrat',
			'c-att-profession' => '<highlight>Bureaucrat<end>',
			'att-org' => 'Team Rainbow',
			'c-att-org' => '<clan>Team Rainbow<end>',
			'att-org-rank' => 'Advisor',
			'att-breed' => 'Nano',
			'c-att-breed' => '<highlight>Nano<end>',
			'att-faction' => 'Clan',
			'c-att-faction' => '<clan>Clan<end>',
			'att-gender' => 'Female',
			'att-whois' => '"Nady" (<highlight>220<end>/<green>30<end>, '.
				'Female Nano <highlight>Bureaucrat<end>, '.
				'<clan>Clan<end>, Advisor of <clan>Team Rainbow<end>)',
			'att-short-prof' => 'Crat',
			'c-att-short-prof' => '<highlight>Crat<end>',
			...Playfield::EXAMPLE_TOKENS,
			...SiteUpdate::EXAMPLE_TOKENS,
		],
		help: 'own_shield_disabled_format.txt',
	)]
	public string $ownShieldDisabledFormat = '{att-name} ({?att-level:{c-att-level}/{c-att-ai-level} {c-att-short-prof}, }{c-att-org}) disabled the defense shield in {site-details}';

	/** Group tower attacks by site, owner and hot-phase */
	#[NCA\Setting\Boolean]
	public bool $groupTowerAttacks = true;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private SiteTrackerController $siteTracker;

	#[NCA\Inject]
	private NotumWarsController $nwCtrl;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private MessageHub $msgHub;

	#[NCA\Inject]
	private LevelController $lvlCtrl;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private DB $db;

	/** Notify if org's tower site defense shield is disabled via pvp(tower-shield-own) */
	#[NCA\HandlesEvent]
	public function shieldLoweredMessageEvent(OrgMsgChannelMsgEvent $eventObj): void {
		if (isset($eventObj->sender)) {
			return;
		}
		if (
			!count($matches = Safe::pregMatch(
				'/^Your (?<tower>.+?) tower in (?<site_name>.+?) in (?<playfield>.+?) has had its '.
				"defense shield disabled by (?<att_name>[^ ]+) \((?<att_faction>.+?)\)\.\s*".
				"The attacker is a member of the organization (?<att_org>.+?)\.$/",
				$eventObj->message,
			))
		) {
			return;
		}
		try {
			$pf = Playfield::fromName($matches['playfield']);
		} catch (Throwable) {
			return;
		}

		/** @var ?FeedMessage\SiteUpdate */
		$site = ($this->nwCtrl->getEnabledSites())
			->where('playfield_id', $pf->value)
			->where('name', $matches['site_name'])
			->first();

		$whois = $this->playerManager->byName($matches['att_name']);
		if ($whois === null) {
			$whois = new Player(
				name: $matches['att_name'],
				charid: 0,
				dimension: $this->config->main->dimension,
				faction: Faction::from(ucfirst(strtolower($matches['att_faction']))),
				guild: $matches['att_org'],
			);
		} else {
			$whois->faction = Faction::from(ucfirst(strtolower($matches['att_faction'])));
			$whois->guild = $matches['att_org'];
		}
		$siteName = $pf->short();
		if (isset($site)) {
			$siteName .= " {$site->site_id}";
			$siteName = Text::makeBlob(
				$siteName,
				$this->nwCtrl->renderSite($site, false, false, null),
			);
		}
		$tokens = array_merge(
			$whois->getTokens('att-'),
			[
				'tower-type' => $matches['tower'],
				'att-name' => $matches['att_name'],
				'c-att-name' => '<highlight>' . $matches['att_name'] . '<end>',
				'att-faction' => ucfirst(strtolower($matches['att_faction'])),
				'c-att-faction' => '<' . strtolower($matches['att_faction']) . '>'.
					ucfirst(strtolower($matches['att_faction'])) . '<end>',
				'att-org' => $matches['att_org'],
				'c-att-org' => '<' . strtolower($matches['att_faction']) . '>'.
					$matches['att_org'] . '<end>',
				'site-details' => $siteName,
			],
			$site?->getTokens() ?? [],
			$pf->getTokens(),
		);
		$msg = Text::renderPlaceholders(
			$this->ownShieldDisabledFormat,
			$tokens
		);
		$rMsg = new RoutableMessage($msg);
		$rMsg->appendPath(new Source('pvp', 'tower-shield-own'));
		$this->msgHub->handle($rMsg);
	}

	/** Notify if org's towers are attacked via pvp(tower-hit-own) */
	#[NCA\HandlesEvent]
	public function attackOwnOrgMessageEvent(OrgMsgChannelMsgEvent $eventObj): void {
		if (isset($eventObj->sender)) {
			return;
		}
		if (
			!count($matches = Safe::pregMatch(
				"/^The tower (?<tower>.+?) in (?<playfield>.+?) was just reduced to (?<health>\d+) % health ".
				'by (?<att_name>[^ ]+) from the (?<att_org>.+?) organization!$/',
				$eventObj->message,
			))
			&& !count($matches = Safe::pregMatch(
				"/^The tower (?<toker>.+?) in (?<playfield>.+?) was just reduced to (?<health>\d+) % health by (?<att_name>[^ ]+)!$/",
				$eventObj->message,
			))
		) {
			return;
		}

		try {
			$pf = Playfield::fromName($matches['playfield']);
		} catch (Throwable) {
			return;
		}
		$attPlayer = $matches['att_name'];
		$attOrg = $matches['att_org'] ?? null;
		$attack = $this->getMatchingAttack($pf, $attPlayer, $attOrg);
		$site = $this->getMatchingSite($pf, $attack);

		$whois = $this->playerManager->byName($attPlayer);
		if ($whois === null) {
			$whois = new Player(
				charid: 0,
				dimension: $this->config->main->dimension,
				name: $attPlayer,
				faction: Faction::Unknown,
			);
		}
		$whois->guild = $attOrg;
		if (isset($attack, $attack->attacker->org) && $attack->attacker->org->name === $attOrg) {
			$whois->guild_id = $attack->attacker->org->id;
		}
		if (isset($attack, $attack->attacker->faction)) {
			$whois->faction = $attack->attacker->faction;
		}
		if (isset($attack) && $attack->attacker->name === $attPlayer) {
			if (isset($attack->attacker->level)) {
				$whois->level = $attack->attacker->level;
			}
			if (isset($attack->attacker->ai_level)) {
				$whois->ai_level = $attack->attacker->ai_level;
			}
			if (isset($attack->attacker->faction)) {
				$whois->faction = $attack->attacker->faction;
			}
			if (isset($attack->attacker->org_rank)) {
				$whois->guild_rank = $attack->attacker->org_rank;
			}
			if (isset($attack->attacker->profession)) {
				$whois->profession = $attack->attacker->profession;
			}
			if (isset($attack->attacker->character_id)) {
				$whois->charid = $attack->attacker->character_id;
			}
		}
		$siteName = $pf->short();
		if (isset($site)) {
			$siteName .= " {$site->site_id}";
			$siteName = Text::makeBlob(
				$siteName,
				$this->nwCtrl->renderSite($site, false, false, null),
			);
		}
		$tokens = array_merge(
			[
				'tower-health' => $matches['health'],
				'tower-type' => $matches['tower'],
				'site-details' => $siteName,
			],
			$whois->getTokens('att-'),
			$pf->getTokens(),
		);
		if (isset($site)) {
			$tokens = array_merge($tokens, $site->getTokens());
		}
		$msg = Text::renderPlaceholders($this->ownTowerHitFormat, $tokens);
		$rMsg = new RoutableMessage($msg);
		$rMsg->appendPath(new Source('pvp', 'tower-hit-own'));
		$this->msgHub->handle($rMsg);
	}

	/** Announce tower attacks */
	#[NCA\HandlesEvent]
	public function announceTowerAttack(TowerAttackInfoEvent $event): void {
		if ($event->site === null) {
			$this->logger->error('ERROR! Could not find closest site for attack');
			return;
		}
		$site = $event->site;
		$this->logger->info('Site being attacked: {pf_short} {site_id}', [
			'pf_short' => $site->playfield->short(),
			'site_id' => $site->site_id,
		]);


		$details = $this->renderAttackInfo($event, $site->playfield);
		$shortSite = "{$site->playfield->short()} {$site->site_id}";
		$detailsLink = Text::makeBlob(
			$shortSite,
			$details,
			"Attack on {$shortSite}",
		);

		/** @var array<string,int|string|null> */
		$tokens = array_merge(
			$event->attack->getTokens(),
			$site->playfield->getTokens(),
			$site->getTokens(),
		);

		$msg = Text::renderPlaceholders(
			$this->towerAttackFormat,
			$tokens
		);
		$msg .= " [{$detailsLink}]";

		if (isset($site->org_id) && $this->config->orgId === $site->org_id) {
			$rMsg = new RoutableMessage($msg);
			$rMsg->prependPath(new Source('pvp', 'tower-attack-own'));
			$this->msgHub->handle($rMsg);
		}
		$rMsg = new RoutableMessage($msg);
		$rMsg->prependPath(new Source('pvp', 'tower-attack'));
		$this->msgHub->handle($rMsg);
		$this->siteTracker->fireEvent(new RoutableMessage($msg), $site, 'tower-attack');
	}

	/** Announce tower victories and abandoned sites */
	#[NCA\HandlesEvent]
	public function announceTowerVictories(Event\TowerOutcomeEvent $event): void {
		$outcome = $event->outcome;
		$pf = $outcome->playfield;
		$site = $this->nwCtrl->state[$pf->value][$outcome->site_id];
		if (!isset($site)) {
			return;
		}
		/*
		$site->ct_pos = null;
		$site->num_conductors = 0;
		$site->num_turrets = 0;
		$site->org_faction = null;
		$site->org_id = null;
		$site->org_name = null;
		$site->plant_time = null;
		$site->ql = null;
		*/

		/** @var array<string,string|int|null> */
		$tokens = array_merge(
			$outcome->getTokens(),
			$site->getTokens(),
			$pf->getTokens(),
		);
		$format = isset($tokens['winning-faction'])
			? $this->towerVictoryFormat
			: $this->siteAbandonedFormat;
		$msg = Text::renderPlaceholders($format, $tokens);

		$details = $this->nwCtrl->renderSite($site, false, true, $outcome);
		$shortSite = "{$pf->short()} {$site->site_id}";
		$detailsLink = Text::makeBlob(
			$shortSite,
			$details,
		);
		$msg .= " [{$detailsLink}]";

		if (isset($site->org_id) && $this->config->orgId === $site->org_id) {
			$rMsg = new RoutableMessage($msg);
			$rMsg->prependPath(new Source('pvp', 'tower-outcome-own'));
			$this->msgHub->handle($rMsg);
		}
		$rMsg = new RoutableMessage($msg);
		$rMsg->prependPath(new Source('pvp', 'tower-outcome'));
		$this->msgHub->handle($rMsg);
		$this->siteTracker->fireEvent(new RoutableMessage($msg), $site, 'tower-outcome');
	}

	/** Show the last tower attack messages */
	#[NCA\HandlesCommand(self::CMD_ATTACKS)]
	public function nwAttacksAnywhereCommand(
		CmdContext $context,
		#[Str('attacks')] string $action,
		?int $page,
	): void {
		$query = $this->db->table(DBTowerAttack::getTable());
		$context->reply($this->nwAttacksCmd(
			$query,
			'Tower Attacks',
			'nw attacks',
			$page??1,
			null,
		));
	}

	/** Show the last tower attack messages for a site */
	#[NCA\HandlesCommand(self::CMD_ATTACKS)]
	public function nwAttacksForSiteCommand(
		CmdContext $context,
		#[Str('attacks')] string $action,
		PTowerSite $towerSite,
		?int $page,
	): void {
		$query = $this->db->table(DBTowerAttack::getTable())
			->where('playfield_id', $towerSite->pf->value)
			->where('site_id', $towerSite->site);
		$context->reply($this->nwAttacksCmd(
			$query,
			"Tower Attacks on {$towerSite->pf->short()} {$towerSite->site}",
			'nw attacks',
			$page??1,
			false,
		));
	}

	/**
	 * Show the last tower attack messages involving a specific organization
	 *
	 * You can use '*' as a wildcard in org names
	 */
	#[NCA\HandlesCommand(self::CMD_ATTACKS)]
	#[NCA\Help\Example('<symbol>nw attacks org *sneak*')]
	#[NCA\Help\Example('<symbol>nw attacks org Komodo')]
	public function nwAttacksForOrgCommand(
		CmdContext $context,
		#[Str('attacks')] string $action,
		#[Str('org')] string $org,
		#[NonGreedy] string $orgName,
		?int $page,
	): void {
		$search = str_replace('*', '%', $orgName);
		$query = $this->db->table(DBTowerAttack::getTable())
			->whereIlike('att_org', $search)
			->orWhereIlike('def_org', $search);
		$context->reply(
			$this->nwAttacksCmd(
				$query,
				"Tower Attacks on/by '{$orgName}'",
				"nw attacks org {$orgName}",
				$page??1,
				false,
			)
		);
	}

	/**
	 * Show the last tower attack messages involving a given character
	 *
	 * You can use '*' as a wildcard in character names
	 */
	#[NCA\HandlesCommand(self::CMD_ATTACKS)]
	#[NCA\Help\Example('<symbol>nw attacks char nady*')]
	#[NCA\Help\Example('<symbol>nw attacks char nadyita')]
	public function nwAttacksForCharCommand(
		CmdContext $context,
		#[Str('attacks')] string $action,
		#[Str('char')] string $char,
		#[NonGreedy] string $search,
		?int $page,
	): void {
		$query = $this->db->table(DBTowerAttack::getTable())
			->whereIlike('att_name', str_replace('*', '%', $search));
		$context->reply(
			$this->nwAttacksCmd(
				$query,
				"Tower Attacks by '{$search}'",
				"nw attacks char {$search}",
				$page??1,
				false,
			)
		);
	}

	/** See how many tower sites each faction has taken and lost in the past 24 hours or &lt;duration&gt; */
	#[NCA\HandlesCommand(self::CMD_STATS)]
	public function nwSTatsCommand(
		CmdContext $context,
		#[Str('stats')] string $action,
		?PDuration $duration,
	): void {
		$from = time() - (isset($duration) ? $duration->toSecs() : 3_600 * 24);

		$attacks = $this->db->table(DBTowerAttack::getTable())
			->where('timestamp', '>', $from)
			->whereNotNull('att_faction')
			->asObj(DBTowerAttack::class);

		$victories = $this->db->table(DBOutcome::getTable())
			->where('timestamp', '>', $from)
			->whereNotNull('attacker_faction')
			->asObj(DBOutcome::class);

		$abandonments = $this->db->table(DBOutcome::getTable())
			->where('timestamp', '>', $from)
			->whereNull('attacker_faction')
			->asObj(DBOutcome::class);

		$blob = "<header2>Attacks<end>\n".
			'<tab><clan>Clans<end> have attacked '.
			$this->times($attacks->where('att_faction', 'Clan')->count()) . ".\n".
			'<tab><neutral>Neutrals<end> have attacked '.
			$this->times($attacks->where('att_faction', 'Neutral')->count()) . ".\n".
			'<tab><omni>Omnis<end> have attacked '.
			$this->times($attacks->where('att_faction', 'Omni')->count()) . '.'.
			"\n\n".
			"<header2>Victories<end>\n".
			'<tab><clan>Clans<end> have lost '.
			$this->sites($victories->where('losing_faction', 'Clan')->count()) . ".\n".
			'<tab><neutral>Neutrals<end> have lost '.
			$this->sites($victories->where('losing_faction', 'Neutral')->count()) . ".\n".
			'<tab><omni>Omnis<end> have lost '.
			$this->sites($victories->where('losing_faction', 'Omni')->count()) . '.'.
			"\n\n" .
			"<header2>Abandonments<end>\n".
			'<tab><clan>Clans<end> have abandoned '.
			$this->sites($abandonments->where('losing_faction', 'Clan')->count()) . ".\n".
			'<tab><neutral>Neutrals<end> have abandoned '.
			$this->sites($abandonments->where('losing_faction', 'Neutral')->count()) . ".\n".
			'<tab><omni>Omnis<end> have abandoned '.
			$this->sites($abandonments->where('losing_faction', 'Omni')->count()) . '.';

		$msg = Text::makeBlob(
			'Tower stats for the last ' . Util::unixtimeToReadable(time() - $from),
			$blob
		);
		$context->reply($msg);
	}

	/** Show the last tower victories */
	#[NCA\HandlesCommand(self::CMD_OUTCOMES)]
	public function nwOutcomesAnywhereCommand(
		CmdContext $context,
		#[Str('victory')] string $action,
		?int $page,
	): void {
		$query = $this->db->table(DBOutcome::getTable());
		$context->reply($this->nwOutcomesCmd(
			$query,
			'Tower Victories',
			'nw victory',
			$page??1,
		));
	}

	/** Show the last tower victories for a site */
	#[NCA\HandlesCommand(self::CMD_OUTCOMES)]
	public function nwOutcomesForSiteCommand(
		CmdContext $context,
		#[Str('victory')] string $action,
		PTowerSite $towerSite,
		?int $page,
	): void {
		$query = $this->db->table(DBOutcome::getTable())
			->where('playfield_id', $towerSite->pf->value)
			->where('site_id', $towerSite->site);
		$context->reply($this->nwOutcomesCmd(
			$query,
			"Tower Victories on {$towerSite->pf->short()} {$towerSite->site}",
			'nw victory',
			$page??1,
		));
	}

	/**
	 * Show the last tower victories involving a specific organization
	 *
	 * You can use '*' as a wildcard in org names
	 */
	#[NCA\HandlesCommand(self::CMD_ATTACKS)]
	#[NCA\Help\Example('<symbol>nw victory org *sneak*')]
	#[NCA\Help\Example('<symbol>nw victory org Komodo')]
	public function nwOutcomesForOrgCommand(
		CmdContext $context,
		#[Str('victory')] string $action,
		#[Str('org')] string $org,
		#[NonGreedy] string $orgName,
		?int $page,
	): void {
		$search = str_replace('*', '%', $orgName);
		$query = $this->db->table(DBOutcome::getTable())
			->whereIlike('attacker_org', $search)
			->orWhereIlike('losing_org', $search);
		$context->reply(
			$this->nwOutcomesCmd(
				$query,
				"Tower Attacks on/by '{$orgName}'",
				"nw attacks org {$orgName}",
				$page??1
			)
		);
	}

	#[
		NCA\NewsTile(
			name: 'tower-own-new',
			description: "Show the last 5 attacks on your org's towers from the last 3\n".
				'days - or nothing, if no attacks occurred.',
			example: "<header2>Notum Wars [<u>see more</u>]<end>\n".
				'<tab>22-Oct-2021 18:20 UTC - Nady (<clan>Team Rainbow<end>) attacked <u>CLON 6</u> (QL 35-50):'
		)
	]
	public function towerOwnTile(string $sender): ?string {
		try {
			$whois = $this->playerManager->byName($sender);
			$text = $this->getTowerSelfTile($whois);
		} catch (Throwable) {
			return null;
		}
		return $text;
	}

	private function getMatchingAttack(Playfield $pf, string $attName, ?string $attOrgName): ?FeedMessage\TowerAttack {
		$attacks = collect($this->nwCtrl->attacks)
			->where('playfield_id', $pf->value)
			->whereNull('penalizing_ended')
			->where('defender.name', $this->config->general->orgName);
		if (isset($attOrgName)) {
			$attacks = $attacks->where('attacker.org.name', $attOrgName);
		} else {
			$attacks = $attacks->where('attacker.name', $attName);
		}
		return $attacks->sortByDesc('timestamp')->first();
	}

	private function getMatchingSite(Playfield $pf, ?FeedMessage\TowerAttack $attack): ?FeedMessage\SiteUpdate {
		if (isset($attack)) {
			return $this->nwCtrl->state[$attack->playfield->value][$attack->site_id] ?? null;
		}
		$sites = collect($this->nwCtrl->getEnabledSites())
			->where('playfield_id', $pf->value)
			->where('org_id', $this->config->orgId);
		// Actually, this can only happen with gas 5% or 25%, but if it's 1 site only
		// already, use that one
		if ($sites->count() === 1) {
			return $sites->firstOrFail();
		}
		$sites = $sites
			->whereNull('gas')
			->where('gas', '!=', 75);
		if ($sites->count() === 1) {
			return $sites->firstOrFail();
		}
		return null;
	}

	/** Return <highlight>{$count} tower time(s)<end> */
	private function times(int $count): string {
		return "<highlight>{$count} " . Text::pluralize('time', $count) . '<end>';
	}

	/** Return <highlight>{$count} tower site(s)<end> */
	private function sites(int $count): string {
		return "<highlight>{$count} tower " . Text::pluralize('site', $count) . '<end>';
	}

	private function renderAttackInfo(TowerAttackInfoEvent $info, Playfield $pf): string {
		$attack = $info->attack;
		$attacker = $attack->attacker;
		$site = $info->site;
		assert(isset($site));
		$blob = '';
		if ($info->attack->isFake) {
			$blob .= "<highlight>Warning:<end> The attacker is very likely a pet with a fake name!\n\n";
		}
		$blob .= "<header2>Attacker<end>\n";
		$blob .= "<tab>Name: <highlight>{$attacker->name}<end>\n";
		if (isset($attacker->breed) && strlen($attacker->breed)) {
			$blob .= "<tab>Breed: <highlight>{$attacker->breed}<end>\n";
		}
		if (isset($attacker->gender) && strlen($attacker->gender)) {
			$blob .= "<tab>Gender: <highlight>{$attacker->gender}<end>\n";
		}

		if (isset($attacker->profession)) {
			$blob .= '<tab>Profession: ' . $attacker->profession->inColor() . "\n";
		}
		if (isset($attacker->level, $attacker->ai_level)) {
			$levelInfo = $this->lvlCtrl->getLevelInfo($attacker->level);
			if (isset($levelInfo)) {
				$blob .= "<tab>Level: <highlight>{$attacker->level}/<green>{$attacker->ai_level}<end> ({$levelInfo->pvpMin}-{$levelInfo->pvpMax})<end>\n";
			}
		}

		$attFaction = $attacker->faction ?? $attacker->org?->faction;
		if (isset($attFaction)) {
			$blob .= "<tab>Alignment: {$attFaction->inColor()}\n";
		}

		if (isset($attacker->org)) {
			$blob .= "<tab>Organization: <highlight>{$attacker->org->name}<end>\n";
			if (isset($attacker->org_rank)) {
				$blob .= "<tab>Organization Rank: <highlight>{$attacker->org_rank}<end>\n";
			}
		}

		$blob .= "\n";

		$blob .= "<header2>Defender<end>\n";
		$blob .= "<tab>Organization: <highlight>{$attack->defender->name}<end>\n";
		$blob .= '<tab>Alignment: ' . $attack->defender->faction->inColor() . "\n\n";

		$baseLink = Text::makeChatcmd("{$pf->short()} {$site->site_id}", "/tell <myname> nw lc {$pf->short()} {$site->site_id}");
		$attackWaypoint = Text::makeChatcmd(
			"{$attack->location->x}x{$attack->location->y}",
			"/waypoint {$attack->location->x} {$attack->location->y} {$pf->value}"
		);
		$blob .= "<header2>Waypoints<end>\n";
		$blob .= "<tab>Site: <highlight>{$baseLink} ({$site->min_ql}-{$site->max_ql})<end>\n";
		$blob .= "<tab>Attack: <highlight>{$pf->long()} ({$attackWaypoint})<end>\n";

		return $blob;
	}

	private function renderDBOutcome(DBOutcome $outcome, FeedMessage\SiteUpdate $site, Playfield $pf): string {
		$blob = 'Time: ' . Util::date($outcome->timestamp) . ' ('.
			'<highlight>' . Util::unixtimeToReadable(time() - $outcome->timestamp).
			"<end> ago)\n";
		if (isset($outcome->attacker_org, $outcome->attacker_faction)) {
			$blob .= 'Winner: ' . $outcome->attacker_faction->inColor($outcome->attacker_org) . "\n";
		} else {
			$blob .= "Winner: <grey>abandoned<end>\n";
		}
		$blob .= 'Loser: ' . $outcome->losing_faction->inColor($outcome->losing_org) . "\n";
		$siteLink = Text::makeChatcmd(
			"{$pf->short()} {$site->site_id}",
			"/tell <myname> <symbol>nw lc {$pf->short()} {$site->site_id}"
		);
		$blob .= "Site: {$siteLink} (QL {$site->min_ql}-{$site->max_ql})";

		return $blob;
	}

	/** @return list<string> */
	private function nwAttacksCmd(QueryBuilder $query, string $title, string $command, int $page, ?bool $group): array {
		$numAttacks = $query->count();

		/** @psalm-suppress DocblockTypeContradiction */
		if ($query->limit === null) {
			$query = $query->limit(15)->offset(($page-1) * 15);
		}
		$attacks = $query
			->orderByDesc('timestamp')
			->asObj(DBTowerAttack::class);
		return $this->renderAttackList(
			$command,
			$page,
			$numAttacks,
			$title,
			$group,
			...$attacks->toArray()
		);
	}

	/** @return list<string> */
	private function nwOutcomesCmd(QueryBuilder $query, string $title, string $command, int $page): array {
		$numOutcomes = $query->count();
		$attacks = $query
			->orderByDesc('timestamp')
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

	/**
	 * Group a given list of attacks into attack-phases divided
	 * by victories and 75% phases
	 *
	 * @return Collection<string,Collection<int,DBTowerAttack>>
	 */
	private function groupAttackList(DBTowerAttack ...$towerAttacks): Collection {
		$attacks = collect($towerAttacks)->sortByDesc('timestamp');
		$firstAttack = $attacks->first();
		$lastAttack = $attacks->last();

		/** @var array<string,list<DBOutcome>> $outcomes */
		$outcomes = [];
		if (isset($firstAttack, $lastAttack)) {
			/**
			 * @var array<string,list<DBOutcome>>
			 *
			 * @phpstan-ignore-next-line
			 */
			$outcomes = $this->db->table(DBOutcome::getTable())
				->where('timestamp', '>', $lastAttack->timestamp)
				->where('timestamp', '<', $firstAttack->timestamp)
				->orderByDesc('timestamp')
				->asObj(DBOutcome::class)
				->groupBy(static function (DBOutcome $outcome): string {
					return "{$outcome->losing_org}:{$outcome->playfield->value}:{$outcome->site_id}";
				})->toArray();
		}

		/**
		 * A hash with site/owner key and a list of attacks for this combination
		 *
		 * @var array<string,list<DBTowerAttack>>
		 */
		$groups = $attacks
			->reduce(static function (array $groups, DBTowerAttack $attack): array {
				$key = "{$attack->def_org}:{$attack->playfield->value}:{$attack->site_id}";
				$groups[$key] ??= [];
				$groups[$key] []= $attack;
				return $groups;
			}, []);
		$lookup = [];
		foreach ($groups as $key => $gAttacks) {
			$keyOutcomes = $outcomes[$key] ?? [];
			$lastAttack = null;
			$id = 0;
			$lastOutcome = array_shift($keyOutcomes);
			foreach ($gAttacks as $attack) {
				if (isset($lastOutcome) && $lastOutcome->timestamp > $attack->timestamp) {
					$id++;
					$lastOutcome = array_shift($keyOutcomes);
				} elseif (!isset($lastAttack) || abs($lastAttack->timestamp - $attack->timestamp) > 6*3_600) {
					$id++;
				}
				$lookup["{$key}:{$attack->timestamp}"] = $id;
				$lastAttack = $attack;
			}
		}

		$grouped = collect($attacks)->groupBy(
			static function (DBTowerAttack $attack) use ($lookup): string {
				$key = "{$attack->def_org}:{$attack->playfield->value}:{$attack->site_id}";
				return $key . ':' . $lookup["{$key}:{$attack->timestamp}"];
			}
		);
		return $grouped;
	}

	/** @return list<string> */
	private function renderAttackList(
		string $baseCommand,
		int $page,
		int $numAttacks,
		string $title,
		?bool $groupTowerAttacks,
		DBTowerAttack ...$attacks
	): array {
		if (!count($attacks)) {
			return ['No tower attacks found.'];
		}
		$groupTowerAttacks ??= $this->groupTowerAttacks;
		if ($groupTowerAttacks) {
			$groups = $this->groupAttackList(...$attacks);

			/** @param Collection<int,DBTowerAttack> $attacks */
			$blocks = $groups->map(function (Collection $attacks): string {
				$first = $attacks->firstOrFail();

				$last = $attacks->last();
				$pf = $first->playfield;
				$site = $this->nwCtrl->state[$pf->value][$first->site_id] ?? null;
				assert(isset($last));
				assert(isset($site));

				$outcome = $this->db->table(DBOutcome::getTable())
					->where('losing_org', $first->def_org)
					->where('timestamp', '>', $last->timestamp)
					->where('timestamp', '<', $last->timestamp + 6 * 3_600)
					->where('playfield_id', $site->playfield->value)
					->where('site_id', $site->site_id)
					->whereNotNull('attacker_org')
					->orderBy('timestamp')
					->firstObj(DBOutcome::class);

				$blocks = [];

				foreach ($attacks as $attack) {
					$blocks []= Util::date($attack->timestamp) . ': '.
						$this->renderDBAttacker($attack);
				}
				return "<header2>{$pf->short()} {$first->site_id}<end>".
					(
						isset($first->ql)
					? " (QL {$first->ql}) ["
					: " (QL {$site->min_ql}-{$site->max_ql}) ["
					).
					Text::makeChatcmd(
						'details',
						"/tell <myname> <symbol>nw lc {$pf->short()} {$first->site_id}"
					) . "]\n".
					'<tab>Defender: ' . $first->def_faction->inColor($first->def_org) . "\n".
					(
						isset($outcome, $outcome->attacker_faction, $outcome->attacker_org)
							? '<tab>Won by ' . $outcome->attacker_faction->inColor($outcome->attacker_org).
								' at ' . Util::date($outcome->timestamp) . "\n\n"
							: "\n"
					) . '<tab>'.
					implode("\n<tab>", $blocks);
			})->toList();
		} else {
			$blocks = [];
			foreach ($attacks as $attack) {
				$blocks []= $this->renderDBAttack($attack);
			}
		}
		$prevLink = '&lt;&lt;&lt;';
		if ($page > 1) {
			$prevLink = Text::makeChatcmd(
				$prevLink,
				"/tell <myname> {$baseCommand} " . ($page-1)
			);
		}
		$nextLink = '';
		if ($page * 15 < $numAttacks) {
			$nextLink = Text::makeChatcmd(
				'&gt;&gt;&gt;',
				"/tell <myname> {$baseCommand} " . ($page+1)
			);
		}
		$blob = '';
		if ($numAttacks > 15) {
			$blob = "{$prevLink}<tab>Page {$page}<tab>{$nextLink}\n\n";
		}
		$blob .= implode("\n\n", $blocks);
		$msg = Text::makeBlob(
			$title,
			$blob
		);
		return (array)$msg;
	}

	/** @return list<string> */
	private function renderOutcomeList(
		string $baseCommand,
		int $page,
		int $numAttacks,
		string $title,
		DBOutcome ...$outcomes
	): array {
		if (!count($outcomes)) {
			return ['No tower victories found.'];
		}
		$blocks = [];
		foreach ($outcomes as $outcome) {
			$pf = $outcome->playfield;
			$site = $this->nwCtrl->state[$pf->value][$outcome->site_id] ?? null;
			if (!isset($site)) {
				continue;
			}
			$blocks []= $this->renderDBOutcome($outcome, $site, $pf);
		}
		$prevLink = '&lt;&lt;&lt;';
		if ($page > 1) {
			$prevLink = Text::makeChatcmd(
				$prevLink,
				"/tell <myname> {$baseCommand} " . ($page-1)
			);
		}
		$nextLink = '';
		if ($page * 15 < $numAttacks) {
			$nextLink = Text::makeChatcmd(
				'&gt;&gt;&gt;',
				"/tell <myname> {$baseCommand} " . ($page+1)
			);
		}
		$blob = '';
		if ($numAttacks > 15) {
			$blob = "{$prevLink}<tab>Page {$page}<tab>{$nextLink}\n\n";
		}
		$blob .= implode("\n\n", $blocks);
		$msg = Text::makeBlob(
			$title,
			$blob
		);
		return (array)$msg;
	}

	/** Render info about an attacker for !nw attacks */
	private function renderDBAttacker(DBTowerAttack $attack): string {
		$attColor = strtolower($attack->att_faction->value ?? 'Unknown');
		$blob = "<{$attColor}>{$attack->att_name}<end>";
		if (isset($attack->att_level, $attack->att_ai_level, $attack->att_profession)) {
			$blob .= " ({$attack->att_level}/<green>{$attack->att_ai_level}<end>";
			if (isset($attack->att_gender)) {
				$blob .= ", {$attack->att_gender}";
			}
			if (isset($attack->att_breed)) {
				$blob .= " {$attack->att_breed}";
			}
			$blob .= " <highlight>{$attack->att_profession->value}<end>";
			if (isset($attack->att_org_rank)) {
				$blob .= ", {$attack->att_org_rank}";
			}
			if (isset($attack->att_org)) {
				$blob .= " of <{$attColor}>{$attack->att_org}<end>)";
			} else {
				$blob .= ')';
			}
		} elseif (isset($attack->att_org)) {
			$blob .= " <{$attColor}>{$attack->att_org}<end>";
		}
		return $blob;
	}

	/** Render a single, un-grouped !nw attacks line */
	private function renderDBAttack(DBTowerAttack $attack): string {
		$blob = 'Time: ' . Util::date($attack->timestamp).
			' (<highlight>'.
			Util::unixtimeToReadable(time() - $attack->timestamp).
			"<end> ago)\n";
		$blob .= 'Attacker: ' . $this->renderDBAttacker($attack) . "\n";
		$blob .= 'Defender: ' . $attack->def_faction->inColor($attack->def_org);
		$site = $this->nwCtrl->state[$attack->playfield->value][$attack->site_id] ?? null;
		$pf = $attack->playfield;
		if (isset($site)) {
			$blob .= "\nSite: " . Text::makeChatcmd(
				"{$pf->short()} {$attack->site_id}",
				"/tell <myname> nw lc {$pf->short()} {$attack->site_id}"
			) . " (QL {$site->min_ql}-{$site->max_ql})";
		}
		return $blob;
	}

	private function getTowerSelfTile(?Player $whois): ?string {
		if (!isset($whois) || !isset($whois->guild)) {
			return null;
		}
		$query = $this->db->table(DBTowerAttack::getTable())
			->where('def_org', $whois->guild)
			->where('timestamp', '>=', time() - (3 * 24 * 3_600))
			->limit(5);
		if ($query->count() === 0) {
			return null;
		}
		// This is a hacky way, until I make this function even more generic
		$blob = Text::getPopups($this->nwAttacksCmd(
			$query,
			'Tower Attacks',
			'nw attacks',
			1,
			true,
		)[0])[0];
		$blob = Safe::pregReplace('/^.+?<header2>/s', '<header2>', $blob);
		$blob = '<tab>' . implode("\n<tab>", explode("\n", $blob));
		$moreLink = Text::makeChatcmd('see more', "/tell <myname> nw attacks org {$whois->guild}");
		return "<header2>Notum Wars [{$moreLink}]<end>\n{$blob}";
	}
}
