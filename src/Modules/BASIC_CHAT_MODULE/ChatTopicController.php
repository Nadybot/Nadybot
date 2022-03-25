<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\{
	AOChatEvent,
	Attributes as NCA,
	CmdContext,
	EventManager,
	ModuleInstance,
	Nadybot,
	SettingManager,
	Text,
	UserStateEvent,
	Util,
};

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "topic",
		accessLevel: "guest",
		description: "Shows Topic",
	),
	NCA\DefineCommand(
		command: ChatTopicController::CMD_TOPIC_SET,
		accessLevel: "rl",
		description: "Changes Topic",
	),

	NCA\Setting\Text(
		name: "topic",
		description: "Topic for Private Channel",
		mode: "noedit",
		defaultValue: "",
	),
	NCA\Setting\Text(
		name: "topic_setby",
		description: "Character who set the topic",
		mode: "noedit",
		defaultValue: "",
	),
	NCA\Setting\Number(
		name: "topic_time",
		description: "Time the topic was set",
		mode: "noedit",
		defaultValue: 0,
	),
	NCA\ProvidesEvent("topic(set)"),
	NCA\ProvidesEvent("topic(clear)")
]
class ChatTopicController extends ModuleInstance {
	public const CMD_TOPIC_SET = "topic set/clear";

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public ChatRallyController $chatRallyController;

	#[NCA\Inject]
	public ChatLeaderController $chatLeaderController;

	#[NCA\Inject]
	public EventManager $eventManager;

	/**
	 * Show the current topic
	 */
	#[NCA\HandlesCommand("topic")]
	public function topicCommand(CmdContext $context): void {
		if ($this->settingManager->getString('topic') === '') {
			$msg = 'No topic set.';
		} else {
			$msg = $this->buildTopicMessage();
		}

		$context->reply($msg);
	}

	/**
	 * Clear the topic
	 */
	#[NCA\HandlesCommand(self::CMD_TOPIC_SET)]
	public function topicClearCommand(CmdContext $context, #[NCA\Str("clear")] string $action): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->setTopic($context->char->name, "");
		$msg = "Topic has been cleared.";
		$context->reply($msg);
		$event = new TopicEvent();
		$event->type = "topic(clear)";
		$event->player = $context->char->name;
		$this->eventManager->fireEvent($event);
	}

	/**
	 * Set a new topic
	 */
	#[NCA\HandlesCommand(self::CMD_TOPIC_SET)]
	public function topicSetCommand(CmdContext $context, string $topic): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->setTopic($context->char->name, $topic);
		$msg = "Topic has been updated.";
		$context->reply($msg);
		$event = new TopicEvent();
		$event->type = "topic(clear)";
		$event->topic = $topic;
		$event->player = $context->char->name;
		$this->eventManager->fireEvent($event);
	}

	#[NCA\Event(
		name: "logOn",
		description: "Shows topic on logon of members"
	)]
	public function logonEvent(UserStateEvent $eventObj): void {
		if ($this->settingManager->getString('topic') === ''
			|| !isset($this->chatBot->guildmembers[$eventObj->sender])
			|| !$this->chatBot->isReady()
			|| !is_string($eventObj->sender)
		) {
			return;
		}
		$msg = $this->buildTopicMessage();
		$this->chatBot->sendMassTell($msg, $eventObj->sender);
	}

	#[NCA\Event(
		name: "joinPriv",
		description: "Shows topic when someone joins the private channel"
	)]
	public function joinPrivEvent(AOChatEvent $eventObj): void {
		if ($this->settingManager->getString('topic') === '' || !is_string($eventObj->sender)) {
			return;
		}
		$msg = $this->buildTopicMessage();
		$this->chatBot->sendMassTell($msg, $eventObj->sender);
	}

	public function setTopic(string $name, string $msg): void {
		$this->settingManager->save("topic_time", (string)time());
		$this->settingManager->save("topic_setby", $name);
		$this->settingManager->save("topic", $msg);

		if (empty($msg)) {
			$this->chatRallyController->clear();
		}
	}

	/**
	 * Builds current topic information message and returns it.
	 */
	public function buildTopicMessage(): string {
		$topicAge = $this->util->unixtimeToReadable(time() - ($this->settingManager->getInt('topic_time')??0), false);
		$topic = $this->settingManager->getString('topic') ?? "&lt;none&gt;";
		$topicCreator = $this->settingManager->getString('topic_setby') ?? "&lt;unknown&gt;";
		$msg = "Topic: <red>{$topic}<end> (set by ".
			$this->text->makeUserlink($topicCreator).
			", <highlight>{$topicAge} ago<end>)";
		return $msg;
	}
}
