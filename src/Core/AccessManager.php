<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use Nadybot\Core\DBSchema\Member;
use Nadybot\Modules\BASIC_CHAT_MODULE\ChatLeaderController;
use Nadybot\Modules\RAID_MODULE\RaidRankController;
use Nadybot\Core\Modules\ALTS\AltsController;

/**
 * The AccessLevel class provides functionality for checking a player's access level.
 *
 * @Instance
 */
class AccessManager {
	/**
	 * @var array<string,int> $ACCESS_LEVELS
	 */
	private static array $ACCESS_LEVELS = [
		'none'          => 0,
		'superadmin'    => 1,
		'admin'         => 2,
		'mod'           => 3,
		'guild'         => 4,
		'raid_admin_3'  => 5,
		'raid_admin_2'  => 6,
		'raid_admin_1'  => 7,
		'raid_leader_3' => 8,
		'raid_leader_2' => 9,
		'raid_leader_1' => 10,
		// 'raid_level_3'  => 11,
		// 'raid_level_2'  => 12,
		// 'raid_level_1'  => 13,
		'member'        => 14,
		'rl'            => 15,
		'all'           => 16,
	];

	/** @Inject */
	public DB $db;

	/** @Inject */
	public SettingObject $setting;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public AdminManager $adminManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Inject */
	public AltsController $altsController;

	/** @Inject */
	public ChatLeaderController $chatLeaderController;

	/** @Inject */
	public RaidRankController $raidRankController;

	/**
	 * This method checks if given $sender has at least $accessLevel rights.
	 *
	 * Normally, you don't have to worry about access levels in the bot.
	 * The bot will automatically restrict access to commands based on the
	 * access level setting on the command and the access level of the user
	 * trying to access the command.
	 *
	 * However, there are some cases where you may need this functionality.
	 * For instance, you may have a command that displays the names of the last
	 * ten people to send a tell to the bot.  You may wish to display a "ban"
	 * link when a moderator or higher uses that command.
	 *
	 * To check if a character named 'Tyrence' has moderator access,
	 * you would do:
	 *
	 * <code>
	 * if ($this->accessManager->checkAccess("Tyrence", "moderator")) {
	 *    // Tyrence has [at least] moderator access level
	 * } else {
	 *    // Tyrence does not have moderator access level
	 * }
	 * </code>
	 *
	 * Note that this will return true if 'Tyrence' is a moderator on your
	 * bot, but also if he is anything higher, such as administrator, or superadmin.
	 *
	 * This command will check the character's "effective" access level, meaning
	 * the higher of it's own access level and that of it's main, if it has a main
	 * and if it has been validated as an alt.
	 */
	public function checkAccess(string $sender, string $accessLevel): bool {
		$this->logger->log("DEBUG", "Checking access level '$accessLevel' against character '$sender'");

		$returnVal = $this->checkSingleAccess($sender, $accessLevel);

		if ($returnVal === false) {
			// if current character doesn't have access,
			// and if the current character is not a main character,
			// and if the current character is validated,
			// then check access against the main character,
			// otherwise just return the result
			$altInfo = $this->altsController->getAltInfo($sender);
			if ($sender !== $altInfo->main && $altInfo->isValidated($sender)) {
				$this->logger->log("DEBUG", "Checking access level '$accessLevel' against the main of '$sender' which is '$altInfo->main'");
				$returnVal = $this->checkSingleAccess($altInfo->main, $accessLevel);
			}
		}

		return $returnVal;
	}

	/**
	 * This method checks if given $sender has at least $accessLevel rights.
	 *
	 * This is the same checkAccess() but doesn't check alt
	 */
	public function checkSingleAccess(string $sender, string $accessLevel): bool {
		$sender = ucfirst(strtolower($sender));

		$charAccessLevel = $this->getSingleAccessLevel($sender);
		return ($this->compareAccessLevels($charAccessLevel, $accessLevel) >= 0);
	}

	/**
	 * Turn the short accesslevel (rl, mod, admin) into the long version
	 */
	public function getDisplayName(string $accessLevel): string {
		$displayName = $this->getAccessLevel($accessLevel);
		switch ($displayName) {
			case "rl":
				return "raidleader";
			case "mod":
				return "moderator";
			case "admin":
				return "administrator";
		}
		if (substr($displayName, 0, 5) === "raid_") {
			$setName = $this->settingManager->getString("name_{$displayName}");
			if ($setName !== null) {
				return $setName;
			}
		}

		return $displayName;
	}

	/**
	 * Returns the access level of $sender, ignoring guild admin and inheriting access level from main
	 */
	public function getSingleAccessLevel(string $sender): string {
		if ($this->chatBot->vars["SuperAdmin"] == $sender) {
			return "superadmin";
		}
		if (isset($this->adminManager->admins[$sender])) {
			$level = $this->adminManager->admins[$sender]["level"];
			if ($level >= 4) {
				return "admin";
			}
			if ($level >= 3) {
				return "mod";
			}
		}
		if (isset($this->raidRankController->ranks[$sender])) {
			$rank = $this->raidRankController->ranks[$sender]->rank;
			if ($rank >= 7) {
				return "raid_admin_" . ($rank-6);
			}
			if ($rank >= 4) {
				return "raid_leader_" . ($rank-3);
			}
			return "raid_level_{$rank}";
		}
		if ($this->chatLeaderController !== null && $this->chatLeaderController->getLeader() == $sender) {
			return "rl";
		}
		if (isset($this->chatBot->guildmembers[$sender])) {
			return "guild";
		}

		$sql = "SELECT name FROM members_<myname> WHERE `name` = ?";
		$row = $this->db->fetch(Member::class, $sql, $sender);
		if ($row !== null) {
			return "member";
		}
		return "all";
	}

	/**
	 * Returns the access level of $sender, accounting for guild admin and inheriting access level from main
	 */
	public function getAccessLevelForCharacter(string $sender): string {
		$sender = ucfirst(strtolower($sender));

		$accessLevel = $this->getSingleAccessLevel($sender);

		$altInfo = $this->altsController->getAltInfo($sender);
		if ($sender != $altInfo->main && $altInfo->isValidated($sender)) {
			$mainAccessLevel = $this->getSingleAccessLevel($altInfo->main);
			if ($this->compareAccessLevels($mainAccessLevel, $accessLevel) > 0) {
				$accessLevel = $mainAccessLevel;
			}
		}

		return $accessLevel;
	}

	/**
	 * Compare 2 access levels
	 *
	 * @return int 1 if $accessLevel1 is a greater access level than $accessLevel2,
	 *             -1 if $accessLevel1 is a lesser access level than $accessLevel2,
	 *             0 if the access levels are equal.
	 */
	public function compareAccessLevels(string $accessLevel1, string $accessLevel2): int {
		$accessLevel1 = $this->getAccessLevel($accessLevel1);
		$accessLevel2 = $this->getAccessLevel($accessLevel2);

		$accessLevels = $this->getAccessLevels();

		return $accessLevels[$accessLevel2] <=> $accessLevels[$accessLevel1];
	}

	/**
	 * Compare the access levels of 2 characters, taking alts into account
	 *
	 * @return int 1 if the access level of $char1 is greater than the access level of $char2,
	 *             -1 if the access level of $char1 is less than the access level of $char2,
	 *             0 if the access levels of $char1 and $char2 are equal.
	 */
	public function compareCharacterAccessLevels(string $char1, string $char2): int {
		$char1 = ucfirst(strtolower($char1));
		$char2 = ucfirst(strtolower($char2));

		$char1AccessLevel = $this->getAccessLevelForCharacter($char1);
		$char2AccessLevel = $this->getAccessLevelForCharacter($char2);

		return $this->compareAccessLevels($char1AccessLevel, $char2AccessLevel);
	}

	/**
	 * Get the short version of the accesslevel, e.g. raidleader => rl
	 * @throws Exception
	 */
	public function getAccessLevel(string $accessLevel): string {
		$accessLevel = strtolower($accessLevel);
		switch ($accessLevel) {
			case "raidleader":
				$accessLevel = "rl";
				break;
			case "moderator":
				$accessLevel = "mod";
				break;
			case "administrator":
				$accessLevel = "admin";
				break;
		}

		$accessLevels = $this->getAccessLevels();
		if (isset($accessLevels[$accessLevel])) {
			return strtolower($accessLevel);
		}
		throw new Exception("Invalid access level '$accessLevel'.");
	}

	/**
	 * Return all allowed and known access levels
	 *
	 * @return int[] All access levels with the name as key and the number as value
	 */
	public function getAccessLevels(): array {
		return self::$ACCESS_LEVELS;
	}
}
