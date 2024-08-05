<?php declare(strict_types=1);

namespace Nadybot\Modules\TRADEBOT_MODULE;

use function Safe\preg_match;
use AO\Package;
use Nadybot\Core\Events\{ConnectEvent, ExtJoinPrivRequest, PrivateChannelMsgEvent, RecvMsgEvent};
use Nadybot\Core\ParamClass\PUuid;
use Nadybot\Core\{
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	Config\BotConfig,
	DB,
	Events\LogonEvent,
	Exceptions\StopExecutionException,
	MessageHub,
	ModuleInstance,
	Nadybot,
	ParamClass\PCharacter,
	ParamClass\PColor,
	ParamClass\PRemove,
	Routing\RoutableMessage,
	Routing\Source,
	Safe,
	SettingHandlers\ColorSettingHandler,
	Text,
};
use Nadybot\Modules\COMMENT_MODULE\CommentController;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: 'tradecolor',
		accessLevel: 'mod',
		description: 'Define colors for tradebot tags',
		alias: 'tradecolors',
	),
]
class TradebotController extends ModuleInstance {
	public const NONE = 'None';

	/** @var array<string,array<string,mixed>> */
	private const BOT_DATA = [
		'Darknet' => [
			'join' => ['!register'],
			'leave' => ['!autoinvite off', '!unregister'],
			'match' => '/^\[([a-z]+)\]/i',
			'ignore' => ['/^Unread News/i'],
		],
		'Lightnet' => [
			'join' => ['register', 'autoinvite on'],
			'leave' => ['autoinvite off', 'unregister'],
			'match' => '/^\[([a-z]+)\]/i',
			'ignore' => [],
		],
	];

	/** Name of the bot whose channel to join */
	#[NCA\Setting\Text(
		options: [self::NONE, 'Darknet', 'Lightnet'],
		help: 'tradebot.txt',
	)]
	public string $tradebot = self::NONE;

	/** Show only the following channels (comma-separated) */
	#[NCA\Setting\Text(options: ['None', '*'])]
	public string $tradebotChannels = '*';

	/** Add link to comments if found */
	#[NCA\Setting\Boolean]
	public bool $tradebotAddComments = true;

	/** Use custom colors for tradebots */
	#[NCA\Setting\Boolean]
	public bool $tradebotCustomColors = false;

	/** Custom color for tradebot message body */
	#[NCA\Setting\Color]
	public string $tradebotTextColor = '#89D2E8';

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BuddylistManager $buddylistManager;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private CommentController $commentController;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Event(
		name: ExtJoinPrivRequest::EVENT_MASK,
		description: 'Accept private channel join invitation from the trade bots'
	)]
	public function acceptPrivJoinEvent(ExtJoinPrivRequest $eventObj): void {
		$sender = $eventObj->sender;
		if (!$this->isTradebot($sender)) {
			return;
		}
		$this->logger->notice("Joining {character}'s private channel.", ['character' => $sender]);
		if (null === ($uid = $this->chatBot->getUid($sender))) {
			return;
		}
		$this->chatBot->sendPackage(
			package: new Package\Out\PrivateChannelJoin(channelId: $uid),
		);
		$this->messageHub->registerMessageEmitter(new TradebotChannel($sender . '-*'));
	}

	#[NCA\Event(
		name: ConnectEvent::EVENT_MASK,
		description: 'Add active tradebots to buddylist'
	)]
	public function addTradebotsAsBuddies(ConnectEvent $event): void {
		$activeBots = $this->normalizeBotNames($this->tradebot);
		foreach ($activeBots as $botName) {
			$this->buddylistManager->addName($botName, 'tradebot');
		}
	}

	/**
	 * (un)subscribe from tradebot(s) when they get activated or deactivated
	 *
	 * @param string $setting  Name of the setting that gets changed
	 * @param string $oldValue Old value of that setting
	 * @param string $newValue New value of that setting
	 */
	#[NCA\SettingChangeHandler('tradebot')]
	public function changeTradebot(string $setting, string $oldValue, string $newValue): void {
		if ($setting !== 'tradebot') {
			return;
		}
		$oldBots = $this->normalizeBotNames($oldValue);
		$newBots = $this->normalizeBotNames($newValue);
		$botsToSignOut = array_diff($oldBots, $newBots);
		$botsToSignUp = array_diff($newBots, $oldBots);
		foreach ($botsToSignOut as $botName) {
			if (array_key_exists($botName, self::BOT_DATA)) {
				foreach (self::BOT_DATA[$botName]['leave'] as $cmd) {
					$this->chatBot->logChat('Out. Msg.', $botName, $cmd);
					$this->chatBot->sendRawTell($botName, $cmd);
					$uid = $this->chatBot->getUid($botName);
					if (!isset($uid)) {
						continue;
					}
					$this->chatBot->sendPackage(
						package: new Package\Out\PrivateChannelLeave(
							channelId: $uid,
						)
					);
				}
				$this->buddylistManager->remove($botName, 'tradebot');
			}
		}
		foreach ($botsToSignUp as $botName) {
			if (array_key_exists($botName, self::BOT_DATA)) {
				foreach (self::BOT_DATA[$botName]['join'] as $cmd) {
					$this->chatBot->logChat('Out. Msg.', $botName, $cmd);
					$this->chatBot->sendRawTell($botName, $cmd);
				}
				if ($this->buddylistManager->isOnline($botName)) {
					$this->joinPrivateChannel($botName);
				}
				EventLoop::queue($this->buddylistManager->addName(...), $botName, 'tradebot');
			}
		}
		if ($this->messageHub->hasRouteFor(Source::TRADEBOT)) {
			return;
		}
		if (count($botsToSignUp)) {
			$msg = 'Please make sure to use <highlight><symbol>route add tradebot(*) -&gt; aopriv<end> '.
				'or <highlight><symbol>route add tradebot(*) -&gt; aoorg<end> to '.
				'set up message routing between the tradebot and your org- and/or private channel.';
			if (strlen($this->config->general->orgName)) {
				$this->chatBot->sendGuild($msg, true);
			} else {
				$this->chatBot->sendPrivate($msg, true);
			}
		}
	}

	#[NCA\Event(
		name: LogonEvent::EVENT_MASK,
		description: 'Join tradebot private channels'
	)]
	public function tradebotOnlineEvent(LogonEvent $eventObj): void {
		if ($this->isTradebot($eventObj->sender)) {
			$this->joinPrivateChannel($eventObj->sender);
		}
	}

	/** Check if the given name is one of the configured tradebots */
	public function isTradebot(string $botName): bool {
		$tradebotNames = $this->normalizeBotNames($this->tradebot);
		foreach ($tradebotNames as $tradebotName) {
			if (preg_match("/^\Q{$tradebotName}\E\d*$/", $botName)) {
				return true;
			}
		}
		return false;
	}

	/** @throws StopExecutionException */
	#[NCA\Event(
		name: PrivateChannelMsgEvent::EVENT_MASK,
		description: 'Relay messages from the tradebot to org/private channel'
	)]
	public function receiveRelayMessageExtPrivEvent(PrivateChannelMsgEvent $eventObj): void {
		if (!$this->isTradebot($eventObj->channel)
			|| !$this->isTradebot($eventObj->sender)) {
			return;
		}
		$this->processIncomingTradeMessage($eventObj->channel, $eventObj->message);
		throw new StopExecutionException();
	}

	#[NCA\Event(
		name: RecvMsgEvent::EVENT_MASK,
		description: 'Relay incoming tells from the tradebots to org/private channel'
	)]
	public function receiveMessageEvent(RecvMsgEvent $eventObj): void {
		if (!$this->isTradebot($eventObj->sender)) {
			return;
		}
		$this->processIncomingTradebotMessage($eventObj->sender, $eventObj->message);
		throw new StopExecutionException();
	}

	/** Relay incoming tell-messages of tradebots to org/priv chat, so we can see errors */
	public function processIncomingTradebotMessage(string $sender, string $message): void {
		$baseSender = Safe::pregReplace("/\d+$/", '', $sender);
		$ignorePattern = self::BOT_DATA[$baseSender]['ignore'] ?? [];
		$strippedMessage = strip_tags($message);
		foreach ($ignorePattern as $ignore) {
			if (preg_match($ignore, $strippedMessage)) {
				return;
			}
		}
		$message = "Received message from Tradebot <highlight>{$sender}<end>: {$message}";
	}

	/**
	 * Relay incoming priv-messages of tradebots to org/priv chat,
	 * but filter out join- and leave-messages of people.
	 */
	public function processIncomingTradeMessage(string $sender, string $message): void {
		// Only relay messages starting with something in square brackets
		$match = self::BOT_DATA[$sender]['match'];
		if (!count($matches = Safe::pregMatch($match, strip_tags($message)))
			|| !$this->isSubscribedTo($matches[1])) {
			return;
		}
		if ($this->tradebotCustomColors) {
			$message = $this->colorizeMessage($sender, $message);
		}
		if ($this->tradebotAddComments) {
			$message = $this->addCommentsToMessage($message);
		}
		$rMessage = new RoutableMessage($message);
		$source = new Source(Source::TRADEBOT, $sender . "-{$matches[1]}");
		$rMessage->prependPath($source);
		$this->messageHub->handle($rMessage);
	}

	/** List the currently custom defined colors */
	#[NCA\HandlesCommand('tradecolor')]
	public function listTradecolorsCommand(CmdContext $context): void {
		$allColors = $this->db->table(TradebotColors::getTable())
			->orderBy('tradebot')
			->orderBy('id')
			->asObj(TradebotColors::class);
		if ($allColors->isEmpty()) {
			$context->reply('No colors have been defined yet.');
			return;
		}

		/** @var array<string,list<TradebotColors>> */
		$colorDefs = $allColors->groupBy('tradebot')->toArray();
		$blob = '';
		foreach ($colorDefs as $tradebot => $colors) {
			$blob = "<pagebreak><header2>{$tradebot}<end>\n";
			foreach ($colors as $color) {
				if (!isset($color->id)) {
					continue;
				}
				$blob .= "<tab>[{$color->channel}]: <highlight>#{$color->color}<end><tab>".
					"<font color='#{$color->color}'>[Example Tag]</font> ".
					'[' . Text::makeChatcmd(
						'remove',
						"/tell <myname> tradecolor rem {$color->id}"
					) . '] '.
					'[' . Text::makeChatcmd(
						'change',
						"/tell <myname> tradecolor pick {$tradebot} {$color->channel}"
					) . "]\n";
			}
			$blob .= "\n";
		}
		$msg = $this->text->makeBlob(
			'Tradebot colors (' . count($allColors) . ')',
			$blob
		);
		$context->reply($msg);
	}

	/** Remove a custom defined color */
	#[NCA\HandlesCommand('tradecolor')]
	public function remTradecolorCommand(CmdContext $context, PRemove $action, PUuid $id): void {
		$id = $id();
		if (!$this->db->table(TradebotColors::getTable())->delete($id)) {
			$context->reply("Tradebot color <highlight>{$id}<end> doesn't exist.");
			return;
		}
		$context->reply("Tradebot color <highlight>{$id}<end> deleted.");
	}

	/** Configure the tag-colors, based on the channel and the tradebot */
	#[NCA\HandlesCommand('tradecolor')]
	#[NCA\Help\Example('<symbol>tradecolor set Darknet lootrights #FFFFFF')]
	#[NCA\Help\Example('<symbol>tradecolor set Darknet pv? #0000FF')]
	#[NCA\Help\Example('<symbol>tradecolor set Darknet * #9900FF')]
	#[NCA\Help\Epilogue(
		"<header2>Wildcards<end>\n\n".
		"<highlight>&lt;tag&gt;<end> can use the following placeholders:\n".
		"<tab><highlight>*<end> (any number of any character)\n".
		"<tab><highlight>?<end> (any single character).\n\n".
		"<header2>Settings<end>\n\n".
		'To be able to define your own colors, you first have to '.
		"<a href='chatcmd:///tell <myname> settings save tradebot_custom_colors 1'>enable custom colors</a>.\n".
		'To configure the main text color, '.
		"<a href='chatcmd:///tell <myname> settings change tradebot_text_color'>change this setting</a>"
	)]
	public function addTradecolorCommand(
		CmdContext $context,
		#[NCA\Str('set', 'add')] string $action,
		PCharacter $tradeBot,
		string $tag,
		PColor $color
	): void {
		$tag = strtolower($tag);
		$color = $color->getCode();
		if (!array_key_exists($tradeBot(), self::BOT_DATA)) {
			$context->reply("<highlight>{$tradeBot}<end> is not a supported tradebot.");
			return;
		}
		if (strlen($tag) > 25) {
			$context->reply('Your tag is longer than the supported 25 characters.');
			return;
		}
		$colorDef = new TradebotColors(
			channel: $tag,
			tradebot: $tradeBot(),
			color: $color,
		);
		$oldValue = $this->getTagColor($tradeBot(), $tag);
		if (isset($oldValue) && $oldValue->channel === $colorDef->channel) {
			$colorDef->id = $oldValue->id;
			$this->db->update($colorDef);
		} else {
			$this->db->insert($colorDef);
		}
		$context->reply(
			"Color for <highlight>{$tradeBot} &gt; [{$tag}]<end> set to ".
			"<font color='#{$color}'>#{$color}</font>."
		);
	}

	/** Pick a tag-color, based on the channel and the tradebot */
	#[NCA\HandlesCommand('tradecolor')]
	public function pickTradecolorCommand(
		CmdContext $context,
		#[NCA\Str('pick')] string $action,
		PCharacter $tradeBot,
		string $tag
	): void {
		$tag = strtolower($tag);
		if (!array_key_exists($tradeBot(), self::BOT_DATA)) {
			$context->reply("{$tradeBot} is not a supported tradebot.");
			return;
		}
		if (strlen($tag) > 25) {
			$context->reply('Your tag name is too long.');
			return;
		}
		$colorList = ColorSettingHandler::getExampleColors();
		$blob = "<header2>Pick a color for {$tradeBot} &gt; [{$tag}]<end>\n";
		foreach ($colorList as $color => $name) {
			$blob .= "<tab>[<a href='chatcmd:///tell <myname> tradecolor set {$tradeBot} {$tag} {$color}'>Pick this one</a>] <font color='{$color}'>Example Text</font> ({$name})\n";
		}
		$msg = $this->text->makeBlob(
			'Choose from colors (' . count($colorList) . ')',
			$blob
		);
		$context->reply($msg);
	}

	/**
	 * Convert the colon-separated list of botnames into a proper array
	 *
	 * @param string $botNames Colon-separated list of botnames
	 *
	 * @return list<string>
	 */
	protected function normalizeBotNames(string $botNames): array {
		return array_values(array_diff(
			array_map(
				'ucfirst',
				explode(
					';',
					strtolower($botNames)
				)
			),
			['', static::NONE]
		));
	}

	/** Join the private channel of the tradebot $botName */
	protected function joinPrivateChannel(string $botName): void {
		$cmd = '!join';
		$this->chatBot->logChat('Out. Msg.', $botName, $cmd);
		$this->chatBot->sendRawTell($botName, $cmd);
	}

	protected function colorizeMessage(string $tradeBot, string $message): string {
		if (!count($matches = Safe::pregMatch("/^.*?\[(.+?)\](.+)$/s", $message))) {
			return $message;
		}
		$tag = strip_tags($matches[1]);

		/** @var string */
		$text = Safe::pregReplace("/^(\s|<\/?font.*?>)*/s", '', $matches[2]);
		$textColor = $this->tradebotTextColor;
		$tagColor = $this->getTagColor($tradeBot, $tag);
		$tagColor = isset($tagColor) ? "<font color='#{$tagColor->color}'>" : '';
		return "{$tagColor}[{$tag}]<end> {$textColor}{$text}";
	}

	protected function getTagColor(string $tradeBot, string $tag): ?TradebotColors {
		$query = $this->db->table(TradebotColors::getTable())
			->where('tradebot', $tradeBot);

		$colorDefs = $query->orderByDesc($query->raw($query->colFunc('LENGTH', 'channel')))
			->asObj(TradebotColors::class);
		foreach ($colorDefs as $colorDef) {
			if (fnmatch($colorDef->channel, $tag, \FNM_CASEFOLD)) {
				return $colorDef;
			}
		}
		return null;
	}

	protected function addCommentsToMessage(string $message): string {
		if (!count($match = Safe::pregMatch("/<a\s+href\s*=\s*['\"]?user:\/\/([A-Z][a-z0-9-]+)/i", $message))) {
			return $message;
		}
		$numComments = $this->commentController->countComments(null, $match[1]);
		if ($numComments === 0) {
			return $message;
		}
		$comText = ($numComments > 1) ? "{$numComments} Comments" : '1 Comment';
		$blob = Text::makeChatcmd("Read {$comText}", "/tell <myname> comments get {$match[1]}").
			' if you have the necessary access level.';
		$message .= ' [' . ((array)$this->text->makeBlob($comText, $blob))[0] . ']';
		return $message;
	}

	/** Check if the message is from a tradenet channel that we are subscribed to */
	protected function isSubscribedTo(string $channel): bool {
		$channelString = $this->tradebotChannels;
		if ($channelString === static::NONE) {
			return false;
		}
		$subbed = explode(',', $channelString);
		foreach ($subbed as $subChannel) {
			if (fnmatch($subChannel, $channel, \FNM_CASEFOLD)) {
				return true;
			}
		}
		return false;
	}
}
