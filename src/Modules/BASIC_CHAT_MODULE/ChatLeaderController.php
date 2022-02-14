<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\{
	AccessLevelProvider,
	AccessManager,
	AOChatEvent,
	Attributes as NCA,
	CmdContext,
	EventManager,
	ModuleInstance,
	Nadybot,
	ParamClass\PCharacter,
	SettingManager,
};

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "leader",
		accessLevel: "guest",
		description: "Become the Leader of the raid",
	),
	NCA\DefineCommand(
		command: ChatLeaderController::CMD_LEADER_SET,
		accessLevel: "rl",
		description: "Sets a specific Leader",
	),
	NCA\DefineCommand(
		command: "leaderecho",
		accessLevel: "rl",
		description: "Set if the text of the leader will be repeated",
	),
	NCA\ProvidesEvent("leader(clear)"),
	NCA\ProvidesEvent("leader(set)")
]
class ChatLeaderController extends ModuleInstance implements AccessLevelProvider {
	public const CMD_LEADER_SET = "leader set leader";

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public EventManager $eventManager;

	/**
	 * Name of the leader character.
	 */
	private ?string $leader = null;

	#[NCA\Setup]
	public function setup(): void {
		$this->accessManager->registerProvider($this);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "leaderecho",
			description: "Repeat the text of the leader",
			mode: "edit",
			type: "options",
			value: "1",
			options: "true;false",
			intoptions: "1;0"
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "leaderecho_color",
			description: "Color for leader echo",
			mode: "edit",
			type: "color",
			value: "<font color=#FFFF00>",
		);
	}

	public function getSingleAccessLevel(string $sender): ?string {
		if ($this->getLeader() === $sender) {
			return "rl";
		}
		return null;
	}

	/**
	 * Sets the leader of the raid, granting special access
	 */
	#[NCA\HandlesCommand("leader")]
	public function leaderCommand(CmdContext $context): void {
		if ($this->leader === $context->char->name) {
			$this->leader = null;
			$this->chatBot->sendPrivate("Raid Leader cleared.");
			$event = new LeaderEvent();
			$event->type = "leader(clear)";
			$this->eventManager->fireEvent($event);
			return;
		}
		$msg = $this->setLeader($context->char->name, $context->char->name);
		if ($msg !== null) {
			$context->reply($msg);
		}
	}

	/**
	 * Set someone to be raid leader
	 */
	#[NCA\HandlesCommand(self::CMD_LEADER_SET)]
	public function leaderSetCommand(CmdContext $context, PCharacter $newLeader): void {
		$msg = $this->setLeader($newLeader(), $context->char->name);
		if ($msg !== null) {
			$context->reply($msg);
		}
	}

	public function setLeader(string $name, string $sender): ?string {
		$name = ucfirst(strtolower($name));
		$uid = $this->chatBot->get_uid($name);
		if (!$uid) {
			return "Character <highlight>{$name}<end> does not exist.";
		}
		if (!isset($this->chatBot->chatlist[$name])) {
			return "Character <highlight>{$name}<end> is not in the private channel.";
		}
		if (isset($this->leader)
			&& $sender !== $this->leader
			&& $this->accessManager->compareCharacterAccessLevels($sender, $this->leader) <= 0) {
			return "You cannot take Raid Leader from <highlight>{$this->leader}<end>.";
		}
		$this->leader = $name;
		$this->chatBot->sendPrivate($this->getLeaderStatusText());
		$event = new LeaderEvent();
		$event->type = "leader(set)";
		$event->player = $name;
		$this->eventManager->fireEvent($event);
		return null;
	}

	/**
	 * Enable or disable leader echoing in the private channel
	 */
	#[NCA\HandlesCommand("leaderecho")]
	public function leaderEchoOnCommand(CmdContext $context, bool $on): void {
		if (!$this->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}
		$this->settingManager->save("leaderecho", $on ? "1" : "0");
		$this->chatBot->sendPrivate("Leader echo has been " . $this->getEchoStatusText());
	}

	/**
	 * Shows the current echoing state
	 */
	#[NCA\HandlesCommand("leaderecho")]
	public function leaderEchoCommand(CmdContext $context): void {
		if (!$this->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}
		$this->chatBot->sendPrivate("Leader echo is currently " . $this->getEchoStatusText());
	}

	#[NCA\Event(
		name: "priv",
		description: "Repeats what the leader says in the color of leaderecho_color setting"
	)]
	public function privEvent(AOChatEvent $eventObj): void {
		if (!$this->settingManager->getBool("leaderecho")
			|| $this->leader !== $eventObj->sender
			|| $eventObj->message[0] === $this->settingManager->get("symbol")) {
			return;
		}
		$msg = ($this->settingManager->getString("leaderecho_color")??"<font>") . $eventObj->message . "<end>";
		$this->chatBot->sendPrivate($msg);
	}

	#[NCA\Event(
		name: "leavePriv",
		description: "Removes leader when the leader leaves the channel"
	)]
	public function leavePrivEvent(AOChatEvent $eventObj): void {
		if ($this->leader !== $eventObj->sender) {
			return;
		}
		$this->leader = null;
		$msg = "Raid Leader cleared.";
		$this->chatBot->sendPrivate($msg);
	}

	/**
	 * Returns echo's status message based on 'leaderecho' setting.
	 */
	private function getEchoStatusText(): string {
		if ($this->settingManager->getBool("leaderecho")) {
			$status = "<green>Enabled<end>";
		} else {
			$status = "<red>Disabled<end>";
		}
		return $status;
	}

	/**
	 * Returns current leader and echo's current status.
	 */
	private function getLeaderStatusText(): string {
		$cmd = $this->settingManager->getBool("leaderecho") ? "off": "on";
		$status = $this->getEchoStatusText();
		$msg = "{$this->leader} is now Raid Leader. Leader echo is currently {$status}. You can change it with <symbol>leaderecho {$cmd}";
		return $msg;
	}

	public function getLeader(): ?string {
		return $this->leader;
	}

	public function checkLeaderAccess(string $sender): bool {
		if (empty($this->leader)) {
			return true;
		} elseif ($this->leader === $sender) {
			return true;
		} elseif ($this->accessManager->checkAccess($sender, "moderator")) {
			return true;
		}
		return false;
	}
}
