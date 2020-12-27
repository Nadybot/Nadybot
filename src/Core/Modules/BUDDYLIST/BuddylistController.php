<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BUDDYLIST;

use Nadybot\Core\BuddylistEntry;
use Nadybot\Core\Nadybot;
use Nadybot\Core\BuddylistManager;
use Nadybot\Core\Text;
use Nadybot\Core\CommandReply;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
*	@DefineCommand(
 *		command     = 'buddylist',
 *		accessLevel = 'admin',
 *		description = 'Shows and manages buddies on the buddylist',
 *		help        = 'buddylist.txt',
 *		alias		= 'friendlist'
 *	)
 */
class BuddylistController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public $moduleName;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public BuddylistManager $buddylistManager;

	/** @Inject */
	public Text $text;

	/**
	 * @HandlesCommand("buddylist")
	 * @Matches("/^buddylist$/i")
	 * @Matches("/^buddylist (clean)$/i")
	 */
	public function buddylistShowCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (count($args) == 2) {
			$cleanup = true;
		}

		$orphanCount = 0;
		if (count($this->buddylistManager->buddyList) === 0) {
			$msg = "There are no players on the buddy list.";
			$sendto->reply($msg);
			return;
		}
		$count = 0;
		$blob = "";
		foreach ($this->getSortedBuddyList() as $value) {
			if (!$value->known) {
				// skip the characters that have been added but the server hasn't sent back an update yet
				continue;
			}

			$count++;
			$removed = '';
			if (count($value->types ?? []) === 0) {
				$orphanCount++;
				if ($cleanup) {
					$this->buddylistManager->remove($value->name);
					$removed = "<red>REMOVED<end>";

					// don't count removed characters
					$count--;
				}
			}

			$blob .= $value->name . " $removed [" . implode(', ', array_keys($value->types ?? ["?" => true])) . "]\n";
		}

		if ($cleanup) {
			$blob .="\n\nRemoved: ($orphanCount)";
		} else {
			$blob .= "\n\nUnknown: ($orphanCount) ";
			if ($orphanCount > 0) {
				$blob .= $this->text->makeChatcmd('Remove Orphans', '/tell <myname> <symbol>buddylist clean');
			}
		}

		if ($cleanup) {
			$sendto->reply("Removed {$orphanCount} characters from the buddy list.");
		}
		$msg = $this->text->makeBlob("Buddy list ($count)", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("buddylist")
	 * @Matches("/^buddylist add ([^ ]+) ([^ ]+)$/i")
	 */
	public function buddylistAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));
		$type = $args[2];

		if ($this->buddylistManager->add($name, $type)) {
			$msg = "<highlight>{$name}<end> added to the buddy list successfully.";
		} else {
			$msg = "Could not add <highlight>{$name}<end> to the buddy list.";
		}

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("buddylist")
	 * @Matches("/^buddylist rem all$/i")
	 */
	public function buddylistRemAllCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		foreach ($this->buddylistManager->buddyList as $uid => $buddy) {
			$this->chatBot->buddy_remove($uid);
		}

		$msg = "All characters have been removed from the buddy list.";
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("buddylist")
	 * @Matches("/^buddylist rem ([^ ]+) ([^ ]+)$/i")
	 */
	public function buddylistRemCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));
		$type = $args[2];

		if ($this->buddylistManager->remove($name, $type)) {
			$msg = "<highlight>{$name}<end> removed from the buddy list successfully.";
		} else {
			$msg = "Could not remove <highlight>{$name}<end> from the buddy list.";
		}

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("buddylist")
	 * @Matches("/^buddylist search (.*)$/i")
	 */
	public function buddylistSearchCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$search = $args[1];

		if (count($this->buddylistManager->buddyList) === 0) {
			$msg = "There are no characters on the buddy list.";
			$sendto->reply($msg);
			return;
		}
		$count = 0;
		$blob = "Buddy list Search: '{$search}'\n\n";
		foreach ($this->getSortedBuddyList() as $value) {
			if (preg_match("/$search/i", $value->name)) {
				$count++;
				$blob .= $value->name . " [" . implode(', ', array_keys($value->types ?? ["?" => true])) . "]\n";
			}
		}

		if ($count > 0) {
			$msg = $this->text->makeBlob("Buddy List Search ($count)", $blob);
		} else {
			$msg = "No characters on the buddy list found containing '$search'";
		}
		$sendto->reply($msg);
	}

	/**
	 * @return array<int,BuddylistEntry>
	 */
	public function getSortedBuddyList(): array {
		$buddylist = $this->buddylistManager->buddyList;
		usort($buddylist, function (BuddylistEntry $entry1, BuddylistEntry $entry2) {
			return $entry1->name > $entry2->name;
		});
		return $buddylist;
	}
}
