<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BUDDYLIST;

use Nadybot\Core\BuddylistEntry;
use Nadybot\Core\Nadybot;
use Nadybot\Core\BuddylistManager;
use Nadybot\Core\CmdContext;
use Nadybot\Core\Text;
use Nadybot\Core\ParamClass\PCharacter;
use Nadybot\Core\ParamClass\PRemove;
use Nadybot\Core\ParamClass\PWord;

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
	 */
	public function buddylistShowCommand(CmdContext $context, ?string $clean="clean"): void {
		$cleanup = isset($clean);

		$orphanCount = 0;
		if (count($this->buddylistManager->buddyList) === 0) {
			$msg = "There are no players on the buddy list.";
			$context->reply($msg);
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
					$removed = " <red>REMOVED<end>";

					// don't count removed characters
					$count--;
				}
			}
			$blob .= $this->renderBuddyLine($value, $removed);
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
			$context->reply("Removed {$orphanCount} characters from the buddy list.");
		}
		$msg = $this->text->makeBlob("Buddy list ($count)", $blob);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("buddylist")
	 */
	public function buddylistAddCommand(CmdContext $context, string $add="add", PCharacter $who, PWord $type): void {
		$name = $who();

		if ($this->buddylistManager->add($name, $type())) {
			$msg = "<highlight>{$name}<end> added to the buddy list successfully.";
		} else {
			$msg = "Could not add <highlight>{$name}<end> to the buddy list.";
		}

		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("buddylist")
	 */
	public function buddylistRemAllCommand(CmdContext $context, PRemove $rem, string $all="all"): void {
		foreach ($this->buddylistManager->buddyList as $uid => $buddy) {
			$this->chatBot->buddy_remove($uid);
		}

		$msg = "All characters have been removed from the buddy list.";
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("buddylist")
	 */
	public function buddylistRemCommand(CmdContext $context, PRemove $rem, PCharacter $who, PWord $type): void {
		$name = $who();

		if ($this->buddylistManager->remove($name, $type())) {
			$msg = "<highlight>{$name}<end> removed from the buddy list successfully.";
		} else {
			$msg = "Could not remove <highlight>{$name}<end> from the buddy list.";
		}

		$context->reply($msg);
	}

	/** Render a BuddylistEntry as a string */
	public function renderBuddyLine(BuddylistEntry $entry, string $suffix=""): string {
		$blob = $entry->name . $suffix;
		if (count($entry->types ?? [])) {
			$blob .= " [" . implode(', ', array_keys($entry->types)) . "]";
		} else {
			$blob .= " [-]";
		}
		return "{$blob}\n";
	}

	/**
	 * @HandlesCommand("buddylist")
	 */
	public function buddylistSearchCommand(CmdContext $context, string $action="search", string $search): void {
		if (count($this->buddylistManager->buddyList) === 0) {
			$msg = "There are no characters on the buddy list.";
			$context->reply($msg);
			return;
		}
		$count = 0;
		$blob = "Buddy list Search: '{$search}'\n\n";
		foreach ($this->getSortedBuddyList() as $value) {
			if (preg_match("/$search/i", $value->name)) {
				$count++;
				$blob .= $this->renderBuddyLine($value);
			}
		}

		if ($count > 0) {
			$msg = $this->text->makeBlob("Buddy List Search ($count)", $blob);
		} else {
			$msg = "No characters on the buddy list found containing '$search'";
		}
		$context->reply($msg);
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
