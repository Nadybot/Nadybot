<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\{
	AOChatEvent,
	CmdContext,
	EventManager,
	Nadybot,
	SettingManager,
	Text,
	UserStateEvent,
	Util,
};

/**
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'topic',
 *		accessLevel = 'all',
 *		description = 'Shows Topic',
 *		help        = 'topic.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'topic .+',
 *		accessLevel = 'rl',
 *		description = 'Changes Topic',
 *		help        = 'topic.txt'
 *	)
 *	@ProvidesEvent("topic(set)")
 *	@ProvidesEvent("topic(clear)")
 */
class ChatTopicController {

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
	public Util $util;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public ChatRallyController $chatRallyController;

	/** @Inject */
	public ChatLeaderController $chatLeaderController;

	/** @Inject */
	public EventManager $eventManager;

	/** @Setup */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			"topic",
			"Topic for Private Channel",
			"noedit",
			"text",
			""
		);
		$this->settingManager->add(
			$this->moduleName,
			"topic_setby",
			"Character who set the topic",
			"noedit",
			"text",
			""
		);
		$this->settingManager->add(
			$this->moduleName,
			"topic_time",
			"Time the topic was set",
			"noedit",
			"number",
			"0"
		);
	}
	/**
	 * This command handler shows topic.
	 * @HandlesCommand("topic")
	 */
	public function topicCommand(CmdContext $context): void {
		if ($this->settingManager->getString('topic') === '') {
			$msg = 'No topic set.';
		} else {
			$msg = $this->buildTopicMessage();
		}

		$context->reply($msg);
	}

	/**
	 * This command handler clears topic.
	 * @HandlesCommand("topic .+")
	 * @Mask $action clear
	 */
	public function topicClearCommand(CmdContext $context, string $action): void {
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
	 * This command handler sets topic.
	 * @HandlesCommand("topic .+")
	 */
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

	/**
	 * @Event("logOn")
	 * @Description("Shows topic on logon of members")
	 */
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

	/**
	 * @Event("joinPriv")
	 * @Description("Shows topic when someone joins the private channel")
	 */
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
