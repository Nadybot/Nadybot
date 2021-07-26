<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\{
	AccessManager,
	Event,
	CommandReply,
	Nadybot,
	SettingManager,
	Text,
	Timer,
	Util,
};
use Nadybot\Core\Modules\{
	CONFIG\ConfigController,
	DISCORD\DiscordAPIClient,
	DISCORD\DiscordChannel,
	DISCORD\DiscordController,
};
use Nadybot\Core\Modules\ALTS\AltsController;
use Nadybot\Core\Modules\CONFIG\SettingOption;
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;
use Nadybot\Core\Modules\PREFERENCES\Preferences;
use Nadybot\Modules\GUILD_MODULE\GuildController;
use Nadybot\Modules\PRIVATE_CHANNEL_MODULE\PrivateChannelController;
use Nadybot\Modules\RELAY_MODULE\RelayController;

/**
 * @author Nadyite (RK5)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'discord',
 *		accessLevel = 'mod',
 *		description = 'Information about the discord link',
 *		help        = 'discord.txt'
 *	)
 */
class DiscordRelayController {
	public string $moduleName;

	/** @Inject */
	public DiscordGatewayController $discordGatewayController;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public RelayController $relayController;

	/** @Inject */
	public DiscordAPIClient $discordAPIClient;

	/** @Inject */
	public DiscordController $discordController;

	/** @Inject */
	public GuildController $guildController;

	/** @Inject */
	public PrivateChannelController $privateChannelController;

	/** @Inject */
	public ConfigController $configController;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public Preferences $preferences;

	/** @Inject */
	public AltsController $altsController;

	/** @Inject */
	public Timer $timer;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public DiscordGatewayCommandHandler $discordGatewayCommandHandler;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Setup */
	public function setup() {
		// $this->settingManager->add(
		// 	$this->moduleName,
		// 	"discord_relay",
		// 	"What to relay text into Discord channel",
		// 	"edit",
		// 	"options",
		// 	"0",
		// 	"off;priv;org;priv+org",
		// 	"0;1;2;3"
		// );
		// $this->settingManager->add(
		// 	$this->moduleName,
		// 	"discord_relay_commands",
		// 	"Relay commands into Discord channel",
		// 	"edit",
		// 	"options",
		// 	"0",
		// 	"true;false",
		// 	"1;0"
		// );
		// $this->settingManager->add(
		// 	$this->moduleName,
		// 	"discord_prefix_relay",
		// 	"Prefix messages to Discord with org/botname",
		// 	"edit",
		// 	"options",
		// 	"1",
		// 	"true;false",
		// 	"1;0"
		// );
		$this->settingManager->add(
			$this->moduleName,
			"discord_relay_channel",
			"Discord channel to relay into",
			"edit",
			"discord_channel",
			"off"
		);
		// $this->settingManager->add(
		// 	$this->moduleName,
		// 	"discord_color_sender_guild",
		// 	"Color of sender name in Discord messages relayed into org chat",
		// 	"edit",
		// 	"color",
		// 	"<font color=#C3C3C3>"
		// );
		// $this->settingManager->add(
		// 	$this->moduleName,
		// 	"discord_color_sender_priv",
		// 	"Color of sender name in Discord messages relayed into priv channel",
		// 	"edit",
		// 	"color",
		// 	"<font color=#C3C3C3>"
		// );
		// $this->settingManager->add(
		// 	$this->moduleName,
		// 	"discord_color_guild",
		// 	"Color of Discord messages relayed into org chat",
		// 	"edit",
		// 	"color",
		// 	"<font color=#C3C3C3>"
		// );
		// $this->settingManager->add(
		// 	$this->moduleName,
		// 	"discord_color_priv",
		// 	"Color of Discord messages relayed into priv channel",
		// 	"edit",
		// 	"color",
		// 	"<font color=#C3C3C3>"
		// );
		// $this->settingManager->add(
		// 	$this->moduleName,
		// 	"discord_color_channel",
		// 	"Color of the Discord tag when relaying",
		// 	"edit",
		// 	"color",
		// 	"<font color=#C3C3C3>"
		// );
		$this->settingManager->add(
			$this->moduleName,
			"discord_relay_mention_rank",
			"Minimum ranks allowed to use @here and @everyone",
			"edit",
			"rank",
			"mod"
		);
	}

	/**
	 * Gives a list of all channels we have access to
	 * @return SettingOption[]
	 */
	public function getChannelOptionList(): array {
		$guilds = $this->discordGatewayController->getGuilds();
		if (empty($guilds)) {
			return [];
		}
		/** @var SettingOption[] */
		$result = [];
		foreach ($guilds as $guildId => $guild) {
			foreach ($guild->channels as $channel) {
				if ($channel->type !== $channel::GUILD_CATEGORY) {
					continue;
				}
				foreach ($guild->channels as $subchannel) {
					if (($subchannel->parent_id??null) !== $channel->id) {
						continue;
					}
					if ($subchannel->type !== $subchannel::GUILD_TEXT) {
						continue;
					}
					$option = new SettingOption();
					$option->value = $subchannel->id;
					$option->name = "{$guild->name} > {$channel->name} > #{$subchannel->name}";
					$result []= $option;
				}
			}
		}
		return $result;
	}

	protected function getChannelTree(?callable $callback=null): array {
		if (!$this->discordGatewayController->isConnected()) {
			return [false, "The bot is not (yet) connected to discord."];
		}
		$guilds = $this->discordGatewayController->getGuilds();
		if (empty($guilds)) {
			return [false, "Your Discord bot is currently not member of any guild"];
		}
		$blob = "";
		foreach ($guilds as $guildId => $guild) {
			$blob .= "<pagebreak><header2>{$guild->name}<end>\n";
			foreach ($guild->channels as $channel) {
				if ($channel->type !== $channel::GUILD_CATEGORY) {
					continue;
				}
				$blob .= "<tab><highlight>{$channel->name}<end>\n";
				foreach ($guild->channels as $subchannel) {
					if (($subchannel->parent_id??null) !== $channel->id) {
						continue;
					}
					$text = "{$subchannel->name}";
					if ($subchannel->type === $subchannel::GUILD_TEXT) {
						$text = "#{$subchannel->name}";
					}
					if ($callback) {
						$text = $callback($subchannel);
					}
					$blob .= "<tab><tab>$text\n";
				}
			}
			$blob .= "\n\n";
		}
		return [true, $blob];
	}

	/**
	 * List the discord channels of all guilds
	 *
	 * @HandlesCommand("discord")
	 * @Matches("/^discord channels$/i")
	 */
	public function discordChannelsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		[$success, $blob] = $this->getChannelTree();
		if (!$success) {
			$sendto->reply($blob);
			return;
		}
		$msg = $this->text->makeBlob("List of all Discord channels", $blob);
		$sendto->reply($msg);
	}

	/**
	 * List the discord channels of all guilds and allow to pick one for notifications
	 *
	 * @HandlesCommand("discord")
	 * @Matches("/^discord notify$/i")
	 */
	public function discordNotifyCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		[$success, $blob] = $this->getChannelTree([$this, "channelNotifyPicker"]);
		if (!$success) {
			$sendto->reply($blob);
			return;
		}
		$msg = $this->text->makeBlob("List of all Discord channels", $blob);
		$sendto->reply($msg);
	}

	/**
	 * Returns a channel name with a link to pick that one as notifications target
	 */
	protected function channelnotifyPicker(DiscordChannel $channel): string {
		$name = $channel->name;
		if ($channel->type === $channel::GUILD_TEXT) {
			$name = "#" . $channel->name;
		}
		if ($channel->type !== $channel::GUILD_TEXT) {
			return $name;
		}
		return "$name [".
			$this->text->makeChatcmd("relay here", "/tell <myname> discord notify {$channel->id}").
			"]";
	}

	/**
	 * Pick a discord channel for notifications
	 *
	 * @HandlesCommand("discord")
	 * @Matches("/^discord notify (.+)$/i")
	 */
	public function discordNotifyChannelCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$channelId = $args[1];
		if ($channelId === 'off') {
			$this->settingManager->save('discord_notify_channel', 'off');
			$msg = "Discord notifications turned off.";
			$sendto->reply($msg);
			return;
		}
		if (!$this->discordGatewayController->isConnected()) {
			$msg = "The bot is not (yet) connected to discord.";
			$sendto->reply($msg);
			return;
		}
		$channel = $this->discordGatewayController->getChannel($channelId);
		if ($channel === null) {
			$msg = "The channel with the id <highlight>{$channelId}<end> does not exist.";
			$sendto->reply($msg);
			return;
		}
		if ($channel->type !== $channel::GUILD_TEXT) {
			$msg = "I can only send notifications into text channels.";
			$sendto->reply($msg);
			return;
		}
		$this->settingManager->save('discord_notify_channel', $channelId);
		$guilds = $this->discordGatewayController->getGuilds();
		$guild = $guilds[$channel->guild_id];
		$msg = "Now sending notifications into <highlight>{$guild->name}<end>\<highlight>#{$channel->name}<end> (ID {$channelId})";
		$sendto->reply($msg);
	}

	public static function formatMessage(string $text): string {
		$smileyMapping = [
			"☺️" => ":-3",
			"🙂" => ":-)",
			"😊" => ":o)",
			"😀" => ":-D",
			"😁" => "^_^",
			"😂" => ":'-)",
			"😃" => ":-)",
			"😃" => "=D",
			"😄" => "xD",
			"😆" => "xDD",
			"😍" => "(*_*)",
			"☹️" => ":-<",
			"🙁" => ":o(",
			"😠" => ">:-[",
			"😡" => ">:-@",
			"😞" => ":-c",
			"😟" => ":-<",
			"😣" => "(>_<)",
			"😖" => "(>_<)>",
			"😢" => ":'-(",
			"😭" => "T_T",
			"😨" => "D-:",
			"😧" => ">:-|",
			"😦" => "D:<",
			"😱" => ":panic:",
			"😫" => "v.v",
			"😩" => "v.v",
			"😮" => ":-O",
			"😯" => ":-o",
			"😲" => ">:O",
			"😗" => ":-*",
			"😙" => ":-*",
			"😚" => ":-*",
			"😘" => ":-*",
			"😉" => ";-)",
			"😜" => ";-P",
			"😛" => ":-P",
			"😝" => ":‑Þ",
			"🤑" => ":-$",
			"🤔" => ":S",
			"😕" => ":-\\",
			"😐" => ":-|",
			"😑" => "-_-",
			"😳" => ":$",
			"🤐" => ":-X",
			"😶" => ":-#",
			"😇" => "O:-)",
			"👼" => "O:-]",
			"😈" => ">:-)",
			"😎" => "B-)",
			"😪" => "|-O",
			"😏" => ":-J",
			"😒" => "(-.-)",
			"😵" => "%-O",
			"🤕" => "%-|",
			"🤒" => ":-###",
			"😷" => ":-#",
			"🤢" => ":-X",
			"🤨" => "o.O",
			"😬" => ":E",
			"🌹" => "@}‑;‑'‑‑‑",
			"❤️" => "<3",
			"💔" => "<\\3",
			"😴" => "zzZ",
			"🙄" => "(°_°)",
			"😅" => "^_^'",
			"🤦" => ":facepalm:",
			"🤷" => ":shrug:",
		];
		$text = preg_replace("/<:([a-z0-9]+):(\d+)>/i", ":$1:", $text);
		$text = preg_replace("/```(.+?)```/s", "$1", $text);
		$text = preg_replace("/`(.+?)`/s", "$1", $text);
		$text = htmlspecialchars($text);
		$text = preg_replace("/\*\*(.+?)\*\*/s", "<highlight>$1<end>", $text);
		$text = preg_replace("/\*(.+?)\*/s", "<i>$1</i>", $text);
		$text = str_replace(
			array_keys($smileyMapping),
			array_values($smileyMapping),
			$text
		);
		if (class_exists("IntlChar")) {
			$text = preg_replace_callback(
				"/([\x{0450}-\x{2018}\x{2020}-\x{fffff}])/u",
				function (array $matches): string {
					$char = \IntlChar::charName($matches[1]);
					if ($char === "ZERO WIDTH JOINER"
						|| substr($char, 0, 19) === "VARIATION SELECTOR-"
						|| substr($char, 0, 14) === "EMOJI MODIFIER"
					) {
						return "";
					}
					return ":{$char}:";
				},
				$text
			);
		}
		return $text;
	}

	public function relayPrivOnlineEvent(string $msg): void {
		if ($this->settingManager->getBool('discord_prefix_relay')) {
			if (strlen($this->chatBot->vars["my_guild"])) {
				$msg = "[Guest] {$msg}";
			} elseif ($this->settingManager->getBool('discord_prefix_relay')) {
				$msg = "[<myname>] {$msg}";
			}
		}
		$discordMsg = $this->discordController->formatMessage($msg);
		$discordMsg->allowed_mentions = (object)[
			"parse" => ["users"]
		];

		$relayChannel = $this->settingManager->getString("discord_relay_channel");
		$this->discordAPIClient->queueToChannel($relayChannel, $discordMsg->toJSON());
	}

	/**
	 * @Event("joinPriv")
	 * @Description("Sends a message to Discord when someone joins the private channel")
	 */
	public function relayJoinPrivMessagesEvent(Event $eventObj): void {
		$relayChannel = $this->settingManager->getString("discord_relay_channel");
		if ($relayChannel === "off" || empty($relayChannel)) {
			return;
		}
		$sender = $eventObj->sender;
		$msg = $this->privateChannelController->getLogonMessageAsync($sender, true, [$this, "relayPrivOnlineEvent"]);
	}

	/**
	 * @Event("leavePriv")
	 * @Description("Sends a message to Discordthe relay when someone leaves the private channel")
	 */
	public function relayLeavePrivMessagesEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		$relayChannel = $this->settingManager->getString("discord_relay_channel");
		if ($relayChannel === "off" || empty($relayChannel)) {
			return;
		}
		$msg = $this->privateChannelController->getLogoffMessage($sender);
		if ($msg === null) {
			return;
		}
		$this->relayPrivOnlineEvent($msg);
	}

	public function relayOrgOnlineEvent(string $msg): void {
		if ($this->settingManager->getBool('discord_prefix_relay')) {
			$guildNameForRelay = $this->relayController->getGuildAbbreviation();
			$msg = "[{$guildNameForRelay}] {$msg}";
		}
		$discordMsg = $this->discordController->formatMessage($msg);
		$discordMsg->allowed_mentions = (object)[
			"parse" => ["users"]
		];

		$relayChannel = $this->settingManager->getString("discord_relay_channel");
		$this->discordAPIClient->queueToChannel($relayChannel, $discordMsg->toJSON());
	}

	/**
	 * @Event("logOn")
	 * @Description("Sends Org logon messages to Discord")
	 */
	public function relayLogonMessagesEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		$relayChannel = $this->settingManager->getString("discord_relay_channel");
		if ($relayChannel === "off"
			|| empty($relayChannel)
			|| !isset($this->chatBot->guildmembers[$sender])
			|| !$this->chatBot->isReady()) {
			return;
		}
		$this->guildController->getLogonMessageAsync($sender, true, [$this, "relayOrgOnlineEvent"]);
	}

	/**
	 * @Event("logOff")
	 * @Description("Sends Logoff messages over the relay")
	 */
	public function relayLogoffMessagesEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		$relayChannel = $this->settingManager->getString("discord_relay_channel");
		if ($relayChannel === "off"
			|| empty($relayChannel)
			|| !isset($this->chatBot->guildmembers[$sender])
			|| !$this->chatBot->isReady()) {
			return;
		}
		$msg = $this->guildController->getLogoffMessage($sender);
		if ($msg === null) {
			return;
		}
		$this->relayOrgOnlineEvent($msg);
	}
}
