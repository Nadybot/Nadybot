<?php

namespace Budabot\Core;

use Exception;

/**
 * The AccessLevel class provides functionality for checking a player's access level.
 *
 * @Instance
 */
class AccessManager {
	/**
	 * @var array[string]int $ACCESS_LEVELS
	 */
	private static $ACCESS_LEVELS = array(
		'none'       => 0,
		'superadmin' => 1,
		'admin'      => 2,
		'mod'        => 3,
		'guild'      => 4,
		'member'     => 5,
		'rl'         => 6,
		'all'        => 7,
	);

	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;

	/**
	 * @var \Budabot\Core\SettingObject $setting
	 * @Inject
	 */
	public $setting;

	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;

	/**
	 * @var \Budabot\Core\AdminManager $adminManager
	 * @Inject
	 */
	public $adminManager;

	/**
	 * @var \Budabot\Core\LoggerWrapper $logger
	 * @Logger
	 */
	public $logger;

	/**
	 * @var \Budabot\Core\Modules\ALTS\AltsController $altsController
	 * @Inject
	 */
	public $altsController;

	/**
	 * @var \Budabot\Modules\BASIC_CHAT_MODULE\ChatLeaderController $chatLeaderController
	 * @Inject
	 */
	public $chatLeaderController;

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
	 *
	 * @param string $sender The name of the person you want to check access on
	 * @param string $accessLevel Can be one of: superadmin, admininistrator, moderator, guild, member, raidleader, all
	 * @return bool true if $sender has at least $accessLevel, false otherwise
	 */
	public function checkAccess($sender, $accessLevel) {
		$this->logger->log("DEBUG", "Checking access level '$accessLevel' against character '$sender'");

		$returnVal = $this->checkSingleAccess($sender, $accessLevel);

		if ($returnVal === false) {
			// if current character doesn't have access,
			// and if the current character is not a main character,
			// and if the current character is validated,
			// then check access against the main character,
			// otherwise just return the result
			$altInfo = $this->altsController->getAltInfo($sender);
			if ($sender != $altInfo->main && $altInfo->isValidated($sender)) {
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
	 *
	 * @param string $sender The name of the person you want to check access on
	 * @param string $accessLevel Can be one of: superadmin, admininistrator, moderator, guild, member, raidleader, all
	 * @return bool true if $sender has at least $accessLevel, false otherwise
	 */
	public function checkSingleAccess($sender, $accessLevel) {
		$sender = ucfirst(strtolower($sender));

		$charAccessLevel = $this->getSingleAccessLevel($sender);
		return ($this->compareAccessLevels($charAccessLevel, $accessLevel) >= 0);
	}

	/**
	 * Turn the short accesslevel (rl, mod, admin) into the long version
	 *
	 * @param string $accessLevel The short version
	 * @return string The long version
	 */
	public function getDisplayName($accessLevel) {
		$displayName = $this->getAccessLevel($accessLevel);
		switch ($displayName) {
			case "rl":
				$displayName = "raidleader";
				break;
			case "mod":
				$displayName = "moderator";
				break;
			case "admin":
				$displayName = "administrator";
				break;
		}

		return $displayName;
	}

	/**
	 * Returns the access level of $sender, ignoring guild admin and inheriting access level from main
	 *
	 * @param string $sender The name of the user to check
	 * @return string One of "superadmin", "admin", "mod", "r", "guild", "member" or "all"
	 */
	public function getSingleAccessLevel($sender) {
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
		if ($this->chatLeaderController !== null && $this->chatLeaderController->getLeader() == $sender) {
			return "rl";
		}
		if (isset($this->chatBot->guildmembers[$sender])) {
			return "guild";
		}

		$sql = "SELECT name FROM members_<myname> WHERE `name` = ?";
		$row = $this->db->queryRow($sql, $sender);
		if ($row !== null) {
			return "member";
		}
		return "all";
	}

	/**
	 * Returns the access level of $sender, accounting for guild admin and inheriting access level from main
	 *
	 * @param string $sender The name of the user to check
	 * @return string One of "superadmin", "admin", "mod", "r", "guild", "member" or "all"
	 */
	public function getAccessLevelForCharacter($sender) {
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
	 * Returns a positive number if $accessLevel1 is a greater access level than $accessLevel2,
	 * a negative number if $accessLevel1 is a lesser access level than $accessLevel2,
	 * and 0 if the access levels are equal.
	 *
	 * @param string $accessLevel1
	 * @param string $accessLevel2
	 * @return int 1 if $accessLevel1 is greater, -1 it $accessLevel1 is lesser and 0 if both are equal
	 */
	public function compareAccessLevels($accessLevel1, $accessLevel2) {
		$accessLevel1 = $this->getAccessLevel($accessLevel1);
		$accessLevel2 = $this->getAccessLevel($accessLevel2);

		$accessLevels = $this->getAccessLevels();

		return $accessLevels[$accessLevel2] - $accessLevels[$accessLevel1];
	}

	/**
	 * Compare the access levels of 2 characters
	 *
	 * Returns a positive number if the access level of $char1 is greater than the access level of $char2,
	 * a negative number if the access level of $char1 is less than the access level of $char2,
	 * and 0 if the access levels of $char1 and $char2 are equal.
	 *
	 * @param string $char1
	 * @param string $char2
	 * @return int 1 if access for $char1 is greater, -1 if lesser and 0 if equal to access of $char2
	 */
	public function compareCharacterAccessLevels($char1, $char2) {
		$char1 = ucfirst(strtolower($char1));
		$char2 = ucfirst(strtolower($char2));

		$char1AccessLevel = $this->getAccessLevelForCharacter($char1);
		$char2AccessLevel = $this->getAccessLevelForCharacter($char2);

		return $this->compareAccessLevels($char1AccessLevel, $char2AccessLevel);
	}

	/**
	 * Get the short version of the accesslevel, e.g. raidleaver => rl
	 *
	 * @param string $accessLevel The long access level
	 * @return string The short version
	 */
	public function getAccessLevel($accessLevel) {
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
		} else {
			throw new Exception("Invalid access level '$accessLevel'.");
		}
	}

	/**
	 * Return all allowed and known access levels
	 *
	 * @return int[] All access levels with the name as key and the number as value
	 */
	public function getAccessLevels() {
		return self::$ACCESS_LEVELS;
	}
}
