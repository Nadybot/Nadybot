<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\{
	AOChatEvent,
	Attributes as NCA,
	CmdContext,
	ModuleInstance,
	Text,
};

/**
 * Commands this class contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "group",
		accessLevel: "guest",
		description: "Join the group selection",
	),
	NCA\DefineCommand(
		command: "group manage",
		accessLevel: "rl",
		description: "Divide people into groups",
	),
]
class ChatGroupingController extends ModuleInstance {
	/** @var string[] */
	public array $joined = [];

	/** @var array<int,string[]> */
	public array $grouped = [];
	#[NCA\Inject]
	private Text $text;

	/** Clear current grouping */
	#[NCA\HandlesCommand("group manage")]
	public function groupClearCommand(
		CmdContext $context,
		#[NCA\Str("clear")]
		string $action,
	): void {
		$this->joined = [];
		$this->grouped = [];
		$context->reply("All grouping cleared.");
	}

	/** Show current groups */
	#[NCA\HandlesCommand("group")]
	public function groupShowCommand(
		CmdContext $context,
	): void {
		if (empty($this->grouped)) {
			if (empty($this->joined)) {
				$context->reply("No current groups, no players joined.");
				return;
			}
			$blob = "<header2>Joined players<end>\n<tab>- ".
				join("\n<tab>- ", $this->joined) . "\n\n".
				"<i>Use <highlight><symbol>group divide &lt;number of groups&gt;<end> to divide into groups.</i>";
			$msg = $this->text->makeBlob(count($this->joined) . " players joined", $blob);
			$msg = $this->text->blobWrap(
				"No current groups, ",
				$msg,
				"."
			);
			$context->reply($msg);
			return;
		}
		$blob = $this->renderGroups($this->grouped);
		$msg = $this->text->makeBlob("Current groups", $blob);
		$context->reply($msg);
	}

	/** Divide joined people into equally-sized groups */
	#[NCA\HandlesCommand("group manage")]
	public function groupDivideCommand(
		CmdContext $context,
		#[NCA\Str("divide")]
		string $action,
		int $numGroups,
	): void {
		if (empty($this->joined)) {
			$context->reply("No one has joined yet.");
			return;
		}
		if (count($this->joined) < $numGroups) {
			$context->reply("You cannot divide " . count($this->joined) . " by {$numGroups}.");
			return;
		}
		$this->grouped = [];
		$queue = $this->joined;
		for ($i = 0; $i < count($this->joined); $i++) {
			$entry = array_rand($queue);
			$this->grouped[$i % $numGroups] ??= [];
			$this->grouped[$i % $numGroups] []= $queue[$entry];
			unset($queue[$entry]);
		}
		$blob = $this->renderGroups($this->grouped);
		$msg = $this->text->makeBlob("Divided " . count($this->joined) . " players", $blob);
		$context->reply($msg);
	}

	/** Join the currently rolled groups */
	#[NCA\HandlesCommand("group")]
	public function groupJoinCommand(
		CmdContext $context,
		#[NCA\Str("join")]
		string $action,
	): void {
		if (in_array($context->char->name, $this->joined, true)) {
			$context->reply("You've already joined.");
			return;
		}
		$this->joined []= $context->char->name;
		$context->reply("You joined the grouping.");
	}

	/** Leave the currently rolled groups */
	#[NCA\HandlesCommand("group")]
	public function groupLeaveCommand(
		CmdContext $context,
		#[NCA\Str("leave")]
		string $action,
	): void {
		if (!in_array($context->char->name, $this->joined, true)) {
			$context->reply("You're not in the grouping.");
			return;
		}
		$this->joined = array_diff($this->joined, [$context->char->name]);
		$context->reply("You left the grouping.");
	}

	#[NCA\Event(
		name: "leavePriv",
		description: "Removes people from the grouping when they leave the channel"
	)]
	public function leavePrivEvent(AOChatEvent $eventObj): void {
		if (!in_array($eventObj->sender, $this->joined, true)) {
			return;
		}
		$this->joined = array_diff($this->joined, [$eventObj->sender]);
	}

	/** @param array<int,string[]> $groups */
	private function renderGroups(array $groups): string {
		$lines = [];
		foreach ($groups as $groupId => $members) {
			$group = ["<header2>Group " . ($groupId +1) . "<end>"];
			foreach ($members as $member) {
				$group []= "<tab>- {$member}";
			}
			$lines []= join("\n", $group);
		}
		return join("\n\n", $lines);
	}
}
