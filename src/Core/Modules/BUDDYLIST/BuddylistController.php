<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BUDDYLIST;

use Nadybot\Core\{
	Attributes as NCA,
	BuddylistEntry,
	Nadybot,
	BuddylistManager,
	CmdContext,
	ModuleInstance,
	Text,
	ParamClass\PCharacter,
	ParamClass\PRemove,
	ParamClass\PWord,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "buddylist",
		accessLevel: "admin",
		description: "Shows and manages buddies on the buddylist",
		alias: "friendlist"
	)
]
class BuddylistController extends ModuleInstance {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	#[NCA\Inject]
	public Text $text;

	/** Show all characters currently on the buddylist */
	#[NCA\HandlesCommand("buddylist")]
	public function buddylistShowCommand(CmdContext $context): void {
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
			}
			$blob .= $this->renderBuddyLine($value, $removed);
		}

		$blob .= "\n\nUnknown: ($orphanCount) ";
		if ($orphanCount > 0) {
			$blob .= $this->text->makeChatcmd('Remove Orphans', '/tell <myname> <symbol>buddylist clean');
		}
		$msg = $this->text->makeBlob("Buddy list ($count)", $blob);
		$context->reply($msg);
	}

	/** Remove unneeded players from the buddy list */
	#[NCA\HandlesCommand("buddylist")]
	public function buddylistClearCommand(
		CmdContext $context,
		#[NCA\Str("clear", "clean")] string $action
	): void {
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
				$this->buddylistManager->remove($value->name);
				$removed = " <red>REMOVED<end>";

				// don't count removed characters
				$count--;
			}
			$blob .= $this->renderBuddyLine($value, $removed);
		}

		$blob .="\n\nRemoved: ($orphanCount)";

		$context->reply("Removed {$orphanCount} characters from the buddy list.");
		$msg = $this->text->makeBlob("Buddy list ($count)", $blob);
		$context->reply($msg);
	}

	/**
	 * Manually add a character to the buddy list
	 * Type is the reason why a character should be on the buddylist.
	 * It's displayed on the '<symbol>buddylist' command in square brackets.
	 */
	#[NCA\HandlesCommand("buddylist")]
	public function buddylistAddCommand(
		CmdContext $context,
		#[NCA\Str("add")] string $action,
		PCharacter $who,
		PWord $type
	): void {
		$name = $who();

		if ($this->buddylistManager->add($name, $type())) {
			$msg = "<highlight>{$name}<end> added to the buddy list successfully.";
		} else {
			$msg = "Could not add <highlight>{$name}<end> to the buddy list.";
		}

		$context->reply($msg);
	}

	/**
	 * Remove all characters from the buddylist. Use with caution.
	 */
	#[NCA\HandlesCommand("buddylist")]
	public function buddylistRemAllCommand(
		CmdContext $context,
		PRemove $rem,
		#[NCA\Str("all")] string $all
	): void {
		foreach ($this->buddylistManager->buddyList as $uid => $buddy) {
			$this->chatBot->buddy_remove($uid);
		}

		$msg = "All characters have been removed from the buddy list.";
		$context->reply($msg);
	}

	/**
	 * Manually remove a character from the buddy list
	 * Type is the reason why a character is on the buddylist.
	 * It's displayed on the '<symbol>buddylist' command in square brackets.
	 */
	#[NCA\HandlesCommand("buddylist")]
	public function buddylistRemCommand(
		CmdContext $context,
		PRemove $action,
		PCharacter $who,
		PWord $type
	): void {
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
	 * Search for characters on the buddylist containing &lt;search&gt;
	 */
	#[NCA\HandlesCommand("buddylist")]
	public function buddylistSearchCommand(
		CmdContext $context,
		#[NCA\Str("search")] string $action,
		string $search
	): void {
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
	 * Rebalance the buddies on the workers by removing and re-adding all of them
	 */
	#[NCA\HandlesCommand("buddylist")]
	public function buddylistRebalanceCommand(
		CmdContext $context,
		#[NCA\Str("rebalance")] string $action,
	): void {
		if (count($this->buddylistManager->buddyList) === 0) {
			$context->reply("There are no characters on the buddy list.");
			return;
		}
		$this->buddylistManager->rebalance();
		$context->reply(
			"Rebalanced all " . count($this->buddylistManager->buddyList) . " buddies"
		);
	}

	/**
	 * @return array<int,BuddylistEntry>
	 */
	public function getSortedBuddyList(): array {
		$buddylist = $this->buddylistManager->buddyList;
		usort($buddylist, function (BuddylistEntry $entry1, BuddylistEntry $entry2): int {
			return strnatcmp($entry1->name, $entry2->name);
		});
		return $buddylist;
	}
}
