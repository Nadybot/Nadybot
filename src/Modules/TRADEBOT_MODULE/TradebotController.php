<?php declare(strict_types=1);

namespace Nadybot\Modules\TRADEBOT_MODULE;

use Nadybot\Core\{
	Event,
	LoggerWrapper,
	StopExecutionException,
	Nadybot,
	SettingManager,
};

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 *
 * @Instance
 */
class TradebotController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Nadybot $chatBot;
	
	/** @Inject */
	public SettingManager $settingManager;
	
	/** @Logger */
	public LoggerWrapper $logger;

	/** @var array<string,array<string,mixed>> */
	private const BOT_DATA = [
		'Darknet' => [
			'join' => ['!register', '!autoinvite on'],
			'leave' => ['!autoinvite off', '!unregister'],
			'match' => '/^\[[a-z]+\]/i',
		],
		'Lightnet' => [
			'join' => ['register', 'autoinvite on'],
			'leave' => ['autoinvite off', 'unregister'],
			'match' => '/^\[[a-z]+\]/i',
		]
	];

	/** @Setup */
	public function setup(): void {
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
			"Showing Tradebot messages in",
			"edit",
			"options",
			"3",
			"Off;Priv;Org;Priv+Org",
			"0;1;2;3",
			"mod"
		);

		$this->settingManager->registerChangeListener(
			'tradebot',
			[$this, 'changeTradebot']
		);
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
			}
		}
		foreach ($botsToSignUp as $botName) {
			if (array_key_exists($botName, self::BOT_DATA)) {
				foreach (self::BOT_DATA[$botName]['join'] as $cmd) {
					$this->logger->logChat("Out. Msg.", $botName, $cmd);
					$this->chatBot->send_tell($botName, $cmd, "\0", AOC_PRIORITY_MED);
				}
			}
		}
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
	 * Relay incoming tell-messages of tradebots to org/priv chat, so we can see errros
	 */
	public function processIncomingTradebotMessage(string $sender, string $message): void {
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
		if (!preg_match($match, strip_tags($message))) {
			return;
		}
		if ($this->settingManager->getInt("tradebot_channel_spam") & 2) {
			$this->chatBot->sendGuild($message, true);
		}
		if ($this->settingManager->getInt("tradebot_channel_spam") & 1) {
			$this->chatBot->sendPrivate($message, true);
		}
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
		$this->chatBot->privategroup_join($sender);
	}
}
