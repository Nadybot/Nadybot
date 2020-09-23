<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Nadybot\Core\{
	CommandReply,
	Event,
	Nadybot,
	SettingManager,
	Modules\PLAYER_LOOKUP\PlayerManager,
	DB,
	DBSchema\Alt,
	EventManager,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command       = 'alts',
 *		accessLevel   = 'member',
 *		description   = 'Alt character handling',
 *		help          = 'alts.txt'
 *	)
 *	@DefineCommand(
 *		command       = 'alts main (.+)',
 *		accessLevel   = 'member',
 *		description   = 'Add yourself as an alt to a main'
 *	)
 *	@DefineCommand(
 *		command       = 'altvalidate',
 *		accessLevel   = 'member',
 *		description   = 'Validate alts for admin privileges',
 *		help          = 'altvalidate.txt'
 *	)
 * @ProvidesEvent("alt(add)")
 * @ProvidesEvent("alt(del)")
 * @ProvidesEvent("alt(validate)")
 */
class AltsController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public DB $db;

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup() {
		$this->db->loadSQLFile($this->moduleName, 'alts');
		$this->settingManager->add(
			$this->moduleName,
			'alts_show_org',
			'Show the org in the altlist',
			'edit',
			'options',
			'1',
			'true;false',
			'1;0',
			'mod'
		);
		$this->settingManager->add(
			$this->moduleName,
			'alts_profession_display',
			'How to show profession in alts list',
			'edit',
			'options',
			'1',
			'off;icon;short;full;icon+short;icon+full',
			'0;1;2;4;3;5',
			'mod'
		);
		$this->settingManager->add(
			$this->moduleName,
			'alts_sort',
			'By what to sort the alts list',
			'edit',
			'options',
			'level',
			'level;name',
			'',
			'mod'
		);
	}

	/**
	 * This command handler adds alt character.
	 *
	 * @HandlesCommand("alts")
	 * @Matches("/^alts add ([a-z0-9- ]+)$/i")
	 */
	public function addAltCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/* get all names in an array */
		$names = preg_split('/\s+/', $args[1]);
	
		$sender = ucfirst(strtolower($sender));
	
		$senderAltInfo = $this->getAltInfo($sender);
	
		$success = 0;
	
		// Pop a name from the array until none are left
		foreach ($names as $name) {
			$name = ucfirst(strtolower($name));
	
			$uid = $this->chatBot->get_uid($name);
			if (!$uid) {
				$msg = "Character <highlight>{$name}<end> does not exist.";
				$sendto->reply($msg);
				continue;
			}
	
			$altInfo = $this->getAltInfo($name);
			if ($altInfo->main == $senderAltInfo->main) {
				// already registered to self
				$msg = "<highlight>$name<end> is already registered to you.";
				$sendto->reply($msg);
				continue;
			}
	
			if (count($altInfo->alts) > 0) {
				// already registered to someone else
				if ($altInfo->main == $name) {
					$msg = "Cannot add alt because <highlight>$name<end> is already registered as a main with alts.";
				} else {
					$msg = "Cannot add alt because <highlight>$name<end> is already registered as an of alt of <highlight>{$altInfo->main}<end>.";
				}
				$sendto->reply($msg);
				continue;
			}
	
			$validated = false;
			if ($senderAltInfo->isValidated($sender)) {
				$validated = true;
			}
	
			// insert into database
			$this->addAlt($senderAltInfo->main, $name, $validated);
			$success++;
	
			// update character information
			$this->playerManager->getByName($name);
		}
	
		if ($success > 0) {
			$msg = ($success == 1 ? "Alt" : "$success alts") . " added successfully.";
			$sendto->reply($msg);
		}
	}

	/**
	 * This command handler removes alt character.
	 *
	 * @HandlesCommand("alts")
	 * @Matches("/^alts (rem|del|remove|delete) ([a-z0-9-]+)$/i")
	 */
	public function removeAltCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[2]));
	
		$altInfo = $this->getAltInfo($sender);
	
		if ($altInfo->main == $name) {
			$msg = "You cannot remove <highlight>{$name}<end> as your main.";
		} elseif (!array_key_exists($name, $altInfo->alts)) {
			$msg = "<highlight>{$name}<end> is not registered as your alt.";
		} elseif (!$altInfo->isValidated($sender) && $altInfo->isValidated($name)) {
			$msg = "You must be on a validated alt to remove another alt that is validated.";
		} else {
			$this->remAlt($altInfo->main, $name);
			$msg = "<highlight>{$name}<end> has been removed as your alt.";
		}
		$sendto->reply($msg);
	}

	/**
	 * This command handler sets main character.
	 *
	 * @HandlesCommand("alts")
	 * @Matches("/^alts setmain$/i")
	 */
	public function setMainCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$altInfo = $this->getAltInfo($sender);
	
		if ($altInfo->main == $sender) {
			$msg = "<highlight>{$sender}<end> is already registered as your main.";
			$sendto->reply($msg);
			return;
		}
	
		if (!$altInfo->isValidated($sender)) {
			$msg = "You must run this command from a validated character.";
			$sendto->reply($msg);
			return;
		}
	
		// remove all the old alt information
		$this->db->exec("DELETE FROM `alts` WHERE `main` = ?", $altInfo->main);
	
		// add current main to new main as an alt
		$this->addAlt($sender, $altInfo->main, true);
	
		// add current alts to new main
		foreach ($altInfo->alts as $alt => $validated) {
			if ($alt !== $sender) {
				$this->addAlt($sender, $alt, $validated);
			}
		}
	
		$msg = "Your main is now <highlight>{$sender}<end>.";
		$sendto->reply($msg);
	}

	/**
	 * This command handler lists alt characters.
	 *
	 * @HandlesCommand("alts")
	 * @Matches("/^alts ([a-z0-9-]+)$/i")
	 * @Matches("/^alts$/i")
	 */
	public function altsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (isset($args[1])) {
			$showValidateLinks = false;
			$name = ucfirst(strtolower($args[1]));
		} else {
			$showValidateLinks = true;
			$name = $sender;
		}
	
		$altInfo = $this->getAltInfo($name);
		if (count($altInfo->alts) == 0) {
			$msg = "No alts are registered for <highlight>{$name}<end>.";
		} else {
			$msg = $altInfo->getAltsBlob($showValidateLinks);
		}
	
		$sendto->reply($msg);
	}

	/**
	 * This command handler adds yourself as an alt to a main.
	 *
	 * @HandlesCommand("alts main (.+)")
	 * @Matches("/^alts main ([a-z0-9-]+)$/i")
	 */
	public function altsMainCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$new_main = $this->getAltInfo($args[1])->main;
	
		$uid = $this->chatBot->get_uid($new_main);
		if (!$uid) {
			$msg = "Character <highlight>$new_main<end> does not exist.";
			$sendto->reply($msg);
			return;
		}
	
		$altInfo = $this->getAltInfo($sender);
	
		if ($altInfo->main == $new_main) {
			$msg = "You are already registered as an alt of <highlight>{$new_main}<end>.";
			$sendto->reply($msg);
			return;
		}
	
		if ($altInfo->main == $sender && count($altInfo->alts) > 0) {
			$msg = "You must not have any alts already registered.";
			$sendto->reply($msg);
			return;
		}
	
		// let them know if they are changing the main for this player
		if ($altInfo->main !== $sender) {
			$this->remAlt($altInfo->main, $sender);
			$msg = "You have been removed as an alt of <highlight>{$altInfo->main}<end>.";
			$sendto->reply($msg);
		}
	
		$this->addAlt($new_main, $sender, false);
		$msg = "You have been registered as an alt of <highlight>{$new_main}<end>.";
		$sendto->reply($msg);
	}

	/**
	 * This command handler validate alts for admin privileges.
	 *
	 * @HandlesCommand("altvalidate")
	 * @Matches("/^altvalidate ([a-z0-9- ]+)$/i")
	 */
	public function altvalidateCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$altInfo = $this->getAltInfo($sender);
		$alt = ucfirst(strtolower($args[1]));
	
		if (!$altInfo->isValidated($sender)) {
			$sendto->reply("<highlight>$alt<end> cannot be validated from an alt that is not validated.");
			return;
		}
	
		// Make sure the character being validated is an alt of the person sending the command
		$isAlt = false;
		foreach ($altInfo->alts as $a => $validated) {
			if ($a !== $alt) {
				continue;
			}
			$isAlt = true;
	
			if ($validated == 1) {
				$sendto->reply("<highlight>$alt<end> is already validated as your alt.");
				return;
			}
		}
	
		if (!$isAlt) {
			$sendto->reply("<highlight>$alt<end> is not registered as your alt.");
			return;
		}
		$this->db->exec("UPDATE `alts` SET `validated` = ? WHERE `alt` LIKE ? AND `main` LIKE ?", '1', $alt, $altInfo->main);
		$event = new AltEvent();
		$event->main = $altInfo->main;
		$event->alt = $alt;
		$event->validated = true;
		$event->type = 'alt(validate)';
		$this->eventManager->fireEvent($event);
		$sendto->reply("<highlight>$alt<end> has been validated as your alt.");
	}

	/**
	 * @Event("logOn")
	 * @Description("Reminds players logging in to validate alts")
	 */
	public function checkUnvalidatedAltsEvent(Event $eventObj): void {
		if ($this->chatBot->isReady()) {
			$altInfo = $this->getAltInfo($eventObj->sender);
		
			if ($altInfo->hasUnvalidatedAlts() && $altInfo->isValidated($eventObj->sender)) {
				$msg = "You have unvalidated alts. Please validate them.";
				$this->chatBot->sendTell($msg, $eventObj->sender);
				$this->chatBot->sendTell($altInfo->getAltsBlob(true), $eventObj->sender);
			}
		}
	}

	/**
	 * Get information about the mains and alts of a player
	 * @param string $player The name of either the main or one of their alts
	 * @return AltInfo Information about the main and the alts
	 */
	public function getAltInfo(string $player): AltInfo {
		$player = ucfirst(strtolower($player));

		$ai = new AltInfo();

		$sql = "SELECT * FROM `alts` WHERE (`main` LIKE ?) OR (`main` LIKE (SELECT `main` FROM `alts` WHERE `alt` LIKE ?))";
		/** @var Alt[] $data */
		$data = $this->db->fetchAll(Alt::class, $sql, $player, $player);

		$ai->main = $player;
		if (count($data) > 0) {
			foreach ($data as $row) {
				$ai->main = $row->main;
				$ai->alts[$row->alt] = $row->validated;
			}
		}

		return $ai;
	}

	/**
	 * This method adds given @a $alt as @a $main's alt character.
	 */
	public function addAlt(string $main, string $alt, bool $validated): int {
		$main = ucfirst(strtolower($main));
		$alt = ucfirst(strtolower($alt));

		$sql = "INSERT INTO `alts` (`alt`, `main`, `validated`) VALUES (?, ?, ?)";
		$added = $this->db->exec($sql, $alt, $main, $validated);
		if ($added > 0) {
			$event = new AltEvent();
			$event->main = $main;
			$event->alt = $alt;
			$event->validated = $validated;
			$event->type = 'alt(add)';
			$this->eventManager->fireEvent($event);
		}
		return $added;
	}

	/**
	 * This method removes given a $alt from being $main's alt character.
	 */
	public function remAlt(string $main, string $alt): int {
		$sql = "DELETE FROM `alts` WHERE `alt` LIKE ? AND `main` LIKE ?";
		$deleted = $this->db->exec($sql, $alt, $main);
		if ($deleted > 0) {
			$event = new AltEvent();
			$event->main = $main;
			$event->alt = $alt;
			$event->type = 'alt(del)';
			$this->eventManager->fireEvent($event);
		}
		return $deleted;
	}
}
