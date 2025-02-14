<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BUDDYLIST;

use function Safe\preg_match;

use Nadybot\Core\{
	Attributes as NCA,
	Attributes\Parameter\Remove,
	Attributes\Parameter\Str,
	BuddylistEntry,
	BuddylistManager,
	CmdContext,
	ModuleInstance,
	Nadybot,
	ParamClass\PCharacter,
	Text,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'buddylist',
		accessLevel: 'admin',
		description: 'Shows and manages buddies on the buddylist',
		alias: 'friendlist'
	)
]
class BuddylistController extends ModuleInstance {
	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BuddylistManager $buddylistManager;

	/** Show all characters currently on the buddylist */
	#[NCA\HandlesCommand('buddylist')]
	public function buddylistShowCommand(CmdContext $context): void {
		$orphanCount = 0;
		$dupeCount = 0;
		if (count($this->buddylistManager->buddyList) === 0) {
			$msg = 'There are no players on the buddy list.';
			$context->reply($msg);
			return;
		}
		$count = 0;
		$blob = '';
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
			if (count($value->worker) > 1) {
				$dupeCount++;
			}
			$blob .= $this->renderBuddyLine($value, $removed);
		}

		$blob .= "\n";
		if ($orphanCount > 0) {
			$blob .= "\nUnknown: {$orphanCount} [";
			$blob .= Text::makeChatcmd(
				'remove orphans',
				'/tell <myname> <symbol>buddylist clean'
			) . ']';
		}
		if ($dupeCount > 0) {
			$blob .= "\nDuplicates: {$dupeCount} [";
			$blob .= Text::makeChatcmd(
				'remove duplicates',
				'/tell <myname> <symbol>buddylist rebalance'
			) . ']';
		}
		$msg = Text::makeBlob("Buddy list ({$count})", $blob);
		$context->reply($msg);
	}

	/** Remove unneeded players from the buddy list */
	#[NCA\HandlesCommand('buddylist')]
	public function buddylistClearCommand(
		CmdContext $context,
		#[Str('clear', 'clean')] string $action
	): void {
		$orphanCount = 0;
		if (count($this->buddylistManager->buddyList) === 0) {
			$msg = 'There are no players on the buddy list.';
			$context->reply($msg);
			return;
		}
		$count = 0;
		$blob = '';
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
				$removed = ' <off>REMOVED<end>';

				// don't count removed characters
				$count--;
			}
			$blob .= $this->renderBuddyLine($value, $removed);
		}

		$blob .="\n\nRemoved: ({$orphanCount})";

		$context->reply("Removed {$orphanCount} characters from the buddy list.");
		$msg = Text::makeBlob("Buddy list ({$count})", $blob);
		$context->reply($msg);
	}

	/**
	 * Manually add a character to the buddy list
	 * Type is the reason why a character should be on the buddylist.
	 * It's displayed on the '<symbol>buddylist' command in square brackets.
	 */
	#[NCA\HandlesCommand('buddylist')]
	public function buddylistAddCommand(
		CmdContext $context,
		#[Str('add')] string $action,
		PCharacter $who,
		#[NCA\Parameter\WordStr] string $type
	): void {
		$name = $who();

		if (true === $this->buddylistManager->addName($name, $type)) {
			$msg = "<highlight>{$name}<end> added to the buddy list successfully.";
		} else {
			$msg = "Could not add <highlight>{$name}<end> to the buddy list.";
		}

		$context->reply($msg);
	}

	/** Remove all characters from the buddylist. Use with caution. */
	#[NCA\HandlesCommand('buddylist')]
	public function buddylistRemAllCommand(
		CmdContext $context,
		#[Remove] string $rem,
		#[Str('all')] string $all
	): void {
		foreach ($this->buddylistManager->buddyList as $uid => $buddy) {
			$this->chatBot->aoClient->buddyRemove($uid);
		}

		$msg = 'All characters have been removed from the buddy list.';
		$context->reply($msg);
	}

	/**
	 * Manually remove a character from the buddy list
	 * Type is the reason why a character is on the buddylist.
	 * It's displayed on the '<symbol>buddylist' command in square brackets.
	 */
	#[NCA\HandlesCommand('buddylist')]
	public function buddylistRemCommand(
		CmdContext $context,
		#[Remove] string $action,
		PCharacter $who,
		#[NCA\Parameter\WordStr] string $type
	): void {
		$name = $who();

		if ($this->buddylistManager->remove($name, $type)) {
			$msg = "<highlight>{$name}<end> removed from the buddy list successfully.";
		} else {
			$msg = "Could not remove <highlight>{$name}<end> from the buddy list.";
		}

		$context->reply($msg);
	}

	/** Render a BuddylistEntry as a string */
	public function renderBuddyLine(BuddylistEntry $entry, string $suffix=''): string {
		$blob = $entry->name . $suffix;
		if (count($entry->types ?? [])) {
			$blob .= ' [' . implode(', ', array_keys($entry->types)) . ']';
		} else {
			$blob .= ' [-]';
		}
		if (count($entry->worker) > 1) {
			$blob .= ' {' . implode(', ', array_keys($entry->worker)) . '}';
		}
		if ($entry->known && $entry->online) {
			$blob .= ' <on>Online<end>';
		}
		return "{$blob}\n";
	}

	/** Search for characters on the buddylist containing &lt;search&gt; */
	#[NCA\HandlesCommand('buddylist')]
	public function buddylistSearchCommand(
		CmdContext $context,
		#[Str('search')] string $action,
		string $search
	): void {
		if (count($this->buddylistManager->buddyList) === 0) {
			$msg = 'There are no characters on the buddy list.';
			$context->reply($msg);
			return;
		}
		$count = 0;
		$blob = "Buddy list Search: '{$search}'\n\n";
		foreach ($this->getSortedBuddyList() as $value) {
			if (preg_match("/{$search}/i", $value->name)) {
				$count++;
				$blob .= $this->renderBuddyLine($value);
			}
		}

		if ($count > 0) {
			$msg = Text::makeBlob("Buddy List Search ({$count})", $blob);
		} else {
			$msg = "No characters on the buddy list found containing '{$search}'";
		}
		$context->reply($msg);
	}

	/** Re-balance the buddies on the workers by removing and re-adding all of them */
	#[NCA\HandlesCommand('buddylist')]
	public function buddylistRebalanceCommand(
		CmdContext $context,
		#[Str('rebalance')] string $action,
	): void {
		if (count($this->buddylistManager->buddyList) === 0) {
			$context->reply('There are no characters on the buddy list.');
			return;
		}
		if ($this->buddylistManager->isRebalancing()) {
			$context->reply('There is already a rebalance in progress.');
			return;
		}
		$this->buddylistManager->rebalance($context);
		$context->reply(
			'Rebalancing all ' . count($this->buddylistManager->buddyList) . ' buddies...'
		);
	}

	/**
	 * @return array<int,BuddylistEntry>
	 *
	 * @psalm-return list<BuddylistEntry>
	 */
	public function getSortedBuddyList(): array {
		$buddylist = $this->buddylistManager->buddyList;
		usort($buddylist, static function (BuddylistEntry $entry1, BuddylistEntry $entry2): int {
			return strnatcmp($entry1->name, $entry2->name);
		});
		return $buddylist;
	}
}
