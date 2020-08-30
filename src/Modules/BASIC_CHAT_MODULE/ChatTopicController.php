<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\{
	CommandReply,
	Event,
	Nadybot,
	SettingManager,
	Text,
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
	 * @Matches("/^topic$/i")
	 */
	public function topicCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if ($this->settingManager->get('topic') === '') {
			$msg = 'No topic set.';
		} else {
			$msg = $this->buildTopicMessage();
		}

		$sendto->reply($msg);
	}

	/**
	 * This command handler clears topic.
	 * @HandlesCommand("topic .+")
	 * @Matches("/^topic clear$/i")
	 */
	public function topicClearCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->setTopic($sender, "");
		$msg = "Topic has been cleared.";
		$sendto->reply($msg);
	}

	/**
	 * This command handler sets topic.
	 * @HandlesCommand("topic .+")
	 * @Matches("/^topic (.+)$/i")
	 */
	public function topicSetCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->setTopic($sender, $args[1]);
		$msg = "Topic has been updated.";
		$sendto->reply($msg);
	}

	/**
	 * @Event("logOn")
	 * @Description("Shows topic on logon of members")
	 */
	public function logonEvent(Event $eventObj): void {
		if ($this->settingManager->get('topic') === '') {
			return;
		}
		if (isset($this->chatBot->guildmembers[$eventObj->sender]) && $this->chatBot->isReady()) {
			$msg = $this->buildTopicMessage();
			$this->chatBot->sendTell($msg, $eventObj->sender);
		}
	}

	/**
	 * @Event("joinPriv")
	 * @Description("Shows topic when someone joins the private channel")
	 */
	public function joinPrivEvent(Event $eventObj): void {
		if ($this->settingManager->get('topic') === '') {
			return;
		}
		$msg = $this->buildTopicMessage();
		$this->chatBot->sendTell($msg, $eventObj->sender);
	}
	
	public function setTopic(string $name, string $msg): void {
		$this->settingManager->save("topic_time", time());
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
		$date_string = $this->util->unixtimeToReadable(time() - $this->settingManager->getInt('topic_time'), false);
		$topic = $this->settingManager->get('topic');
		$set_by = $this->settingManager->get('topic_setby');
		$msg = "Topic: <red>{$topic}<end> (set by ".
			$this->text->makeUserlink($set_by).
			", <highlight>{$date_string} ago<end>)";
		return $msg;
	}
}
