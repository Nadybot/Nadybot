<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	EventManager,
	Events\JoinMyPrivEvent,
	Events\LogonEvent,
	ModuleInstance,
	MyOrg,
	Nadybot,
	SettingManager,
	Text,
	Types\SettingMode,
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
]
class ChatTopicController extends ModuleInstance {
	public const CMD_TOPIC_SET = 'topic set/clear';

	/** Topic for Private Channel */
	#[NCA\Setting\Text(mode: SettingMode::NoEdit)]
	public string $topic = '';

	/** Character who set the topic */
	#[NCA\Setting\Text(mode: SettingMode::NoEdit)]
	public string $topicSetby = '';

	/** Time the topic was set */
	#[NCA\Setting\Timestamp(mode: SettingMode::NoEdit)]
	public int $topicTime = 0;

	/** Color of the topic */
	#[NCA\Setting\Color]
	public string $topicColor = '#FF0000';

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private ChatRallyController $chatRallyController;

	#[NCA\Inject]
	private ChatLeaderController $chatLeaderController;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private MyOrg $myOrg;

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
	public function topicClearCommand(CmdContext $context, #[NCA\Parameter\Str('clear')] string $action): void {
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

	/** Shows topic on logon of members */
	#[NCA\HandlesEvent]
	public function logonEvent(LogonEvent $eventObj): void {
		if ($this->topic === ''
			|| !$this->myOrg->isMember($eventObj->sender)
			|| !$this->chatBot->isReady()
			|| $eventObj->wasOnline !== false
		) {
			return;
		}
		$msg = $this->buildTopicMessage();
		$this->chatBot->sendMassTell($msg, $eventObj->sender);
	}

	/** Shows topic when someone joins the private channel */
	#[NCA\HandlesEvent]
	public function joinPrivEvent(JoinMyPrivEvent $eventObj): void {
		if ($this->topic === '') {
			return;
		}
		$msg = $this->buildTopicMessage();
		$this->chatBot->sendMassTell($msg, $eventObj->sender);
	}

	public function setTopic(string $name, string $msg): void {
		$this->settingManager->save('topic_time', (string)time());
		$this->settingManager->save('topic_setby', $name);
		$this->settingManager->save('topic', $msg);

		if ($msg === '') {
			$this->chatRallyController->clear();
		}
	}

	/** Builds current topic information message and returns it. */
	public function buildTopicMessage(): string {
		$topicAge = Util::unixtimeToReadable(time() - $this->topicTime, false);
		$topic = $this->topic;
		$topicCreator = $this->topicSetby;
		$msg = "Topic: {$this->topicColor}{$topic}<end> (set by ".
			Text::makeUserlink($topicCreator).
			", <highlight>{$topicAge} ago<end>)";
		return $msg;
	}
}
