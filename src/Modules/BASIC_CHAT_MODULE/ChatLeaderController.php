<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\{
	AccessManager,
	AOChatEvent,
	CmdContext,
	Event,
	EventManager,
	Nadybot,
	SettingManager,
};
use Nadybot\Core\ParamClass\PCharacter;

/**
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'leader',
 *		accessLevel = 'all',
 *		description = 'Sets the Leader of the raid',
 *		help        = 'leader.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'leader (.+)',
 *		accessLevel = 'rl',
 *		description = 'Sets a specific Leader',
 *		help        = 'leader.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'leaderecho',
 *		accessLevel = 'rl',
 *		description = 'Set if the text of the leader will be repeated',
 *		help        = 'leader.txt'
 *	)
 *	@ProvidesEvent("leader(clear)")
 *	@ProvidesEvent("leader(set)")
 */

class ChatLeaderController {

	public string $moduleName;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public EventManager $eventManager;

	/**
	 * Name of the leader character.
	 */
	private ?string $leader = null;

	/** @Setup */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			"leaderecho",
			"Repeat the text of the leader",
			"edit",
			"options",
			"1",
			"true;false",
			"1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"leaderecho_color",
			"Color for leader echo",
			"edit",
			"color",
			"<font color=#FFFF00>",
		);
	}

	/**
	 * This command handler sets the leader of the raid.
	 * @HandlesCommand("leader")
	 */
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
	 * This command handler sets a specific leader.
	 * @HandlesCommand("leader (.+)")
	 */
	public function leaderSetCommand(CmdContext $context, PCharacter $leader): void {
		$msg = $this->setLeader($leader(), $context->char->name);
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
	 * This command handler enables leader echoing in private channel.
	 * @HandlesCommand("leaderecho")
	 */
	public function leaderEchoOnCommand(CmdContext $context, bool $on): void {
		if (!$this->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}
		$this->settingManager->save("leaderecho", $on ? "1" : "0");
		$this->chatBot->sendPrivate("Leader echo has been " . $this->getEchoStatusText());
	}

	/**
	 * This command handler shows current echoing state.
	 * @HandlesCommand("leaderecho")
	 */
	public function leaderEchoCommand(CmdContext $context): void {
		if (!$this->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}
		$this->chatBot->sendPrivate("Leader echo is currently " . $this->getEchoStatusText());
	}

	/**
	 * @Event(name="priv",
	 * 	description="Repeats what the leader says in the color of leaderecho_color setting")
	 */
	public function privEvent(AOChatEvent $eventObj): void {
		if (!$this->settingManager->getBool("leaderecho")
			|| $this->leader !== $eventObj->sender
			|| $eventObj->message[0] === $this->settingManager->get("symbol")) {
			return;
		}
		$msg = ($this->settingManager->getString("leaderecho_color")??"<font>") . $eventObj->message . "<end>";
		$this->chatBot->sendPrivate($msg);
	}

	/**
	 * @Event(name="leavePriv",
	 * 	description="Removes leader when the leader leaves the channel")
	 */
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
