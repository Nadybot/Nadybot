<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Nadybot\Core\{
	BuddylistManager,
	CommandReply,
	DB,
	DBSchema\Alt,
	Event,
	EventManager,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	SettingManager,
	Text,
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
 *		command       = 'altvalidate',
 *		accessLevel   = 'all',
 *		description   = 'Validate alts for admin privileges',
 *		help          = 'altvalidate.txt'
 *	)
 *	@DefineCommand(
 *		command       = 'altdecline',
 *		accessLevel   = 'all',
 *		description   = 'Declines being the alt of someone else',
 *		help          = 'altvalidate.txt'
 *	)
 * @ProvidesEvent("alt(add)")
 * @ProvidesEvent("alt(del)")
 * @ProvidesEvent("alt(validate)")
 * @ProvidesEvent("alt(decline)")
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
	public BuddylistManager $buddylistManager;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup() {
		$this->db->loadSQLFile($this->moduleName, 'alts');
		$this->settingManager->add(
			$this->moduleName,
			'alts_require_confirmation',
			'Adding alt requires confirmation from alt',
			'edit',
			'options',
			'1',
			'true;false',
			'1;0',
			'mod'
		);
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
	
		$senderAltInfo = $this->getAltInfo($sender, true);
	
		$success = 0;
	
		// Pop a name from the array until none are left
		foreach ($names as $name) {
			$name = ucfirst(strtolower($name));
			if ($name === $sender) {
				$msg = "You cannot add yourself as your own alt.";
				$sendto->reply($msg);
				continue;
			}
	
			$uid = $this->chatBot->get_uid($name);
			if (!$uid) {
				$msg = "Character <highlight>{$name}<end> does not exist.";
				$sendto->reply($msg);
				continue;
			}
	
			$altInfo = $this->getAltInfo($name, true);
			if ($altInfo->main === $senderAltInfo->main) {
				if ($altInfo->alts[$name]) {
					// already registered to self
					$msg = "<highlight>$name<end> is already registered to you.";
				} else {
					$msg = "You already requested adding <highlight>$name<end> as an alt.";
				}
				$sendto->reply($msg);
				continue;
			}
	
			if (count($altInfo->alts) > 0) {
				// already registered to someone else
				if ($altInfo->main === $name) {
					$msg = "Cannot add alt because <highlight>$name<end> is already registered as a main with alts.";
				} else {
					if ($altInfo->isValidated($name)) {
						$msg = "Cannot add alt, because <highlight>$name<end> is already registered as an alt of <highlight>{$altInfo->main}<end>.";
					} else {
						$msg = "Cannot add alt, because <highlight>$name<end> has a pending alt add request from <highlight>{$altInfo->main}<end>.";
					}
				}
				$sendto->reply($msg);
				continue;
			}
	
			$validated = $this->settingManager->getBool('alts_require_confirmation') === false;
	
			// insert into database
			$this->addAlt($senderAltInfo->main, $name, $validated);
			$success++;
			if (!$validated) {
				if ($this->buddylistManager->isOnline($name)) {
					$this->sendAltValidationRequest($name, $senderAltInfo);
				} else {
					$this->buddylistManager->add($name, "altvalidate");
				}
			}
	
			// update character information
			$this->playerManager->getByNameAsync(function() {
			}, $name);
		}
	
		if ($success > 0) {
			$numAlts = ($success === 1 ? "Alt" : "$success alts");
			if ($validated) {
				$msg = "{$numAlts} added successfully.";
			} else {
				$msg = "{$numAlts} requested to be added successfully. ".
					"Make sure to confirm on them.";
			}
			$sendto->reply($msg);
		}
	}

	/**
	 * This command handler removes an alt character.
	 *
	 * @HandlesCommand("alts")
	 * @Matches("/^alts (rem|del|remove|delete) ([a-z0-9-]+)$/i")
	 */
	public function removeAltCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[2]));
	
		$altInfo = $this->getAltInfo($sender, true);
	
		if ($altInfo->main === $name) {
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
	
		if ($altInfo->main === $sender) {
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
			$name = ucfirst(strtolower($args[1]));
		} else {
			$name = $sender;
		}
	
		$altInfo = $this->getAltInfo($name, true);
		if (count($altInfo->alts) === 0) {
			$msg = "No alts are registered for <highlight>{$name}<end>.";
		} else {
			$msg = $altInfo->getAltsBlob();
		}
	
		$sendto->reply($msg);
	}

	/**
	 * This command handler validate alts for admin privileges.
	 *
	 * @HandlesCommand("altvalidate")
	 * @Matches("/^altvalidate ([a-z0-9- ]+)$/i")
	 */
	public function altvalidateCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$altInfo = $this->getAltInfo($sender, true);
		$main = ucfirst(strtolower($args[1]));
	
		if ($altInfo->isValidated($sender)) {
			if ($altInfo->main === $main) {
				$sendto->reply("You are already a validated alt of <highlight>{$main}<end>.");
			} else {
				$sendto->reply("You don't have a pending alt validation request from <highlight>{$main}<end>.");
			}
			return;
		}

		if ($main !== $altInfo->main) {
			$sendto->reply("<highlight>{$main}<end> didn't request to add you as an alt.");
			return;
		}
		$this->db->exec(
			"UPDATE `alts` SET `validated` = ? ".
			"WHERE `alt` LIKE ? AND `main` LIKE ?",
			1,
			$sender,
			$main
		);
		$event = new AltEvent();
		$event->main = $main;
		$event->alt = $sender;
		$event->validated = true;
		$event->type = 'alt(validate)';
		$this->eventManager->fireEvent($event);
		$sendto->reply("<highlight>$main<end> has been validated as your main.");
		$this->buddylistManager->remove($sender, "altvalidate");
	}

	/**
	 * This command handler validate alts for admin privileges.
	 *
	 * @HandlesCommand("altdecline")
	 * @Matches("/^altdecline ([a-z0-9- ]+)$/i")
	 */
	public function altdeny(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$altInfo = $this->getAltInfo($sender, true);
		$main = ucfirst(strtolower($args[1]));
	
		if ($altInfo->isValidated($sender)) {
			if ($altInfo->main === $main) {
				$sendto->reply("You are already a validated alt of <highlight>{$main}<end>.");
			} else {
				$sendto->reply("You don't have a pending alt validation request from <highlight>{$main}<end>.");
			}
			return;
		}

		if ($main !== $altInfo->main) {
			$sendto->reply("<highlight>{$main}<end> didn't request to add you as an alt.");
			return;
		}
		$this->db->exec(
			"DELETE FROM `alts` WHERE `alt` LIKE ? AND `main` LIKE ?",
			$sender,
			$main
		);
		$event = new AltEvent();
		$event->main = $main;
		$event->alt = $sender;
		$event->validated = false;
		$event->type = 'alt(decline)';
		$this->eventManager->fireEvent($event);
		$sendto->reply("You declined <highlight>$main's<end> request to add you as their alt.");
		$this->buddylistManager->remove($sender, "altvalidate");
	}

	/**
	 * @Event("logOn")
	 * @Description("Reminds unvalidates alts to accept or deny")
	 */
	public function checkUnvalidatedAltsEvent(Event $eventObj): void {
		if (!$this->chatBot->isReady()) {
			return;
		}
		$altInfo = $this->getAltInfo($eventObj->sender, true);
		if (!$altInfo->hasUnvalidatedAlts() || $altInfo->isValidated($eventObj->sender)) {
			return;
		}
		$this->sendAltValidationRequest($eventObj->sender, $altInfo);
	}

	/**
	 * Send $sender a request to confirm that they are $altInfo->main's alt
	 */
	public function sendAltValidationRequest(string $sender, AltInfo $altInfo): void {
		$blob = "<header2>Are you an alt of {$altInfo->main}?<end>\n";
		$blob .= "<tab>We received a request from <highlight>{$altInfo->main}<end> ".
			"to add you as their alt.\n\n";
		$blob .= "<tab>Do you agree to this: ";
		$blob .= "[".
			$this->text->makeChatcmd("yes", "/tell <myname> altvalidate {$altInfo->main}").
			"] [".
			$this->text->makeChatcmd("no", "/tell <myname> altdecline {$altInfo->main}").
			"]";
		$msg = "{$altInfo->main} requested to add you as their alt :: ".
			$this->text->makeBlob("decide", $blob, "Decide if you are {$altInfo->main}'s alt");
		$this->chatBot->sendTell($msg, $sender);
	}

	/**
	 * Get information about the mains and alts of a player
	 * @param string $player The name of either the main or one of their alts
	 * @return AltInfo Information about the main and the alts
	 */
	public function getAltInfo(string $player, bool $includePending=false): AltInfo {
		$player = ucfirst(strtolower($player));

		$ai = new AltInfo();

		$validatedWhere = "AND validated IS TRUE";
		if ($includePending) {
			$validatedWhere = "";
		}
		$sql = "SELECT * FROM `alts` ".
			"WHERE (".
				"(`main` LIKE ?) ".
				"OR ".
				"(`main` LIKE (SELECT `main` FROM `alts` WHERE `alt` LIKE ? $validatedWhere))".
			") $validatedWhere";
		/** @var Alt[] */
		$data = $this->db->fetchAll(Alt::class, $sql, $player, $player);

		$ai->main = $player;
		foreach ($data as $row) {
			$ai->main = $row->main;
			$ai->alts[$row->alt] = $row->validated;
		}

		return $ai;
	}

	/**
	 * This method adds given $alt as $main's alt character.
	 */
	public function addAlt(string $main, string $alt, bool $validated): int {
		$main = ucfirst(strtolower($main));
		$alt = ucfirst(strtolower($alt));

		$sql = "INSERT INTO `alts` (`alt`, `main`, `validated`) VALUES (?, ?, ?)";
		$added = $this->db->exec($sql, $alt, $main, (int)$validated);
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
