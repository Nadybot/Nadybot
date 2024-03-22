<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\Event\JoinMyPrivEvent;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	EventManager,
	LogonEvent,
	ModuleInstance,
	Nadybot,
	SettingManager,
	Text,
	Util,
};

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'topic',
		accessLevel: 'guest',
		description: 'Shows Topic',
	),
	NCA\DefineCommand(
		command: ChatTopicController::CMD_TOPIC_SET,
		accessLevel: 'rl',
		description: 'Changes Topic',
	),

	NCA\ProvidesEvent(TopicSetEvent::class),
	NCA\ProvidesEvent(TopicClearEvent::class)
]
class ChatTopicController extends ModuleInstance {
	public const CMD_TOPIC_SET = 'topic set/clear';

	/** Topic for Private Channel */
	#[NCA\Setting\Text(mode: 'noedit')]
	public string $topic = '';

	/** Character who set the topic */
	#[NCA\Setting\Text(mode: 'noedit')]
	public string $topicSetby = '';

	/** Time the topic was set */
	#[NCA\Setting\Timestamp(mode: 'noedit')]
	public int $topicTime = 0;

	/** Color of the topic */
	#[NCA\Setting\Color]
	public string $topicColor = '#FF0000';

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private Util $util;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private ChatRallyController $chatRallyController;

	#[NCA\Inject]
	private ChatLeaderController $chatLeaderController;

	#[NCA\Inject]
	private EventManager $eventManager;

	/** Show the current topic */
	#[NCA\HandlesCommand('topic')]
	public function topicCommand(CmdContext $context): void {
		if ($this->topic === '') {
			$msg = 'No topic set.';
		} else {
			$msg = $this->buildTopicMessage();
		}

		$context->reply($msg);
	}

	/** Clear the topic */
	#[NCA\HandlesCommand(self::CMD_TOPIC_SET)]
	public function topicClearCommand(CmdContext $context, #[NCA\Str('clear')] string $action): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}

		$event = new TopicClearEvent(
			player: $context->char->name,
			topic: $this->topic,
		);
		$this->setTopic($context->char->name, '');
		$msg = 'Topic has been cleared.';
		$context->reply($msg);
		$this->eventManager->fireEvent($event);
	}

	/** Set a new topic */
	#[NCA\HandlesCommand(self::CMD_TOPIC_SET)]
	public function topicSetCommand(CmdContext $context, string $topic): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}

		$this->setTopic($context->char->name, $topic);
		$msg = 'Topic has been updated.';
		$context->reply($msg);
		$event = new TopicSetEvent(
			topic: $topic,
			player: $context->char->name,
		);
		$this->eventManager->fireEvent($event);
	}

	#[NCA\Event(
		name: LogonEvent::EVENT_MASK,
		description: 'Shows topic on logon of members'
	)]
	public function logonEvent(LogonEvent $eventObj): void {
		if ($this->topic === ''
			|| !isset($this->chatBot->guildmembers[$eventObj->sender])
			|| !$this->chatBot->isReady()
			|| !is_string($eventObj->sender)
			|| $eventObj->wasOnline !== false
		) {
			return;
		}
		$msg = $this->buildTopicMessage();
		$this->chatBot->sendMassTell($msg, $eventObj->sender);
	}

	#[NCA\Event(
		name: JoinMyPrivEvent::EVENT_MASK,
		description: 'Shows topic when someone joins the private channel'
	)]
	public function joinPrivEvent(JoinMyPrivEvent $eventObj): void {
		if ($this->topic === '' || !is_string($eventObj->sender)) {
			return;
		}
		$msg = $this->buildTopicMessage();
		$this->chatBot->sendMassTell($msg, $eventObj->sender);
	}

	public function setTopic(string $name, string $msg): void {
		$this->settingManager->save('topic_time', (string)time());
		$this->settingManager->save('topic_setby', $name);
		$this->settingManager->save('topic', $msg);

		if (empty($msg)) {
			$this->chatRallyController->clear();
		}
	}

	/** Builds current topic information message and returns it. */
	public function buildTopicMessage(): string {
		$topicAge = $this->util->unixtimeToReadable(time() - $this->topicTime, false);
		$topic = $this->topic;
		$topicCreator = $this->topicSetby;
		$msg = "Topic: {$this->topicColor}{$topic}<end> (set by ".
			$this->text->makeUserlink($topicCreator).
			", <highlight>{$topicAge} ago<end>)";
		return $msg;
	}
}
