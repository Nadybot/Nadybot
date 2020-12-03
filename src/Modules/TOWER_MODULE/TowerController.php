<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use Nadybot\Core\{
	AOChatEvent,
	CommandReply,
	DB,
	DBSchema\Player,
	Event,
	EventManager,
	LoggerWrapper,
	Modules\DISCORD\DiscordController,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	SettingManager,
	Text,
	Util,
};
use Nadybot\Modules\{
	HELPBOT_MODULE\Playfield,
	HELPBOT_MODULE\PlayfieldController,
	LEVEL_MODULE\LevelController,
	TIMERS_MODULE\Alert,
	TIMERS_MODULE\TimerController,
};

/**
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'towerstats',
 *		accessLevel = 'all',
 *		description = 'Show how many towers each faction has lost',
 *		help        = 'towerstats.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'attacks',
 *      alias       = 'battles',
 *		accessLevel = 'all',
 *		description = 'Show the last Tower Attack messages',
 *		help        = 'attacks.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'forcescout',
 *		accessLevel = 'guild',
 *		description = 'Add tower info to watch list (bypasses some of the checks)',
 *		help        = 'scout.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'lc',
 *		accessLevel = 'all',
 *		description = 'Show status of towers',
 *		help        = 'lc.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'opentimes',
 *		accessLevel = 'guild',
 *		description = 'Show status of towers',
 *		help        = 'scout.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'penalty',
 *		accessLevel = 'all',
 *		description = 'Show orgs in penalty',
 *		help        = 'penalty.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'remscout',
 *		accessLevel = 'guild',
 *		description = 'Remove tower info from watch list',
 *		help        = 'scout.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'scout',
 *		accessLevel = 'guild',
 *		description = 'Add tower info to watch list',
 *		help        = 'scout.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'victory',
 *		accessLevel = 'all',
 *		description = 'Show the last Tower Battle results',
 *		help        = 'victory.txt',
 *		alias       = 'victories'
 *	)
 *  @ProvidesEvent("tower(attack)")
 *  @ProvidesEvent("tower(win)")
 */
class TowerController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public PlayfieldController $playfieldController;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public DiscordController $discordController;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Util $util;
	
	/** @Inject */
	public LevelController $levelController;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Inject */
	public TimerController $timerController;

	/** @var AttackListener[] */
	protected array $attackListeners = [];

	/**
	 * @Setting("tower_attack_spam")
	 * @Description("Layout types when displaying tower attacks")
	 * @Visibility("edit")
	 * @Type("options")
	 * @Options("off;compact;normal")
	 * @Intoptions("0;1;2")
	 * @AccessLevel("mod")
	 */
	public $defaultTowerAttackSpam = 1;

	/**
	 * @Setting("tower_page_size")
	 * @Description("Number of results to display for victory/attacks")
	 * @Visibility("edit")
	 * @Type("options")
	 * @Options("5;10;15;20;25")
	 * @Intoptions("5;10;15;20;25")
	 * @AccessLevel("mod")
	 */
	public $defaultTowerPageSize = 15;
	
	/**
	 * @Setting("check_close_time_on_scout")
	 * @Description("Check that close time is within one hour of last victory on site")
	 * @Visibility("edit")
	 * @Type("options")
	 * @Options("true;false")
	 * @Intoptions("1;0")
	 * @AccessLevel("mod")
	 */
	public $defaultCheckCloseTimeOnScout = 1;
	
	/**
	 * @Setting("check_guild_name_on_scout")
	 * @Description("Check that guild name has attacked or been attacked before")
	 * @Visibility("edit")
	 * @Type("options")
	 * @Options("true;false")
	 * @Intoptions("1;0")
	 * @AccessLevel("mod")
	 */
	public $defaultCheckGuildNameOnScout = 1;

	/**
	 * @Setting("tower_plant_timer")
	 * @Description("Start a timer for planting whenever a tower site goes down")
	 * @Visibility("edit")
	 * @Type("options")
	 * @Options("off;priv;org")
	 * @Intoptions("0;1;2")
	 * @AccessLevel("mod")
	 */
	public $defaultTowerPlantTimer = 0;

	/**
	 * @Setting("discord_notify_org_attacks")
	 * @Description("Notify message for Discord if being attacked")
	 * @Visibility("edit")
	 * @Type("text")
	 * @Options("off;@here Our field in {location} is being attacked by {player}")
	 * @AccessLevel("mod")
	 */
	public $defaultDiscordNotifyOrgAttacks = "@here Our field in {location} is being attacked by {player}";

	public int $lastDiscordNotify = 0;

	public const TIMER_NAME = "Towerbattles";

	/**
	 * Adds listener callback which will be called when tower attacks occur.
	 */
	public function registerAttackListener(callable $callback, $data=null): void {
		$listener = new AttackListener();
		$listener->callback = $callback;
		$listener->data = $data;
		$this->attackListeners []= $listener;
	}

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, 'tower_attack');
		$this->db->loadSQLFile($this->moduleName, 'scout_info');
		$this->db->loadSQLFile($this->moduleName, 'tower_site');
		if ($this->db->getType() === $this->db::MYSQL) {
			$this->db->exec("ALTER TABLE `tower_attack_<myname>` CHANGE COLUMN `att_player` `att_player` VARCHAR(50)");
		}

		$this->settingManager->add(
			$this->moduleName,
			"tower_spam_target",
			"Where to send tower messages to",
			"edit",
			"options",
			"2",
			"Off;Priv;Guild;Priv+Guild;Discord;Discord+Priv;Discord+Guild;Discord+Priv+Guild",
			"0;1;2;3;4;5;6;7"
		);
	
		$this->settingManager->add(
			$this->moduleName,
			"tower_spam_color",
			"What color to use for tower messages",
			"edit",
			"color",
			"<font color=#F06AED>"
		);
	}

	/**
	 * This command handler shows the last tower attack messages.
	 *
	 * @HandlesCommand("attacks")
	 * @Matches("/^attacks (\d+)$/i")
	 * @Matches("/^attacks$/i")
	 */
	public function attacksCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$page = $args[1] ?? 1;
		$this->attacksCommandHandler((int)$page, [''], '', $sendto);
	}

	/**
	 * This command handler shows the last tower attack messages by site number
	 * and optionally by page.
	 *
	 * @HandlesCommand("attacks")
	 * @Matches("/^attacks (?!org|player)([a-z0-9]+) (\d+) (\d+)$/i")
	 * @Matches("/^attacks (?!org|player)([a-z0-9]+) (\d+)$/i")
	 */
	public function attacks2Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$playfield = $this->playfieldController->getPlayfieldByName($args[1]);
		if ($playfield === null) {
			$msg = "<highlight>{$args[1]}<end> is not a valid playfield.";
			$sendto->reply($msg);
			return;
		}
	
		$towerInfo = $this->getTowerInfo($playfield->id, (int)$args[2]);
		if ($towerInfo === null) {
			$msg = "<highlight>{$playfield->long_name}<end> doesn't have a site <highlight>X{$args[2]}<end>.";
			$sendto->reply($msg);
			return;
		}
	
		$cmd = "$args[1] $args[2] ";
		$search = [
			"WHERE a.`playfield_id` = ? AND a.`site_number` = ?",
			$towerInfo->playfield_id,
			$towerInfo->site_number
		];
		$page = $args[3] ?? 1;
		$this->attacksCommandHandler((int)$page, $search, $cmd, $sendto);
	}

	/**
	 * This command handler shows the last tower attack messages where given
	 * org has been an attacker or defender.
	 *
	 * @HandlesCommand("attacks")
	 * @Matches("/^attacks org (.+) (\d+)$/i")
	 * @Matches("/^attacks org (.+)$/i")
	 */
	public function attacksOrgCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$cmd = "org $args[1] ";
		$search = [
			"WHERE a.`att_guild_name` LIKE ? OR a.`def_guild_name` LIKE ?",
			$args[1],
			$args[1]
		];
		$this->attacksCommandHandler((int)($args[2] ?? 1), $search, $cmd, $sendto);
	}

	/**
	 * This command handler shows the last tower attack messages where given
	 * player has been as attacker.
	 *
	 * @HandlesCommand("attacks")
	 * @Matches("/^attacks player (.+) (\d+)$/i")
	 * @Matches("/^attacks player (.+)$/i")
	 */
	public function attacksPlayerCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$cmd = "player $args[1] ";
		$search = ["WHERE a.`att_player` LIKE ?", $args[1]];
		$this->attacksCommandHandler((int)($args[2] ?? 1), $search, $cmd, $sendto);
	}

	/**
	 * This command handler shows status of towers.
	 *
	 * @HandlesCommand("lc")
	 * @Matches("/^lc$/i")
	 */
	public function lcCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sql = "SELECT * FROM playfields WHERE `id` IN ".
			"(SELECT DISTINCT `playfield_id` FROM tower_site) ".
			"ORDER BY `short_name`";
		/** @var Playfield[] */
		$playfields = $this->db->fetchAll(Playfield::class, $sql);

		$blob = "<header2>Playfields with notum fields<end>\n";
		foreach ($playfields as $pf) {
			$baseLink = $this->text->makeChatcmd($pf->long_name, "/tell <myname> lc $pf->short_name");
			$blob .= "<tab>$baseLink <highlight>($pf->short_name)<end>\n";
		}
		$msg = $this->text->makeBlob('Land Control Index', $blob);
		$sendto->reply($msg);
	}

	/**
	 * This command handler shows status of towers.
	 *
	 * @HandlesCommand("lc")
	 * @Matches("/^lc ([0-9a-z]+)$/i")
	 */
	public function lc2Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$playfieldName = strtoupper($args[1]);
		$playfield = $this->playfieldController->getPlayfieldByName($playfieldName);
		if ($playfield === null) {
			$msg = "Playfield <highlight>$playfieldName<end> could not be found.";
			$sendto->reply($msg);
			return;
		}

		$sql = "SELECT * FROM tower_site t ".
			"JOIN playfields p ON (t.playfield_id = p.id) ".
			"WHERE t.playfield_id = ?";
	
		/** @var SiteInfo[] */
		$data = $this->db->fetchAll(SiteInfo::class, $sql, $playfield->id);
		if (!count($data)) {
			$msg = "Playfield <highlight>$playfield->long_name<end> does not have any tower sites.";
			$sendto->reply($msg);
			return;
		}
		$blob = '';
		foreach ($data as $row) {
			$blob .= "<pagebreak>" . $this->formatSiteInfo($row) . "\n\n";
		}

		$msg = $this->text->makeBlob("All Bases in $playfield->long_name", $blob);

		$sendto->reply($msg);
	}

	/**
	 * This command handler shows status of towers.
	 *
	 * @HandlesCommand("lc")
	 * @Matches("/^lc ([0-9a-z]+) (\d+)$/i")
	 */
	public function lc3Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$playfieldName = strtoupper($args[1]);
		$playfield = $this->playfieldController->getPlayfieldByName($playfieldName);
		if ($playfield === null) {
			$msg = "Playfield <highlight>$playfieldName<end> could not be found.";
			$sendto->reply($msg);
			return;
		}

		$siteNumber = (int)$args[2];
		$sql = "SELECT * FROM tower_site t ".
			"JOIN playfields p ON (t.playfield_id = p.id) ".
			"WHERE t.playfield_id = ? AND t.site_number = ?";

		/** @var ?SiteInfo */
		$site = $this->db->fetch(SiteInfo::class, $sql, $playfield->id, $siteNumber);
		if ($site === null) {
			$msg = "Invalid site number.";
			$sendto->reply($msg);
			return;
		}
		$blob = $this->formatSiteInfo($site) . "\n\n";

		// show last attacks and victories
		$sql = "SELECT a.*, v.*, COALESCE(v.time, a.time) dt ".
			"FROM tower_attack_<myname> a ".
			"LEFT JOIN tower_victory_<myname> v ON v.attack_id = a.id ".
			"WHERE a.playfield_id = ? AND a.site_number = ? ".
			"ORDER BY dt DESC ".
			"LIMIT 10";
		/** @var TowerAttackAndVictory[] */
		$attacks = $this->db->fetchAll(
			TowerAttackAndVictory::class,
			$sql,
			$playfield->id,
			$siteNumber
		);
		if (count($attacks)) {
			$blob .= "<header2>Recent Attacks<end>\n";
		}
		foreach ($attacks as $attack) {
			if (empty($attack->attack_id)) {
				// attack
				if (!empty($attack->att_guild_name)) {
					$name = $attack->att_guild_name;
				} else {
					$name = $attack->att_player;
				}
				$blob .= "<tab><$attack->att_faction>$name<end> attacked <$attack->def_faction>$attack->def_guild_name<end>\n";
			} else {
				// victory
				$blob .= "<tab><$attack->win_faction>$attack->win_guild_name<end> won against <$attack->lose_faction>$attack->lose_guild_name<end>\n";
			}
		}

		$msg = $this->text->makeBlob("$playfield->short_name $siteNumber", $blob);

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("opentimes")
	 * @Matches("/^opentimes$/i")
	 */
	public function openTimesCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sql = "SELECT guild_name, SUM(ct_ql) AS total_ql ".
			"FROM scout_info ".
			"GROUP BY guild_name ".
			"ORDER BY guild_name ASC";
		$data = $this->db->query($sql);
		$contractQls = [];
		foreach ($data as $row) {
			$contractQls[$row->guild_name] = (int)$row->total_ql;
		}
	
		$sql = "SELECT * ".
			"FROM tower_site t ".
			"JOIN scout_info s ON (t.playfield_id = s.playfield_id AND s.site_number = t.site_number) ".
			"JOIN playfields p ON (t.playfield_id = p.id) ".
			"ORDER BY guild_name ASC, ct_ql DESC";
		$data = $this->db->query($sql);
	
		if (count($data) > 0) {
			$blob = '';
			$currentGuildName = '';
			foreach ($data as $row) {
				if ($row->guild_name !== $currentGuildName) {
					$contractQl = $contractQls[$row->guild_name];
					$contractQl = ($contractQl * 2);
					$faction = strtolower($row->faction);
	
					$blob .= "\n<u><$faction>$row->guild_name<end></u> (Total Contract QL: $contractQl)\n";
					$currentGuildName = $row->guild_name;
				}
				$gasInfo = $this->getGasLevel((int)$row->close_time);
				$gasChangeString = "{$gasInfo->color} {$gasInfo->gas_level} - ".
					"{$gasInfo->next_state} in <highlight>".
					$this->util->unixtimeToReadable($gasInfo->gas_change).
					"<end>";
	
				$siteLink = $this->text->makeChatcmd(
					"$row->short_name $row->site_number",
					"/tell <myname> lc $row->short_name $row->site_number"
				);
				$openTime = $row->close_time - (3600 * 6);
				if ($openTime < 0) {
					$openTime += 86400;
				}
	
				$blob .= "<tab>$siteLink - {$row->min_ql}-{$row->max_ql}, $row->ct_ql CT, $gasChangeString [by $row->scouted_by]\n";
			}
	
			$msg = $this->text->makeBlob("Scouted Bases", $blob);
		} else {
			$msg = "No sites currently scouted.";
		}
		$sendto->reply($msg);
	}

	/**
	 * This command handler shows orgs in penalty.
	 *
	 * @HandlesCommand("penalty")
	 * @Matches("/^penalty$/i")
	 * @Matches("/^penalty ([a-z0-9]+)$/i")
	 */
	public function penaltyCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$budatime = '2h';
		if (count($args) === 2) {
			$budatime = $args[1];
		}
		
		$time = $this->util->parseTime($budatime);
		if ($time < 1) {
			$msg = "You must enter a valid time parameter.";
			$sendto->reply($msg);
			return;
		}

		$penaltyTimeString = $this->util->unixtimeToReadable($time, false);
	
		$orgs = $this->getSitesInPenalty(time() - $time);
	
		if (count($orgs) === 0) {
			$msg = "There are no orgs who have attacked or won battles in the past $penaltyTimeString.";
			$sendto->reply($msg);
			return;
		}
		$blob = '';
		$currentFaction = '';
		foreach ($orgs as $org) {
			if ($currentFaction !== $org->att_faction) {
				$blob .= "\n<header2>{$org->att_faction}<end>\n";
				$currentFaction = $org->att_faction;
			}
			$timeString = $this->util->unixtimeToReadable(time() - $org->penalty_time, false);
			$blob .= "<tab><{$org->att_faction}>{$org->att_guild_name}<end> - $timeString ago\n";
		}
		$msg = $this->text->makeBlob("Orgs in penalty ($penaltyTimeString)", $blob);
		$sendto->reply($msg);
	}

	/**
	 * This command handler removes tower info to watch list.
	 *
	 * @HandlesCommand("remscout")
	 * @Matches("/^remscout ([a-z0-9]+) (\d+)$/i")
	 */
	public function remscoutCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$playfieldName = $args[1];
		$siteNumber = (int)$args[2];
	
		$playfield = $this->playfieldController->getPlayfieldByName($playfieldName);
		if ($playfield === null) {
			$msg = "Invalid playfield.";
			$sendto->reply($msg);
			return;
		}
	
		$towerInfo = $this->getTowerInfo($playfield->id, $siteNumber);
		if ($towerInfo === null) {
			$msg = "Invalid site number.";
			$sendto->reply($msg);
			return;
		}
	
		$numDeleted = $this->remScoutSite($playfield->id, $siteNumber);
	
		if ($numDeleted === 0) {
			$msg = "Could not find a scout record for <highlight>{$playfield->short_name} {$siteNumber}<end>.";
		} else {
			$msg = "<highlight>{$playfield->short_name} {$siteNumber}<end> removed successfully.";
		}
		$sendto->reply($msg);
	}

	protected function scoutInputHandler(string $sender, CommandReply $sendto, array $args, bool $skipChecks): void {
		if (count($args) === 7) {
			$playfieldName = $args[1];
			$siteNumber = (int)$args[2];
			$closingTime = $args[3];
			$ctQL = (int)$args[4];
			$faction = $this->getFaction($args[5]);
			$guildName = $args[6];
		} else {
			$pattern = "@Control Tower - ([^ ]+) Level: (\d+) Danger level: (.+) Alignment: ([^ ]+)  Organization: (.+) Created at UTC: ([^ ]+) ([^ ]+)@si";
			if (preg_match($pattern, $args[3], $arr)) {
				$playfieldName = $args[1];
				$siteNumber = (int)$args[2];
				$closingTime = $arr[7];
				$ctQL = (int)$arr[2];
				$faction = $this->getFaction($arr[1]);
				$guildName = $arr[5];
			} else {
				return;
			}
		}

		$msg = $this->addScoutInfo($sender, $playfieldName, $siteNumber, $closingTime, $ctQL, $faction, $guildName, $skipChecks);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("scout")
	 * @Matches("/^scout ([a-z0-9]+) (\d+) (\d{1,2}:\d{2}:\d{2}) (\d+) ([a-z]+) (.*)$/i")
	 * @Matches("/^scout ([a-z0-9]+) (\d+) (.*)$/i")
	 */
	public function scoutCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->scoutInputHandler($sender, $sendto, $args, false);
	}
	
	/**
	 * @HandlesCommand("forcescout")
	 * @Matches("/^forcescout ([a-z0-9]+) (\d+) (\d{1,2}:\d{2}:\d{2}) (\d+) ([a-z]+) (.*)$/i")
	 * @Matches("/^forcescout ([a-z0-9]+) (\d+) (.*)$/i")
	 */
	public function forcescoutCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->scoutInputHandler($sender, $sendto, $args, true);
	}
	
	public function addScoutInfo(string $sender, string $playfieldName, int $siteNumber, string $closingTime, int $ctQL, string $faction, string $guildName, bool $skipChecks): string {
		if ($faction !== 'Omni' && $faction !== 'Neutral' && $faction !== 'Clan') {
			return "Valid values for faction are: 'Omni', 'Neutral', and 'Clan'.";
		}
	
		$playfield = $this->playfieldController->getPlayfieldByName($playfieldName);
		if ($playfield === null) {
			return "Invalid playfield.";
		}
	
		$towerInfo = $this->getTowerInfo($playfield->id, $siteNumber);
		if ($towerInfo === null) {
			return "Invalid site number.";
		}
	
		if ($ctQL < $towerInfo->min_ql || $ctQL > $towerInfo->max_ql) {
			return "<highlight>$playfield->short_name $towerInfo->site_number<end> ".
				"can only accept Control Tower of ql ".
				"<highlight>{$towerInfo->min_ql}<end>-<highlight>{$towerInfo->max_ql}<end>.";
		}
	
		$closingTimeArray = explode(':', $closingTime);
		$closingTimeSeconds = (int)$closingTimeArray[0] * 3600 + (int)$closingTimeArray[1] * 60 + (int)$closingTimeArray[2];
	
		$checkBlob = "";
		if (!$skipChecks && $this->settingManager->getBool('check_close_time_on_scout')) {
			$last_victory = $this->getLastVictory($towerInfo->playfield_id, $towerInfo->site_number);
			if ($last_victory !== null) {
				$victory_time_of_day = $last_victory->time % 86400;
				if ($victory_time_of_day > $closingTimeSeconds) {
					$victory_time_of_day -= 86400;
				}
	
				if ($closingTimeSeconds - $victory_time_of_day > 3600) {
					$checkBlob .= "- <green>Closing time<end> The closing time you have specified is more than 1 hour after the site was destroyed.";
					$checkBlob .= " Please verify that you are using the closing time and not the gas change time and that the closing time is correct.\n\n";
				}
			}
		}
	
		if (!$skipChecks && $this->settingManager->getBool('check_guild_name_on_scout')) {
			if (!$this->checkGuildName($guildName)) {
				$checkBlob .= "- <green>Org name<end> The org name you entered has never attacked or been attacked.\n\n";
			}
		}
	
		if ($checkBlob) {
			$forceCmd = "forcescout $playfield->short_name $siteNumber $closingTime $ctQL $faction $guildName";
			$forcescoutLink = $this->text->makeChatcmd("<symbol>$forceCmd", "/tell <myname> $forceCmd");
			$checkBlob .= "Please correct these errors, or, if you are sure the values you entered are correct, use !forcescout to bypass these checks.\n\n";
			$checkBlob .= $forcescoutLink;

			return $this->text->makeBlob("Scouting problems for $playfield->short_name $siteNumber", $checkBlob);
		} else {
			$this->addScoutSite($playfield->id, $siteNumber, $closingTimeSeconds, $ctQL, $faction, $guildName, $sender);
			return "Scout info for <highlight>$playfield->short_name $siteNumber<end> has been updated.";
		}
	}

	/**
	 * @HandlesCommand("towerstats")
	 * @Matches("/^towerstats (.+)$/i")
	 * @Matches("/^towerstats$/i")
	 */
	public function towerStatsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$budatime = "1d";
		if (count($args) === 2) {
			$budatime = $args[1];
		}

		$time = $this->util->parseTime($budatime);
		if ($time < 1) {
			$msg = "You must enter a valid time parameter.";
			$sendto->reply($msg);
			return;
		}

		$timeString = $this->util->unixtimeToReadable($time);

		$blob = '';

		$sql = "SELECT att_faction, COUNT(att_faction) AS num ".
			"FROM tower_attack_<myname> ".
			"WHERE `time` >= ? ".
			"GROUP BY att_faction ".
			"ORDER BY num DESC";

		$data = $this->db->query($sql, time() - $time);
		foreach ($data as $row) {
			$blob .= "<{$row->att_faction}>{$row->att_faction}<end> have attacked <highlight>{$row->num}<end> times.\n";
		}
		if (count($data) > 0) {
			$blob .= "\n";
		}

		$sql = "SELECT lose_faction, COUNT(lose_faction) AS num ".
			"FROM tower_victory_<myname> ".
			"WHERE `time` >= ? ".
			"GROUP BY lose_faction ".
			"ORDER BY num DESC";

		$data = $this->db->query($sql, time() - $time);
		foreach ($data as $row) {
			$blob .= "<{$row->lose_faction}>{$row->lose_faction}<end> have lost <highlight>{$row->num}<end> tower sites.\n";
		}

		if ($blob == '') {
			$msg = "No tower attacks or victories have been recorded.";
		} else {
			$msg = $this->text->makeBlob("Tower Stats for the Last $timeString", $blob);
		}
		$sendto->reply($msg);
	}

	/**
	 * This command handler shows the last tower battle results.
	 *
	 * @HandlesCommand("victory")
	 * @Matches("/^victory (\d+)$/i")
	 * @Matches("/^victory$/i")
	 */
	public function victoryCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$page = (int)($args[1] ?? 1);
		$this->victoryCommandHandler($page, [""], "", $sendto);
	}

	/**
	 * This command handler shows the last tower battle results.
	 *
	 * @HandlesCommand("victory")
	 * @Matches("/^victory (?!org|player)([a-z0-9]+) (\d+) (\d+)$/i")
	 * @Matches("/^victory (?!org|player)([a-z0-9]+) (\d+)$/i")
	 */
	public function victory2Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$playfield = $this->playfieldController->getPlayfieldByName($args[1]);
		if ($playfield === null) {
			$msg = "Invalid playfield.";
			$sendto->reply($msg);
			return;
		}
	
		$towerInfo = $this->getTowerInfo($playfield->id, (int)$args[2]);
		if ($towerInfo === null) {
			$msg = "Invalid site number.";
			$sendto->reply($msg);
			return;
		}
	
		$cmd = "$args[1] $args[2] ";
		$search = [
			"WHERE a.`playfield_id` = ? AND a.`site_number` = ?",
			$towerInfo->playfield_id,
			$towerInfo->site_number
		];
		$this->victoryCommandHandler((int)($args[3] ?? 1), $search, $cmd, $sendto);
	}

	/**
	 * This command handler shows the last tower battle results.
	 *
	 * @HandlesCommand("victory")
	 * @Matches("/^victory org (.+) (\d+)$/i")
	 * @Matches("/^victory org (.+)$/i")
	 */
	public function victoryOrgCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$cmd = "org $args[1] ";
		$search = [
			"WHERE v.`win_guild_name` LIKE ? OR v.`lose_guild_name` LIKE ?",
			$args[1],
			$args[1]
		];
		$this->victoryCommandHandler((int)($args[2] ?? 1), $search, $cmd, $sendto);
	}

	/**
	 * This command handler shows the last tower battle results.
	 *
	 * @HandlesCommand("victory")
	 * @Matches("/^victory player (.+) (\d+)$/i")
	 * @Matches("/^victory player (.+)$/i")
	 */
	public function victoryPlayerCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$cmd = "player $args[1] ";
		$search = [
			"WHERE a.`att_player` LIKE ?",
			$args[1]
		];
		$this->victoryCommandHandler((int)($args[2] ?? 1), $search, $cmd, $sendto);
	}

	/**
	 * @Event("orgmsg")
	 * @Description("Notify if org's towers are attacked")
	 */
	public function attackOwnOrgMessageEvent(AOChatEvent $eventObj): void {
		if ($this->util->isValidSender($eventObj->sender)) {
			return;
		}
		if (
			!preg_match(
				"/^The tower (.+?) in (.+?) was just reduced to \d+ % health ".
				"by ([^ ]+) from the (.+?) organization!$/",
				$eventObj->message,
				$matches
			)
			&& !preg_match(
				"/^The tower (.+?) in (.+?) was just reduced to \d+ % health by ([^ ]+)!$/",
				$eventObj->message,
				$matches
			)
			&& !preg_match(
				"/^Your (.+?) tower in (?:.+?) in (.+?) has had its ".
				"defense shield disabled by ([^ ]+) \(.+?\)\.\s*".
				"The attacker is a member of the organization (.+?)\.$/",
				$eventObj->message,
				$matches
			)
		) {
			return;
		}
		$discordMessage = $this->settingManager->getString('discord_notify_org_attacks');
		if (empty($discordMessage) || $discordMessage === "off") {
			return;
		}
		// One notification every 5 minutes seems enough
		if (time() - $this->lastDiscordNotify < 300) {
			return;
		}
		$this->playerManager->getByNameAsync(
			function(?Player $whois) use ($matches, $discordMessage): void {
				$attGuild = $matches[4] ?? null;
				$attPlayer = $matches[3];
				$playfieldName = $matches[2];
				if ($whois === null) {
					$whois = new Player();
					$whois->type = 'npc';
					$whois->name = $attPlayer;
					$whois->faction = 'Neutral';
				} else {
					$whois->type = 'player';
				}
				$playerName = "<highlight>{$whois->name}<end> ({$whois->faction}";
				if ($attGuild) {
					$playerName .= " org \"{$whois->guild}\"";
				}
				$playerName .= ")";
				$discordMessage = str_replace(
					["{player}", "{location}"],
					[$playerName, $playfieldName],
					$discordMessage
				);
				$this->discordController->sendDiscord($discordMessage, true);
				$this->lastDiscordNotify = time();
			},
			$matches[3]
		);
	}

	/**
	 * This event handler record attack messages.
	 *
	 * @Event("towers")
	 * @Description("Record attack messages")
	 */
	public function attackMessagesEvent(AOChatEvent $eventObj): void {
		$attack = new Attack();
		if (preg_match(
			"/^The (Clan|Neutral|Omni) organization ".
			"(.+) just entered a state of war! ".
			"(.+) attacked the (Clan|Neutral|Omni) organization ".
			"(.+)'s tower in ".
			"(.+) at location \\((\\d+),(\\d+)\\)\\.$/i",
			$eventObj->message,
			$arr
		)) {
			$attack->attSide = ucfirst(strtolower($arr[1]));  // comes across as a string instead of a reference, so convert to title case
			$attack->attGuild = $arr[2];
			$attack->attPlayer = $arr[3];
			$attack->defSide = ucfirst(strtolower($arr[4]));  // comes across as a string instead of a reference, so convert to title case
			$attack->defGuild = $arr[5];
			$attack->playfieldName = $arr[6];
			$attack->xCoords = (int)$arr[7];
			$attack->yCoords = (int)$arr[8];
		} elseif (preg_match(
			"/^(.+) just attacked the (Clan|Neutral|Omni) organization ".
			"(.+)'s tower in ".
			"(.+) at location \(([0-9]+), ([0-9]+)\).(.*)$/i",
			$eventObj->message,
			$arr
		)) {
			$attack->attPlayer = $arr[1];
			$attack->defSide = ucfirst(strtolower($arr[2]));  // comes across as a string instead of a reference, so convert to title case
			$attack->defGuild = $arr[3];
			$attack->playfieldName = $arr[4];
			$attack->xCoords = (int)$arr[5];
			$attack->yCoords = (int)$arr[6];
		} else {
			return;
		}
		
		// regardless of what the player lookup says, we use the information from the
		// attack message where applicable because that will always be most up to date
		$this->playerManager->getByNameAsync(
			function(?Player $player) use ($attack): void {
				$this->handleAttack($attack, $player);
			},
			$attack->attPlayer
		);
	}

	public function handleAttack(Attack $attack, ?Player $whois): void {
		if ($whois === null) {
			$whois = new Player();
			$whois->type = 'npc';
			
			// in case it's not a player who causes attack message (pet, mob, etc)
			$whois->name = $attack->attPlayer;
			$whois->faction = 'Neutral';
		} else {
			$whois->type = 'player';
		}
		if (isset($attack->attSide)) {
			$whois->faction = $attack->attSide;
		} else {
			$whois->factionGuess = true;
		}
		$whois->guild = $attack->attGuild ?? null;
		
		$playfield = $this->playfieldController->getPlayfieldByName($attack->playfieldName);
		if ($playfield === null) {
			$this->logger->log('error', "ERROR! Could not find Playfield \"{$attack->playfieldName}\"");
			return;
		}
		$closestSite = $this->getClosestSite($playfield->id, $attack->xCoords, $attack->yCoords);

		$defender = new Defender();
		$defender->faction   = $attack->defSide;
		$defender->guild     = $attack->defGuild;
		$defender->playfield = $playfield;
		$defender->site      = $closestSite;

		foreach ($this->attackListeners as $listener) {
			$callback = $listener->callback;
			$callback($whois, $defender, $listener->data);
		}

		if ($closestSite === null) {
			$this->logger->log('error', "ERROR! Could not find closest site: ({$attack->playfieldName}) '{$playfield->id}' '{$attack->xCoords}' '{$attack->yCoords}'");
			$more = "[<red>UNKNOWN AREA!<end>]";
		} else {
			$this->recordAttack($whois, $attack, $closestSite);
			$this->logger->log('debug', "Site being attacked: ({$attack->playfieldName}) '{$closestSite->playfield_id}' '{$closestSite->site_number}'");

			// Beginning of the 'more' window
			$link = "";
			if (isset($whois->factionGuess)) {
				$link .= "<highlight>Warning:<end> The attacker could also be a pet with a fake name!\n\n";
			}
			$link .= "Attacker: <highlight>";
			if (isset($whois->firstname) && strlen($whois->firstname)) {
				$link .= $whois->firstname . " ";
			}

			$link .= '"' . $attack->attPlayer . '"';
			if (isset($whois->lastname) && strlen($whois->lastname)) {
				$link .= " " . $whois->lastname;
			}
			$link .= "<end>\n";

			if (isset($whois->breed)) {
				$link .= "Breed: <highlight>$whois->breed<end>\n";
			}
			if (isset($whois->gender)) {
				$link .= "Gender: <highlight>$whois->gender<end>\n";
			}

			if (isset($whois->profession)) {
				$link .= "Profession: <highlight>$whois->profession<end>\n";
			}
			if (isset($whois->level)) {
				$level_info = $this->levelController->getLevelInfo($whois->level);
				$link .= "Level: <highlight>{$whois->level}/<green>{$whois->ai_level}<end> ({$level_info->pvpMin}-{$level_info->pvpMax})<end>\n";
			}

			$link .= "Alignment: <highlight>{$whois->faction}<end>\n";

			if (isset($whois->guild)) {
				$link .= "Organization: <highlight>$whois->guild<end>\n";
				if (isset($whois->guild_rank)) {
					$link .= "Organization Rank: <highlight>$whois->guild_rank<end>\n";
				}
			}

			$link .= "\n";

			$link .= "Defender: <highlight>{$attack->defGuild}<end>\n";
			$link .= "Alignment: <highlight>{$attack->defSide}<end>\n\n";

			$baseLink = $this->text->makeChatcmd("{$playfield->short_name} {$closestSite->site_number}", "/tell <myname> lc {$playfield->short_name} {$closestSite->site_number}");
			$attackWaypoint = $this->text->makeChatcmd("{$attack->xCoords}x{$attack->yCoords}", "/waypoint {$attack->xCoords} {$attack->yCoords} {$playfield->id}");
			$link .= "Playfield: <highlight>{$baseLink} ({$closestSite->min_ql}-{$closestSite->max_ql})<end>\n";
			$link .= "Location: <highlight>{$closestSite->site_name} ({$attackWaypoint})<end>\n";

			$more = $this->text->makeBlob("{$playfield->short_name} {$closestSite->site_number}", $link, 'Advanced Tower Info');
		}

		$targetOrg = "<".strtolower($attack->defSide).">{$attack->defGuild}<end>";

		// Starting tower message to org/private chat
		$msg = $this->settingManager->getString('tower_spam_color').
			"[TOWERS]<end> ";
		if ($whois->guild) {
			$msg .= "<".strtolower($whois->faction).">$whois->guild<end>";
		} elseif (isset($whois->factionGuess)) {
			$msg .= "{$attack->attPlayer} (<" . strtolower($whois->faction) . ">{$whois->faction}<end> <highlight>{$whois->profession}<end> or fake name)";
		} else {
			$msg .= "<".strtolower($whois->faction).">{$attack->attPlayer}<end>";
		}
		$msg .= " attacked $targetOrg";

		// tower_attack_spam >= 2 (normal) includes attacker stats
		if ($this->settingManager->getInt("tower_attack_spam") && $whois->type !== 'npc' && !isset($whois->factionGuess)) {
			$msg .= " - ".preg_replace(
				"/, <(omni|neutral|clan)>(omni|neutral|clan)<end>/i",
				'',
				preg_replace(
					"/ of <(omni|neutral|clan)>.+?<end>/i",
					'',
					$this->playerManager->getInfo($whois, false)
				)
			);
		}

		$msg .= " [$more]";

		$s = $this->settingManager->getInt("tower_attack_spam");

		if ($s === 0) {
			return;
		}
		$target = $this->settingManager->getInt('tower_spam_target');
		if ($target & 1) {
			$this->chatBot->sendPrivate($msg, true);
		}
		if ($target & 2) {
			$this->chatBot->sendGuild($msg, true);
		}
		if ($target & 4) {
			$this->discordController->sendDiscord($msg);
		}
	}

	/**
	 * Set a timer to warn 1m, 5s and 0s before you can plant
	 */
	protected function setPlantTimer(string $timerLocation): void {
		$start = time();
		/** @var Alert[] */
		$alerts = [];

		$alert = new Alert();
		$alert->time = $start;
		$alert->message = "Started countdown for planting $timerLocation";
		$alerts []= $alert;

		$alert = new Alert();
		$alert->time = $start + 19*60;
		$alert->message = "<highlight>1 minute<end> remaining to plant $timerLocation";
		$alerts []= $alert;

		$countdown = [5, 4, 3, 2, 1];
		if ($this->settingManager->getInt('tower_plant_timer') === 2) {
			$countdown = [5];
		}
		foreach ($countdown as $remaining) {
			$alert = new Alert();
			$alert->time = $start + 20*60-$remaining;
			$alert->message = "<highlight>${remaining}s<end> remaining to plant ".strip_tags($timerLocation);
			$alerts []= $alert;
		}
		
		$alertPlant = new Alert();
		$alertPlant->time = $start + 20*60;
		$alertPlant->message = "Plant $timerLocation <highlight>NOW<end>";
		$alerts []= $alertPlant;

		$this->timerController->add(
			"Plant " . strip_tags($timerLocation),
			$this->chatBot->vars['name'],
			$this->settingManager->getInt('tower_plant_timer') === 1 ? "priv": "guild",
			$alerts,
			'timercontroller.timerCallback'
		);
	}

	/**
	 * This event handler record victory messages.
	 *
	 * @Event("towers")
	 * @Description("Record victory messages")
	 */
	public function victoryMessagesEvent(Event $eventObj): void {
		if (preg_match("/^The (Clan|Neutral|Omni) organization (.+) attacked the (Clan|Neutral|Omni) (.+) at their base in (.+). The attackers won!!$/i", $eventObj->message, $arr)) {
			$winnerFaction = $arr[1];
			$winnerOrgName = $arr[2];
			$loserFaction  = $arr[3];
			$loserOrgName  = $arr[4];
			$playfieldName = $arr[5];
		} elseif (preg_match("/^Notum Wars Update: The (clan|neutral|omni) organization (.+) lost their base in (.+).$/i", $eventObj->message, $arr)) {
			$winnerFaction = '';
			$winnerOrgName = '';
			$loserFaction  = ucfirst($arr[1]);  // capitalize the faction name to match the other messages
			$loserOrgName  = $arr[2];
			$playfieldName = $arr[3];
		} else {
			return;
		}
		
		$event = new TowerVictoryEvent();

		$playfield = $this->playfieldController->getPlayfieldByName($playfieldName);
		if ($playfield === null) {
			$this->logger->log('error', "Could not find playfield for name '$playfieldName'");
			return;
		}

		if (!$winnerFaction) {
			$msg = $this->settingManager->getString('tower_spam_color').
				"[TOWERS]<end> ".
				"<" . strtolower($loserFaction) . ">{$loserOrgName}<end> ".
				"abandoned their field";
		} else {
			$msg = $this->settingManager->getString('tower_spam_color').
				"[TOWERS]<end> ".
				"<".strtolower($winnerFaction).">{$winnerOrgName}<end>".
				" won against " .
				"<" . strtolower($loserFaction) . ">{$loserOrgName}<end>";
		}

		$lastAttack = $this->getLastAttack($winnerFaction, $winnerOrgName, $loserFaction, $loserOrgName, $playfield->id);

		if ($lastAttack !== null) {
			$towerInfo = $this->getTowerInfo($playfield->id, $lastAttack->site_number);
			$event->site = $towerInfo;
			$waypointLink = $this->text->makeChatcmd("Get a waypoint", "/waypoint {$lastAttack->x_coords} {$lastAttack->y_coords} {$playfield->id}");
			$timerLocation = $this->text->makeBlob(
				"{$playfield->short_name} {$lastAttack->site_number}",
				"Name: <highlight>{$towerInfo->site_name}<end><br>".
				"QL: <highlight>{$towerInfo->min_ql}<end> - <highlight>{$towerInfo->max_ql}<end><br>".
				"Action: $waypointLink",
				"Information about {$playfield->short_name} {$lastAttack->site_number}"
			);
			$msg .= " in " . $timerLocation;
		} else {
			$msg .= " in {$playfield->short_name}";
		}

		if ($this->settingManager->getInt('tower_plant_timer') !== 0) {
			if ($lastAttack === null) {
				$timerLocation = "unknown field in " . $playfield->short_name;
			}

			$this->setPlantTimer($timerLocation);
		}

		$target = $this->settingManager->getInt('tower_spam_target');
		if ($target & 1) {
			$this->chatBot->sendPrivate($msg, true);
		}
		if ($target & 2) {
			$this->chatBot->sendGuild($msg, true);
		}
		if ($target & 4) {
			$this->discordController->sendDiscord($msg);
		}

		if ($lastAttack !== null) {
			$this->remScoutSite($lastAttack->playfield_id, $lastAttack->site_number);
		} else {
			$lastAttack = new TowerAttack();
			$lastAttack->att_guild_name = $winnerOrgName;
			$lastAttack->def_guild_name = $loserOrgName;
			$lastAttack->att_faction = $winnerFaction;
			$lastAttack->def_faction = $loserFaction;
			$lastAttack->playfield_id = $playfield->id;
			$lastAttack->id = -1;
		}
		
		$this->recordVictory($lastAttack);
		$event->attack = $lastAttack;
		$this->eventManager->fireEvent($event);
	}

	protected function attacksCommandHandler(?int $pageLabel=null, array $where, string $cmd, CommandReply $sendto): void {
		if ($pageLabel === null) {
			$pageLabel = 1;
		} elseif ($pageLabel < 1) {
			$msg = "You must choose a page number greater than 0";
			$sendto->reply($msg);
			return;
		}

		$pageSize = $this->settingManager->getInt('tower_page_size');
		$startRow = ($pageLabel - 1) * $pageSize;

		$search = array_shift($where);
		$where = [...$where, $startRow, $pageSize];
		$sql = "SELECT * ".
			"FROM tower_attack_<myname> a ".
			"LEFT JOIN playfields p ON (a.playfield_id = p.id) ".
			"LEFT JOIN tower_site s ON (a.playfield_id = s.playfield_id AND a.site_number = s.site_number) ".
			"$search ".
			"ORDER BY a.`time` DESC ".
			"LIMIT ?, ?";

		$data = $this->db->query($sql, ...$where);
		if (count($data) === 0) {
			$msg = "No tower attacks found.";
		} else {
			$links = [];
			if ($pageLabel > 1) {
				$links['Previous Page'] = '/tell <myname> attacks ' . ($pageLabel - 1);
			}
			$links['Next Page'] = "/tell <myname> attacks {$cmd}" . ($pageLabel + 1);

			$blob = "The last $pageSize Tower Attacks (page $pageLabel)\n\n";
			$blob .= $this->text->makeHeaderLinks($links) . "\n\n";

			foreach ($data as $row) {
				$timeString = $this->util->unixtimeToReadable(time() - $row->time);
				$blob .= "Time: " . $this->util->date($row->time) . " (<highlight>$timeString<end> ago)\n";
				if ($row->att_faction == '') {
					$att_faction = "unknown";
				} else {
					$att_faction = strtolower($row->att_faction);
				}

				if ($row->def_faction == '') {
					$def_faction = "unknown";
				} else {
					$def_faction = strtolower($row->def_faction);
				}

				if ($row->att_profession == 'Unknown') {
					$blob .= "Attacker: <{$att_faction}>{$row->att_player}<end> ({$row->att_faction})\n";
				} elseif ($row->att_guild_name == '') {
					$blob .= "Attacker: <{$att_faction}>{$row->att_player}<end> ({$row->att_level}/<green>{$row->att_ai_level}<end> {$row->att_profession}) ({$row->att_faction})\n";
				} else {
					$blob .= "Attacker: {$row->att_player} ({$row->att_level}/<green>{$row->att_ai_level}<end> {$row->att_profession}) <{$att_faction}>{$row->att_guild_name}<end> ({$row->att_faction})\n";
				}

				$base = $this->text->makeChatcmd("{$row->short_name} {$row->site_number}", "/tell <myname> lc {$row->short_name} {$row->site_number}");
				$base .= " ({$row->min_ql}-{$row->max_ql})";

				$blob .= "Defender: <{$def_faction}>{$row->def_guild_name}<end> ({$row->def_faction})\n";
				$blob .= "Site: $base\n\n";
			}
			$msg = $this->text->makeBlob("Tower Attacks", $blob);
		}

		$sendto->reply($msg);
	}

	protected function victoryCommandHandler(int $pageLabel, array $search, string $cmd, CommandReply $sendto): void {
		if ($pageLabel < 1) {
			$msg = "You must choose a page number greater than 0";
			$sendto->reply($msg);
			return;
		}

		$pageSize = $this->settingManager->getInt('tower_page_size');
		$startRow = ($pageLabel - 1) * $pageSize;

		$where = array_shift($search);

		$sql = "SELECT *, v.time AS victory_time, a.time AS attack_time ".
			"FROM tower_victory_<myname> v ".
			"LEFT JOIN tower_attack_<myname> a ON (v.attack_id = a.id) ".
			"LEFT JOIN playfields p ON (a.playfield_id = p.id) ".
			"LEFT JOIN tower_site s ON (a.playfield_id = s.playfield_id AND a.site_number = s.site_number) ".
			"{$where} ".
			"ORDER BY `victory_time` DESC ".
			"LIMIT ?, ?";
		$search = [...$search, $startRow, $pageSize];
		/** @var TowerVictory[] */
		$data = $this->db->fetchAll(TowerVictory::class, $sql, ...$search);
		if (count($data) == 0) {
			$msg = "No Tower results found.";
		} else {
			$links = [];
			if ($pageLabel > 1) {
				$links['Previous Page'] = '/tell <myname> victory ' . ($pageLabel - 1);
			}
			$links['Next Page'] = "/tell <myname> victory {$cmd}" . ($pageLabel + 1);

			$blob = "The last $pageSize Tower Results (page $pageLabel)\n\n";
			$blob .= $this->text->makeHeaderLinks($links) . "\n\n";
			foreach ($data as $row) {
				$timeString = $this->util->unixtimeToReadable(time() - $row->victory_time);
				$blob .= "Time: " . $this->util->date($row->victory_time) . " (<highlight>$timeString<end> ago)\n";

				if (!$win_side = strtolower($row->win_faction)) {
					$win_side = "unknown";
				}
				if (!$lose_side = strtolower($row->lose_faction)) {
					$lose_side = "unknown";
				}

				if ($row->playfield_id != '' && $row->site_number != '') {
					$base = $this->text->makeChatcmd("{$row->short_name} {$row->site_number}", "/tell <myname> lc {$row->short_name} {$row->site_number}");
					$base .= " ({$row->min_ql}-{$row->max_ql})";
				} else {
					$base = "Unknown";
				}

				$blob .= "Winner: <{$win_side}>{$row->win_guild_name}<end> (".ucfirst($win_side).")\n";
				$blob .= "Loser: <{$lose_side}>{$row->lose_guild_name}<end> (".ucfirst($lose_side).")\n";
				$blob .= "Site: $base\n\n";
			}
			$msg = $this->text->makeBlob("Tower Victories", $blob);
		}

		$sendto->reply($msg);
	}

	public function getTowerInfo(int $playfieldID, int $siteNumber): ?TowerSite {
		$sql = "SELECT * ".
			"FROM tower_site t ".
			"WHERE `playfield_id` = ?  AND `site_number` = ? ".
			"LIMIT 1";

		return $this->db->fetch(TowerSite::class, $sql, $playfieldID, $siteNumber);
	}

	protected function getClosestSite(int $playfieldID, int $xCoords, int $yCoords): ?TowerSite {
		$sql = "SELECT *, ".
				"((x_distance * x_distance) + (y_distance * y_distance)) radius ".
			"FROM ".
				"( ".
					"SELECT *, ".
						"(x_coord - {$xCoords}) AS x_distance, ".
						"(y_coord - {$yCoords}) AS y_distance ".
					"FROM tower_site ".
					"WHERE playfield_id = ? ".
				") t ".
			"ORDER BY radius ASC ".
			"LIMIT 1";

		return $this->db->fetch(TowerSite::class, $sql, $playfieldID);
	}

	protected function getLastAttack(string $attackFaction, string $attackOrgName, string $defendFaction, string $defendOrgName, int $playfieldID): ?TowerAttack {
		$time = time() - (7 * 3600);

		$sql = "SELECT * ".
			"FROM tower_attack_<myname> ".
			"WHERE `att_guild_name` = ? ".
				"AND `att_faction` = ? ".
				"AND `def_guild_name` = ? ".
				"AND `def_faction` = ? ".
				"AND `playfield_id` = ? ".
				"AND `time` >= ? ".
			"ORDER BY `time` DESC ".
			"LIMIT 1";

		return $this->db->fetch(
			TowerAttack::class,
			$sql,
			$attackOrgName,
			$attackFaction,
			$defendOrgName,
			$defendFaction,
			$playfieldID,
			$time
		);
	}

	protected function recordAttack(Player $whois, Attack $attack, TowerSite $closestSite): int {
		$event = new TowerAttackEvent();
		$event->attacker = $whois;
		$event->defender = (object)["org" => $attack->defGuild, "faction" => $attack->defSide];
		$event->site = $closestSite;
		$event->type = "tower(attack)";
		$sql = "INSERT INTO `tower_attack_<myname>` ( ".
				"`time`, `att_guild_name`, `att_faction`, `att_player`, ".
				"`att_level`, `att_ai_level`, `att_profession`, `def_guild_name`, ".
				"`def_faction`, `playfield_id`, `site_number`, `x_coords`, ".
				"`y_coords` ".
			") VALUES ( ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

		$result = $this->db->exec(
			$sql,
			time(),
			$whois->guild ?? null,
			$whois->faction ?? null,
			$whois->name ?? null,
			$whois->level ?? null,
			$whois->ai_level ?? null,
			$whois->profession ?? null,
			$attack->defGuild,
			$attack->defSide,
			$closestSite->playfield_id,
			$closestSite->site_number,
			$attack->xCoords,
			$attack->yCoords
		);
		$this->eventManager->fireEvent($event);
		return $result;
	}

	protected function getLastVictory(int $playfieldID, int $siteNumber): ?TowerAttackAndVictory {
		$sql = "SELECT * ".
			"FROM tower_victory_<myname> v ".
				"JOIN tower_attack_<myname> a ON (v.attack_id = a.id) ".
			"WHERE a.`playfield_id` = ?  AND a.`site_number` >= ? ".
			"ORDER BY v.`time` DESC ".
			"LIMIT 1";

		return $this->db->fetch(TowerAttackAndVictory::class, $sql, $playfieldID, $siteNumber);
	}

	protected function recordVictory(TowerAttack $attack): int {
		$sql = "INSERT INTO tower_victory_<myname> ( ".
				"`time`, ".
				"`win_guild_name`, ".
				"`win_faction`, ".
				"`lose_guild_name`, ".
				"`lose_faction`, ".
				"`attack_id` ".
			") VALUES ( ?, ?, ?, ?, ?, ?)";

		return $this->db->exec(
			$sql,
			time(),
			$attack->att_guild_name ?? null,
			$attack->att_faction ?? null,
			$attack->def_guild_name ?? null,
			$attack->def_faction ?? null,
			$attack->id ?? null
		);
	}

	protected function addScoutSite(int $playfieldID, int $siteNumber, int $closingTime, int $ctQL, string $faction, string $orgName, string $scoutedBy): int {
		$this->db->exec(
			"DELETE FROM scout_info WHERE `playfield_id` = ? AND `site_number` = ?",
			$playfieldID,
			$siteNumber
		);

		$sql = "INSERT INTO scout_info ( ".
				"`playfield_id`, `site_number`, `scouted_on`, `scouted_by`, ".
				"`ct_ql`, `guild_name`, `faction`, `close_time` ".
			") VALUES ( ?, ?, ?, ?, ?, ?, ?, ?)";

		$numrows = $this->db->exec(
			$sql,
			$playfieldID,
			$siteNumber,
			time(),
			$scoutedBy,
			$ctQL,
			$orgName,
			$faction,
			$closingTime
		);

		return $numrows;
	}

	protected function remScoutSite(int $playfield_id, int $site_number): int {
		$sql = "DELETE FROM scout_info WHERE `playfield_id` = ? AND `site_number` = ?";

		return $this->db->exec($sql, $playfield_id, $site_number);
	}

	protected function checkGuildName(string $guildName): bool {
		$sql = "SELECT * FROM tower_attack_<myname> WHERE `att_guild_name` LIKE ? OR `def_guild_name` LIKE ? LIMIT 1";

		$data = $this->db->fetchAll(TowerAttack::class, $sql, $guildName, $guildName);
		if (count($data) === 0) {
			return false;
		}
		return true;
	}

	/**
	 * @return OrgInPenalty[]
	 */
	protected function getSitesInPenalty(int $time): array {
		$sql = "SELECT att_guild_name, att_faction, ".
				"MAX(IFNULL(t2.time, t1.time)) AS penalty_time ".
			"FROM tower_attack_<myname> t1 ".
			"LEFT JOIN tower_victory_<myname> t2 ON t1.id = t2.attack_id ".
			"WHERE att_guild_name != '' ".
				"AND COALESCE(t2.time, t1.time) > ? ".
			"GROUP BY att_guild_name, att_faction ".
			"ORDER BY att_faction ASC, penalty_time DESC";
		return $this->db->fetchAll(OrgInPenalty::class, $sql, $time);
	}
	
	protected function getGasLevel(int $closeTime): GasInfo {
		$currentTime = time() % 86400;

		$site = new GasInfo();
		$site->current_time = $currentTime;
		$site->close_time = $closeTime;

		if ($closeTime < $currentTime) {
			$closeTime += 86400;
		}

		$timeUntilCloseTime = $closeTime - $currentTime;
		$site->time_until_close_time = $timeUntilCloseTime;

		if ($timeUntilCloseTime < 3600 * 1) {
			$site->gas_change = $timeUntilCloseTime;
			$site->gas_level = '5%';
			$site->next_state = 'closes';
			$site->color = "<orange>";
		} elseif ($timeUntilCloseTime < 3600 * 6) {
			$site->gas_change = $timeUntilCloseTime;
			$site->gas_level = '25%';
			$site->next_state = 'closes';
			$site->color = "<green>";
		} else {
			$site->gas_change = $timeUntilCloseTime - (3600 * 6);
			$site->gas_level = '75%';
			$site->next_state = 'opens';
			$site->color = "<red>";
		}

		return $site;
	}
	
	protected function formatSiteInfo(SiteInfo $row): string {
		$waypointLink = $this->text->makeChatcmd($row->x_coord . "x" . $row->y_coord, "/waypoint {$row->x_coord} {$row->y_coord} {$row->playfield_id}");
		$attacksLink = $this->text->makeChatcmd("Recent attacks", "/tell <myname> attacks {$row->short_name} {$row->site_number}");
		$victoryLink = $this->text->makeChatcmd("Recent victories", "/tell <myname> victory {$row->short_name} {$row->site_number}");

		$blob = "Short name: <highlight>{$row->short_name} {$row->site_number}<end>\n";
		$blob .= "Long name: <highlight>{$row->site_name}, {$row->long_name}<end>\n";
		$blob .= "Level range: <highlight>{$row->min_ql}-{$row->max_ql}<end>\n";
		$blob .= "Center coordinates: $waypointLink\n";
		$blob .= $attacksLink . "\n";
		$blob .= $victoryLink;
		
		return $blob;
	}
	
	public function getFaction(string $input): string {
		$faction = ucfirst(strtolower($input));
		if ($faction == "Neut") {
			$faction = "Neutral";
		}
		return $faction;
	}
}
