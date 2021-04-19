<?php declare(strict_types=1);

namespace Nadybot\Modules\TRADEBOT_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	BuddylistManager,
	ColorSettingHandler,
	CommandAlias,
	CommandReply,
	DB,
	Event,
	LoggerWrapper,
	StopExecutionException,
	Nadybot,
	SettingManager,
	Text,
	UserStateEvent,
};
use Nadybot\Modules\COMMENT_MODULE\CommentController;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'tradecolor',
 *		accessLevel = 'mod',
 *		description = 'Define colors for tradebot tags',
 *		help        = 'tradecolor.txt'
 *	)
 */
class TradebotController {

	public const DB_TABLE = "tradebot_colors_<myname>";

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public BuddylistManager $buddylistManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Inject */
	public CommentController $commentController;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public DB $db;

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
		]
	];

	/** @Setup */
	public function setup(): void {
		$this->commandAlias->register($this->moduleName, "tradecolor", "tradecolors");
		$this->settingManager->add(
			$this->moduleName,
			'tradebot',
			"Name of the bot whose channel to join",
			"edit",
			"text",
			"None",
			"None;" . implode(';', array_keys(self::BOT_DATA)),
			'',
			"mod",
			"tradebot.txt"
		);
		$this->settingManager->add(
			$this->moduleName,
			"tradebot_channel_spam",
			"Show Tradebot messages in",
			"edit",
			"options",
			"3",
			"Off;Priv;Org;Priv+Org",
			"0;1;2;3",
			"mod"
		);
		$this->settingManager->add(
			$this->moduleName,
			"tradebot_channels",
			"Show only the following channels (comma-separated)",
			"edit",
			"text",
			"*",
			"None;*"
		);

		$this->settingManager->add(
			$this->moduleName,
			'tradebot_add_comments',
			'Add link to comments if found',
			'edit',
			'options',
			'1',
			'true;false',
			'1;0',
			'mod'
		);

		$this->settingManager->add(
			$this->moduleName,
			'tradebot_custom_colors',
			'Use custom colors for tradebots',
			'edit',
			'options',
			'0',
			'true;false',
			'1;0',
			'mod'
		);

		$this->settingManager->add(
			$this->moduleName,
			'tradebot_text_color',
			'Custom color for tradebot message body',
			'edit',
			'color',
			"<font color='#89D2E8'>"
		);

		$this->settingManager->registerChangeListener(
			'tradebot',
			[$this, 'changeTradebot']
		);

		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
	}

	/**
	 * @Event("Connect")
	 * @Description("Add active tradebots to buddylist")
	 */
	public function addTradebotsAsBuddies(): void {
		$activeBots = $this->normalizeBotNames($this->settingManager->getString('tradebot'));
		foreach ($activeBots as $botName) {
			$this->buddylistManager->add($botName, "tradebot");
		}
	}

	/**
	 * Convert the colon-separated list of botnames into a proper array
	 *
	 * @param string $botNames Colon-separated list of botnames
	 * @return string[]
	 */
	protected function normalizeBotNames(string $botNames): array {
		return array_diff(
			array_map(
				'ucfirst',
				explode(
					';',
					strtolower($botNames)
				)
			),
			['', 'None']
		);
	}

	/**
	 * (un)subscribe from tradebot(s) when they get activated or deactivated
	 *
	 * @param string $setting Name of the setting that gets changed
	 * @param string $oldValue Old value of that setting
	 * @param string $newValue New value of that setting
	 * @return void
	 */
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
					$this->logger->logChat("Out. Msg.", $botName, $cmd);
					$this->chatBot->send_tell($botName, $cmd, "\0", AOC_PRIORITY_MED);
					$this->chatBot->privategroup_leave($botName);
				}
				$this->buddylistManager->remove($botName, "tradebot");
			}
		}
		foreach ($botsToSignUp as $botName) {
			if (array_key_exists($botName, self::BOT_DATA)) {
				foreach (self::BOT_DATA[$botName]['join'] as $cmd) {
					$this->logger->logChat("Out. Msg.", $botName, $cmd);
					$this->chatBot->send_tell($botName, $cmd, "\0", AOC_PRIORITY_MED);
				}
				if ($this->buddylistManager->isOnline($botName)) {
					$this->joinPrivateChannel($botName);
				}
				$this->buddylistManager->add($botName, "tradebot");
			}
		}
	}

	/**
	 * @Event("logOn")
	 * @Description("Join tradebot private channels")
	 */
	public function tradebotOnlineEvent(UserStateEvent $eventObj): void {
		if ($this->isTradebot($eventObj->sender)) {
			$this->joinPrivateChannel($eventObj->sender);
		}
	}

	/** Join the private channel of the tradebot $botName */
	protected function joinPrivateChannel(string $botName): void {
		$cmd = "!join";
		$this->logger->logChat("Out. Msg.", $botName, $cmd);
		$this->chatBot->send_tell($botName, $cmd, AOC_PRIORITY_MED);
	}

	/**
	 * Check if the given name is one of the configured tradebots
	 */
	public function isTradebot(string $botName): bool {
		$tradebotNames = $this->normalizeBotNames($this->settingManager->getString('tradebot'));
		foreach ($tradebotNames as $tradebotName) {
			if (preg_match("/^\Q$tradebotName\E\d*$/", $botName)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @Event("extPriv")
	 * @Description("Relay messages from the tradebot to org/private channel")
	 *
	 * @throws StopExecutionException
	 */
	public function receiveRelayMessageExtPrivEvent(Event $eventObj): void {
		if (!$this->isTradebot($eventObj->channel)
			|| !$this->isTradebot($eventObj->sender)) {
			return;
		}
		$this->processIncomingTradeMessage($eventObj->channel, $eventObj->message);
		throw new StopExecutionException();
	}

	/**
	 * @Event("msg")
	 * @Description("Relay incoming tells from the tradebots to org/private channel")
	 */
	public function receiveMessageEvent(Event $eventObj): void {
		if (!$this->isTradebot($eventObj->sender)) {
			return;
		}
		$this->processIncomingTradebotMessage($eventObj->sender, $eventObj->message);
		throw new StopExecutionException();
	}

	/**
	 * Relay incoming tell-messages of tradebots to org/priv chat, so we can see errors
	 */
	public function processIncomingTradebotMessage(string $sender, string $message): void {
		$baseSender = preg_replace("/\d+$/", "", $sender);
		$ignorePattern = self::BOT_DATA[$baseSender]['ignore'] ?? [];
		$strippedMessage = strip_tags($message);
		foreach ($ignorePattern as $ignore) {
			if (preg_match($ignore, $strippedMessage)) {
				return;
			}
		}
		$message = "Received message from Tradebot <highlight>$sender<end>: $message";
		$this->chatBot->sendGuild($message, true);
		if ($this->settingManager->getBool("guest_relay")) {
			$this->chatBot->sendPrivate($message, true);
		}
	}

	/**
	 * Relay incoming priv-messages of tradebots to org/priv chat,
	 * but filter out join- and leave-messages of people.
	 */
	public function processIncomingTradeMessage(string $sender, string $message) {
		// Only relay messages starting with something in square brackets
		$match = self::BOT_DATA[$sender]["match"];
		if (!preg_match($match, strip_tags($message), $matches)
			|| !$this->isSubscribedTo($matches[1])) {
			return;
		}
		if ($this->settingManager->getBool('tradebot_custom_colors')) {
			$message = $this->colorizeMessage($sender, $message);
		}
		if ($this->settingManager->getBool('tradebot_add_comments')) {
			$message = $this->addCommentsToMessage($message);
		}

		if ($this->settingManager->getInt("tradebot_channel_spam") & 2) {
			$this->chatBot->sendGuild($message, true);
		}
		if ($this->settingManager->getInt("tradebot_channel_spam") & 1) {
			$this->chatBot->sendPrivate($message, true);
		}
	}

	protected function colorizeMessage(string $tradeBot, string $message): string {
		if (!preg_match("/^.*?\[(.+?)\](.+)$/s", $message, $matches)) {
			return $message;
		}
		$tag = strip_tags($matches[1]);
		$text = preg_replace("/^(\s|<\/?font.*?>)*/s", "", $matches[2]);
		$textColor = $this->settingManager->getString('tradebot_text_color');
		$tagColor = $this->getTagColor($tradeBot, $tag);
		$tagColor = isset($tagColor) ? "<font color='#{$tagColor->color}'>" : "";
		return "{$tagColor}[{$tag}]<end> {$textColor}{$text}";
	}

	protected function getTagColor(string $tradeBot, string $tag): ?TradebotColors {
		$query = $this->db->table(self::DB_TABLE)
			->where("tradebot", $tradeBot);
		/** @var Collection<TradebotColors> */
		$colorDefs = $query->orderByDesc($query->colFunc("LENGTH", "channel"))
			->asObj(TradebotColors::class);
		foreach ($colorDefs as $colorDef) {
			if (fnmatch($colorDef->channel, $tag, FNM_CASEFOLD)) {
				return $colorDef;
			}
		}
		return null;
	}

	protected function addCommentsToMessage(string $message): string {
		if (!preg_match("/<a\s+href\s*=\s*['\"]?user:\/\/([A-Z][a-z0-9-]+)/i", $message, $match)) {
			return $message;
		}
		$numComments = $this->commentController->countComments(null, $match[1]);
		if ($numComments === 0) {
			return $message;
		}
		$comText = ($numComments > 1) ? "$numComments Comments" : "1 Comment";
		$blob = $this->text->makeChatcmd("Read {$comText}", "/tell <myname> comments get {$match[1]}").
			" if you have the necessary access level.";
		$message .= " [" . $this->text->makeBlob($comText, $blob) . "]";
		return $message;
	}

	/**
	 * Check if the message is from a tradenet channel that we are subscribed to
	 */
	protected function isSubscribedTo(string $channel): bool {
		$channelString = $this->settingManager->getString('tradebot_channels');
		if ($channelString === 'None') {
			return false;
		}
		$subbed = explode(",", $channelString);
		foreach ($subbed as $subChannel) {
			if (fnmatch($subChannel, $channel, FNM_CASEFOLD)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @Event("extJoinPrivRequest")
	 * @Description("Accept private channel join invitation from the trade bots")
	 */
	public function acceptPrivJoinEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		if (!$this->isTradebot($sender)) {
			return;
		}
		$this->logger->log('INFO', "Joining {$sender}'s private channel.");
		$this->chatBot->privategroup_join($sender);
	}

	/**
	 * @HandlesCommand("tradecolor")
	 * @Matches("/^tradecolor$/i")
	 */
	public function listTradecolorsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var Collection<TradebotColors> */
		$colors = $this->db->table(self::DB_TABLE)
			->orderBy("tradebot")
			->orderBy("id")
			->asObj(TradebotColors::class);
		if ($colors->isEmpty()) {
			$sendto->reply("No colors have been defined yet.");
			return;
		}
		/** @var array<string,TradebotColors[]> */
		$colorDefs = $colors->groupBy("tradebot")->toArray();
		$blob = "";
		foreach ($colorDefs as $tradebot => $colors) {
			$blob = "<pagebreak><header2>{$tradebot}<end>\n";
			foreach ($colors as $color) {
				$blob .= "<tab>[{$color->channel}]: <highlight>#{$color->color}<end><tab>".
					"<font color='#{$color->color}'>[Example Tag]</font> ".
					"[" . $this->text->makeChatcmd(
						"remove",
						"/tell <myname> tradecolor rem {$color->id}"
					) . "] ".
					"[" . $this->text->makeChatcmd(
						"change",
						"/tell <myname> tradecolor pick {$tradebot} {$color->channel}"
					) . "]\n";
			}
			$blob .= "\n";
		}
		$msg = $this->text->makeBlob(
			"Tradebot colors (" . count($colors) . ")",
			$blob
		);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("tradecolor")
	 * @Matches("/^tradecolor\s+(?:rem|del|remove|delete|rm)\s+(\d+)$/i")
	 */
	public function remTradecolorCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];
		if (!$this->db->table(self::DB_TABLE)->delete($id)) {
			$sendto->reply("Tradebot color <highlight>#{$id}<end> doesn't exist.");
			return;
		}
		$sendto->reply("Tradebot color <highlight>#{$id}<end> deleted.");
	}

	/**
	 * @HandlesCommand("tradecolor")
	 * @Matches("/^tradecolor\s+(?:add|set)\s+([^ ]+)\s+(.+)\s+#?([0-9a-f]{6})$/i")
	 */
	public function addTradecolorCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$tradeBot = ucfirst(strtolower($args[1]));
		$tag = strtolower($args[2]);
		$color = strtoupper($args[3]);
		if (!array_key_exists($tradeBot, self::BOT_DATA)) {
			$sendto->reply("<highlight>{$tradeBot}<end> is not a supported tradebot.");
			return;
		}
		if (strlen($tag) > 25) {
			$sendto->reply("Your tag is longer than the supported 25 characters.");
			return;
		}
		$colorDef = new TradebotColors();
		$colorDef->channel = $tag;
		$colorDef->tradebot = $tradeBot;
		$colorDef->color = $color;
		$oldValue = $this->getTagColor($tradeBot, $tag);
		if (isset($oldValue) && $oldValue->channel === $colorDef->channel) {
			$colorDef->id = $oldValue->id;
			$this->db->update(self::DB_TABLE, "id", $colorDef);
		} else {
			$colorDef->id = $this->db->insert(self::DB_TABLE, $colorDef);
		}
		$sendto->reply(
			"Color for <highlight>{$tradeBot} &gt; [{$tag}]<end> set to ".
			"<font color='#{$color}'>#{$color}</font>."
		);
	}

	/**
	 * @HandlesCommand("tradecolor")
	 * @Matches("/^tradecolor\s+(?:pick)\s+([^ ]+)\s+(.+)$/i")
	 */
	public function pickTradecolorCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$tradeBot = ucfirst(strtolower($args[1]));
		$tag = strtolower($args[2]);
		if (!array_key_exists($tradeBot, self::BOT_DATA)) {
			$sendto->reply("{$tradeBot} is not a supported tradebot.");
			return;
		}
		if (strlen($tag) > 25) {
			$sendto->reply("Your tag name is too long.");
			return;
		}
		$colorList = ColorSettingHandler::getExampleColors();
		$blob = "<header2>Pick a color for {$tradeBot} &gt; [{$tag}]<end>\n";
		foreach ($colorList as $color => $name) {
			$blob .= "<tab>[<a href='chatcmd:///tell <myname> tradecolor set {$tradeBot} {$tag} {$color}'>Pick this onet</a>] <font color='{$color}'>Example Text</font> ({$name})\n";
		}
		$msg = $this->text->makeBlob(
			"Choose from colors (" . count($colorList) . ")",
			$blob
		);
		$sendto->reply($msg);
	}
}
