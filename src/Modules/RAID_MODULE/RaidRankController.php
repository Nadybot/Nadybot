<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\{
	AccessManager,
	AdminManager,
	BuddylistManager,
	CommandAlias,
	CommandReply,
	DB,
	LoggerWrapper,
	Nadybot,
	SettingManager,
	Text,
};
use Nadybot\Core\Modules\ALTS\AltEvent;
use Nadybot\Core\Modules\ALTS\AltsController;

/**
 * @Instance
 * Commands this controller contains:
 * @DefineCommand(
 *     command       = 'raidadmin',
 *     accessLevel   = 'raid_admin_2',
 *     description   = 'Promote/demote someone to/from raid admin',
 *     help          = 'raidranks.txt'
 * )
 * @DefineCommand(
 *     command       = 'raidleader',
 *     accessLevel   = 'raid_admin_1',
 *     description   = 'Promote/demote someone to/from raid leader',
 *     help          = 'raidranks.txt'
 * )
 *	@DefineCommand(
 *		command       = 'leaderlist',
 *		accessLevel   = 'all',
 *		description   = 'Shows the list of raid leaders and admins',
 *		help          = 'leaderlist.txt',
 *		alias         = 'leaders',
 *		defaultStatus = '1'
 *	)
 */
class RaidRankController {
	public string $moduleName;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public AdminManager $adminManager;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public AltsController $altsController;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public BuddylistManager $buddylistManager;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @var array<string,RaidRank> */
	public array $ranks = [];

	/**
	 * @Setup
	 * @todo: Add support for the raid levels
	 */
	public function setup(): void {
		/**
		$this->settingManager->add(
			$this->moduleName,
			'name_raid_level_1',
			'Name of the raid points rank 1',
			'edit',
			'text',
			'Experienced Raider'
		);
		$this->settingManager->add(
			$this->moduleName,
			'name_raid_level_2',
			'Name of the raid points rank 2',
			'edit',
			'text',
			'Veteran Raider'
		);
		$this->settingManager->add(
			$this->moduleName,
			'name_raid_level_3',
			'Name of the raid points rank 3',
			'edit',
			'text',
			'Elite Raider'
		);
		*/

		$this->settingManager->add(
			$this->moduleName,
			'raid_rank_promotion_distance',
			'Number of raid ranks below your own you can manage',
			'edit',
			'number',
			'1'
		);
		$this->settingManager->add(
			$this->moduleName,
			'name_raid_leader_1',
			'Name of the raid leader rank 1',
			'edit',
			'text',
			'Apprentice Leader'
		);
		$this->settingManager->add(
			$this->moduleName,
			'name_raid_leader_2',
			'Name of the raid leader rank 2',
			'edit',
			'text',
			'Leader'
		);
		$this->settingManager->add(
			$this->moduleName,
			'name_raid_leader_3',
			'Name of the raid leader rank 3',
			'edit',
			'text',
			'Veteran Leader'
		);

		$this->settingManager->add(
			$this->moduleName,
			'name_raid_admin_1',
			'Name of the raid admin rank 1',
			'edit',
			'text',
			'Apprentice Raid Admin'
		);
		$this->settingManager->add(
			$this->moduleName,
			'name_raid_admin_2',
			'Name of the raid admin rank 2',
			'edit',
			'text',
			'Raid Admin'
		);
		$this->settingManager->add(
			$this->moduleName,
			'name_raid_admin_3',
			'Name of the raid admin rank 3',
			'edit',
			'text',
			'Veteran Raid Admin'
		);
		$this->commandAlias->register($this->moduleName, "raidadmin", "raid admin");
		$this->commandAlias->register($this->moduleName, "raidleader", "raid leader");
	}

	/**
	 * @Event("connect")
	 * @Description("Add raid leader and admins to the buddy list")
	 * @DefaultStatus("1")
	 */
	public function checkRaidRanksEvent(): void {
		/** @var RaidRank[] $data */
		$data = $this->db->fetchAll(RaidRank::class, "SELECT * FROM raid_rank_<myname>");
		foreach ($data as $row) {
			$this->buddylistManager->add($row->name, 'raidrank');
		}
	}

	/**
	 * Load the raid leaders, admins and veterans from the database into $ranks
	 * @Setup
	 */
	public function uploadRaidRanks(): void {
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS raid_rank_<myname> (".
				"`name` VARCHAR(25) NOT NULL PRIMARY KEY, ".
				"`rank` INT NOT NULL".
			")"
		);

		/** @var RaidRank[] $data */
		$data = $this->db->fetchAll(RaidRank::class, "SELECT * FROM raid_rank_<myname>");
		foreach ($data as $row) {
			$this->ranks[$row->name] = $row;
		}
	}

	/**
	 * Demote someone's special raid rank
	 */
	public function removeFromLists(string $who): void {
		unset($this->ranks[$who]);
		$this->db->exec("DELETE FROM raid_rank_<myname> WHERE `name` = ?", $who);
		$this->buddylistManager->remove($who, 'raidrank');
	}

	/**
	 * Set the raid rank of a user
	 *
	 * @return string Either "demoted" or "promoted"
	 */
	public function addToLists(string $who, int $rank): string {
		$action = 'promoted';
		if (isset($this->ranks[$who])) {
			$this->db->exec("UPDATE raid_rank_<myname> SET `rank` = ? WHERE `name` = ?", $rank, $who);
			if ($this->ranks[$who]->rank > $rank) {
				$action = "demoted";
			}
		} else {
			$this->db->exec("INSERT INTO raid_rank_<myname> (`rank`, `name`) VALUES (?, ?)", $rank, $who);
		}

		$this->ranks[$who]->rank = $rank;
		$this->buddylistManager->add($who, 'raidrank');

		return $action;
	}

	/**
	 * Check if a user $who has raid rank $rank
	 */
	public function checkExisting(string $who, int $rank): bool {
		return ($this->ranks[$who]->rank??-1) === $rank;
	}

	/**
	 * Chheck if $actor's access level is higher than $actee's
	 */
	public function checkAccessLevel(string $actor, string $actee): bool {
		$senderAccessLevel = $this->accessManager->getAccessLevelForCharacter($actor);
		$whoAccessLevel = $this->accessManager->getSingleAccessLevel($actee);
		return $this->accessManager->compareAccessLevels($whoAccessLevel, $senderAccessLevel) < 0;
	}

	/** Check if $sender can change $who's raid rank (to $newRank or in general) */
	public function canChangeRaidRank(string $sender, string $who, ?string $newRank=null, CommandReply $sendto): bool {
		if (!$this->checkAccessLevel($sender, $who, $sendto)) {
			$sendto->reply("You must have a higher access level than <highlight>$who<end> in order to change their access level.");
			return false;
		}
		$reqDistance = $this->settingManager->getInt('raid_rank_promotion_distance');
		$accessLevels = $this->accessManager->getAccessLevels();
		$senderAccessLevel = $this->accessManager->getAccessLevel(
			$this->accessManager->getAccessLevelForCharacter($sender)
		);
		$oldAccessLevel = $this->accessManager->getAccessLevel(
			$this->accessManager->getAccessLevelForCharacter($who)
		);
		$newAccessLevel = $oldAccessLevel;
		if (isset($newRank)) {
			$newAccessLevel = $this->accessManager->getAccessLevel($newRank);
		}
		$numSenderAccessLevel = $accessLevels[$senderAccessLevel];
		$numOldAccessLevel = $accessLevels[$oldAccessLevel];
		$numSettableAL = $numSenderAccessLevel + $reqDistance;
		$numNewAccessLevel = $accessLevels[$newAccessLevel];
		if ($numNewAccessLevel < $numSettableAL || $numOldAccessLevel < $numSettableAL) {
			$reverseALs = array_flip($accessLevels);
			$nameSettableAL = $this->accessManager->getDisplayName($reverseALs[$numSettableAL]);
			$sendto->reply("You can only change raid ranks up to and including {$nameSettableAL}.");
			return false;
		}
		return true;
	}

	public function add(string $who, string $sender, CommandReply $sendto, int $rank, string $rankName, string $alName): bool {
		if ($this->chatBot->get_uid($who) == null) {
			$sendto->reply("Character <highlight>$who<end> does not exist.");
			return false;
		}

		if ($this->checkExisting($who, $rank)) {
			$sendto->reply(
				"<highlight>$who<end> is already $rankName. ".
				"To promote/demote to a different rank, add the ".
				"rank number (1, 2 or 3) to the command."
			);
			return false;
		}

		if (!$this->canChangeRaidRank($sender, $who, $alName, $sendto)) {
			return false;
		}

		$altInfo = $this->altsController->getAltInfo($who);
		if ($altInfo->main !== $who) {
			$msg = "<red>WARNING<end>: $who is not a main. This command did NOT affect $who's access level and no action was performed.";
			$sendto->reply($msg);
			return false;
		}

		$action = $this->addToLists($who, $rank);

		$sendto->reply(
			"<highlight>{$who}<end> has been <highlight>{$action}<end> ".
			"to {$rankName}."
		);
		$this->chatBot->sendTell(
			"You have been <highlight>{$action}<end> to {$rankName} ".
			"by <highlight>$sender<end>.",
			$who
		);
		return true;
	}

	public function remove(string $who, string $sender, CommandReply $sendto, array $ranks, string $rankName): bool {
		if (!in_array($this->ranks[$who]->rank ?? null, $ranks)) {
			$sendto->reply("<highlight>$who<end> is not $rankName.");
			return false;
		}

		if (!$this->canChangeRaidRank($sender, $who, null, $sendto)) {
			return false;
		}

		$this->removeFromLists($who);

		$altInfo = $this->altsController->getAltInfo($who);
		if ($altInfo->main !== $who) {
			$msg = "<red>WARNING<end>: $who is not a main.  This command did NOT affect $who's access level.";
			$sendto->reply($msg);
		}

		$sendto->reply("<highlight>$who<end> has been removed as $rankName.");
		$this->chatBot->sendTell("You have been removed as $rankName by <highlight>$sender<end>.", $who);
		return true;
	}

	/**
	 * @HandlesCommand("raidadmin")
	 * @Matches("/^raidadmin (?:add|promote) ([^ ]+)$/i")
	 * @Matches("/^raidadmin (?:add|promote) ([^ ]+) (\d)$/i")
	 */
	public function raidAdminAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$who = ucfirst(strtolower($args[1]));
		$rank = 1;
		if (count($args) > 2) {
			$rank = (int)$args[2];
			if ($rank < 1 || $rank > 3) {
				$sendto->reply("The admin rank must be a number between 1 and 3");
				return;
			}
		}
		$rankName = $this->settingManager->getString("name_raid_admin_$rank");

		$this->add($who, $sender, $sendto, $rank+6, $rankName, "raid_admin_$rank");
	}

	/**
	 * @HandlesCommand("raidadmin")
	 * @Matches("/^raidadmin (?:rem|del|rm|demote) (.+)$/i")
	 */
	public function raidAdminRemoveCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$who = ucfirst(strtolower($args[1]));
		$rank = 'a raid admin';

		$this->remove($who, $sender, $sendto, [7, 8, 9], $rank);
	}

	/**
	 * @HandlesCommand("raidleader")
	 * @Matches("/^raidleader (?:add|promote) ([^ ]+)$/i")
	 * @Matches("/^raidleader (?:add|promote) ([^ ]+) (\d)$/i")
	 */
	public function raidLeaderAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$who = ucfirst(strtolower($args[1]));
		$rank = 1;
		if (count($args) > 2) {
			$rank = (int)$args[2];
			if ($rank < 1 | $rank > 3) {
				$sendto->reply("The leader rank must be a number between 1 and 3");
				return;
			}
		}
		$rankName = $this->settingManager->getString("name_raid_leader_$rank");

		$this->add($who, $sender, $sendto, $rank+3, $rankName, "raid_leader_$rank");
	}

	/**
	 * @HandlesCommand("raidleader")
	 * @Matches("/^raidleader (?:rem|del|rm|demote) (.+)$/i")
	 */
	public function raidLeaderRemoveCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$who = ucfirst(strtolower($args[1]));
		$rank = 'a raid leader';

		$this->remove($who, $sender, $sendto, [4, 5, 6], $rank);
	}

	/**
	 * @HandlesCommand("leaderlist")
	 * @Matches("/^leaderlist$/i")
	 * @Matches("/^leaderlist (all)$/i")
	 */
	public function leaderlistCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (count($args) > 1) {
			$showOfflineAlts = true;
		} else {
			$showOfflineAlts = false;
		}

		$blob = "";
		$admins = [];
		foreach ($this->ranks as $who => $data) {
			if ($data->rank >= 7) {
				if ($who !== "") {
					$admins []= "<tab>$who".
						$this->getOnlineStatus($who) . "\n".
						$this->getAltLeaderInfo($who, $showOfflineAlts);
				}
			}
		}
		if (count($admins)) {
			$blob .= "<header2>Raid admins<end>\n".
				join("", $admins) . "\n";
		}

		$leaders = [];
		foreach ($this->ranks as $who => $data) {
			if ($data->rank >= 4 && $data->rank < 7) {
				if ($who !== "") {
					$leaders []= "<tab>$who".
						$this->getOnlineStatus($who) . "\n".
						$this->getAltLeaderInfo($who, $showOfflineAlts);
				}
			}
		}
		if (count($leaders)) {
			$blob .= "<header2>Raid leaders<end>\n".
				join("", $leaders) . "\n";
		}

		$link = $this->text->makeBlob('Raid leaders/admins', $blob);
		$sendto->reply($link);
	}

	/**
	 * Get the string of the online status
	 * @param string $who Playername
	 * @return string " (<green>online<end>)" and so on
	 */
	private function getOnlineStatus(string $who): string {
		if ($this->buddylistManager->isOnline($who) && isset($this->chatBot->chatlist[$who])) {
			return " (<green>Online and in chat<end>)";
		} elseif ($this->buddylistManager->isOnline($who)) {
			return " (<green>Online<end>)";
		} else {
			return " (<red>Offline<end>)";
		}
	}

	private function getAltLeaderInfo(string $who, bool $showOfflineAlts): string {
		$blob = '';
		$altInfo = $this->altsController->getAltInfo($who);
		if ($altInfo->main === $who) {
			foreach ($altInfo->getAllValidatedAlts() as $alt) {
				if ($showOfflineAlts || $this->buddylistManager->isOnline($alt)) {
					$blob .= "<tab><tab>$alt" . $this->getOnlineStatus($alt) . "\n";
				}
			}
		}
		return $blob;
	}

	/**
	 * @Event("alt(newmain)")
	 * @Description("Move raid rank to new main")
	 */
	public function moveRaidRanks(AltEvent $event): void {
		$oldRank = $this->ranks[$event->alt] ?? null;
		if ($oldRank === null) {
			return;
		}
		$this->removeFromLists($event->alt);
		$this->addToLists($event->main, $oldRank->rank);
		$this->logger->log('INFO', "Moved raid rank {$oldRank->rank} from {$event->alt} to {$event->main}.");
	}
}
