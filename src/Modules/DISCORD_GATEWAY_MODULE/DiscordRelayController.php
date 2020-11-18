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
};
use Nadybot\Core\Modules\{
	CONFIG\ConfigController,
	DISCORD\DiscordAPIClient,
	DISCORD\DiscordChannel,
	DISCORD\DiscordController,
};
use Nadybot\Core\Modules\ALTS\AltsController;
use Nadybot\Core\Modules\CONFIG\SettingOption;
use Nadybot\Core\Modules\DISCORD\DiscordUser;
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;
use Nadybot\Core\Modules\PREFERENCES\Preferences;
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\GuildMember;
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
	public DiscordGatewayCommandHandler $discordGatewayCommandHandler;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Setup */
	public function setup() {
		$this->settingManager->add(
			$this->moduleName,
			"discord_relay",
			"What to relay text into Discord channel",
			"edit",
			"options",
			"0",
			"off;priv;org;priv+org",
			"0;1;2;3"
		);
		$this->settingManager->add(
			$this->moduleName,
			"discord_relay_commands",
			"Relay commands into Discord channel",
			"edit",
			"options",
			"0",
			"true;false",
			"1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"discord_prefix_relay",
			"Prefix messages to Discord with org/botname",
			"edit",
			"options",
			"1",
			"true;false",
			"1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"discord_relay_channel",
			"Discord channel to relay into",
			"edit",
			"discord_channel",
			"off"
		);
		$this->settingManager->add(
			$this->moduleName,
			"discord_color_sender_guild",
			"Color of sender name in Discord messages relayed into org chat",
			"edit",
			"color",
			"<font color=#C3C3C3>"
		);
		$this->settingManager->add(
			$this->moduleName,
			"discord_color_sender_priv",
			"Color of sender name in Discord messages relayed into priv channel",
			"edit",
			"color",
			"<font color=#C3C3C3>"
		);
		$this->settingManager->add(
			$this->moduleName,
			"discord_color_guild",
			"Color of Discord messages relayed into org chat",
			"edit",
			"color",
			"<font color=#C3C3C3>"
		);
		$this->settingManager->add(
			$this->moduleName,
			"discord_color_priv",
			"Color of Discord messages relayed into priv channel",
			"edit",
			"color",
			"<font color=#C3C3C3>"
		);
		$this->settingManager->add(
			$this->moduleName,
			"discord_color_channel",
			"Color of the Discord tag when relaying",
			"edit",
			"color",
			"<font color=#C3C3C3>"
		);

		$this->timer->callLater(
			0,
			function() {
				$ranks = $this->configController->getValidAccessLevels();
				$allowedRanks = [];
				foreach ($ranks as $rank) {
					$allowedRanks []= $rank->value;
				}
				$this->settingManager->add(
					$this->moduleName,
					"discord_relay_mention_rank",
					"Minimum ranks allowed to use @here and @everyone",
					"edit",
					"options",
					"mod",
					join(";", $allowedRanks)
				);
			}
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

	/**
	 * List the discord channels of all guilds and allow to pick one for relaying
	 *
	 * @HandlesCommand("discord")
	 * @Matches("/^discord relay$/i")
	 */
	public function discordRelayCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		[$success, $blob] = $this->getChannelTree([$this, "channelRelayPicker"]);
		if (!$success) {
			$sendto->reply($blob);
			return;
		}
		$msg = $this->text->makeBlob("List of all Discord channels", $blob);
		$sendto->reply($msg);
	}

	/**
	 * Returns a channel name with a link to pick that one as relay target
	 */
	protected function channelRelayPicker(DiscordChannel $channel): string {
		$name = $channel->name;
		if ($channel->type === $channel::GUILD_TEXT) {
			$name = "#" . $channel->name;
		}
		if ($channel->type !== $channel::GUILD_TEXT) {
			return $name;
		}
		return "$name [".
			$this->text->makeChatcmd("relay here", "/tell <myname> discord relay {$channel->id}").
			"]";
	}

	/**
	 * Pick a discord channel for relaying
	 *
	 * @HandlesCommand("discord")
	 * @Matches("/^discord relay (.+)$/i")
	 */
	public function discordRelayChannelCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$channelId = $args[1];
		if ($channelId === 'off') {
			$this->settingManager->save('discord_relay_channel', 'off');
			$msg = "Discord relaying turned off.";
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
			$msg = "I can only relay into text channels.";
			$sendto->reply($msg);
			return;
		}
		$this->settingManager->save('discord_relay_channel', $channelId);
		$guilds = $this->discordGatewayController->getGuilds();
		$guild = $guilds[$channel->guild_id];
		$msg = "Now relaying into <highlight>{$guild->name}<end>\\<highlight>#{$channel->name}<end> (ID {$channelId})";
		$sendto->reply($msg);
	}

	public function relayPrivChannelMessage(string $sender, string $message): void {
		// Check if a channel to relay into was chosen
		$relayChannel = $this->settingManager->getString("discord_relay_channel");
		if ($relayChannel === "off") {
			return;
		}

		// Check that it's not a command or if it is a command, check that guest_relay_commands is not disabled
		if ($message[0] === $this->settingManager->getString("symbol")
			&& !$this->settingManager->getBool("discord_relay_commands")) {
			return;
		}
		if (strlen($this->chatBot->vars["my_guild"])) {
			$msg = "[Guest] ";
		} elseif ($this->settingManager->getBool('discord_prefix_relay')) {
			$msg = "[<myname>] ";
		}
		$msg .= "{$sender}: {$message}";
		$discordMsg = $this->discordController->formatMessage($msg);
		$minRankForMentions = $this->settingManager->getString('discord_relay_mention_rank');
		$sendersRank = $this->accessManager->getAccessLevelForCharacter($sender);
		if ($this->accessManager->compareAccessLevels($sendersRank, $minRankForMentions) < 0) {
			$discordMsg->allowed_mentions = (object)[
				"parse" => ["users"]
			];
		}

		//Relay the message to the discord channel
		$this->discordAPIClient->sendToChannel($relayChannel, $discordMsg->toJSON());
	}

	/**
	 * @Event("priv")
	 * @Event("sendpriv")
	 * @Description("Relay priv channel to Discord")
	 */
	public function relayPrivChannelEvent(Event $eventObj, ?bool $disableRelay=false): void {
		if ($disableRelay) {
			return;
		}
		$sender = $eventObj->sender;
		$message = $eventObj->message;

		// Check if the private channel relay is enabled
		if (($this->settingManager->getInt("discord_relay") & 1) !== 1) {
			return;
		}

		$this->relayPrivChannelMessage($sender, $message);
	}

	public function relayOrgChannelMessage(string $sender, string $message): void {
		// Check if a channel to relay into was chosen
		$relayChannel = $this->settingManager->getString("discord_relay_channel");
		if ($relayChannel === "off") {
			return;
		}

		// Check that it's not a command or if it is a command, check that guest_relay_commands is not disabled
		if ($message[0] === $this->settingManager->getString("symbol")
			&& !$this->settingManager->getBool("discord_relay_commands")) {
			return;
		}
		if ($this->settingManager->getBool('discord_prefix_relay')) {
			$guildNameForRelay = $this->relayController->getGuildAbbreviation();
			$msg = "[{$guildNameForRelay}] ";
		}
		$msg .= "{$sender}: {$message}";
		$discordMsg = $this->discordController->formatMessage($msg);

		//Relay the message to the discord channel
		$this->discordAPIClient->sendToChannel($relayChannel, $discordMsg->toJSON());
	}

	/**
	 * @Event("guild")
	 * @Event("sendguild")
	 * @Description("Relay org channel to Discord")
	 */
	public function relayOrgChannelEvent(Event $eventObj, ?bool $disableRelay=false): void {
		if ($disableRelay) {
			return;
		}
		$sender = $eventObj->sender;
		$message = $eventObj->message;

		// Check if the org channel relay is enabled
		if (($this->settingManager->getInt("discord_relay") & 2) !== 2) {
			return;
		}

		$this->relayOrgChannelMessage($sender, $message);
	}

	/**
	 * @Event("discordpriv")
	 * @Description("Relay discord channel into priv/org channel")
	 */
	public function relayDiscordEvent(DiscordMessageEvent $eventObj) {
		$relayChannel = $this->settingManager->getString("discord_relay_channel");
		if ($relayChannel === "off" || $relayChannel !== $eventObj->channel) {
			return;
		}
		if ($this->settingManager->getInt("discord_relay") === 0) {
			return;
		}
		$message = $eventObj->message;
		if ($message[0] === $this->settingManager->getString("symbol")) {
			return;
		}
		$this->resolveDiscordMentions(
			$eventObj->discord_message->guild_id??null,
			$message,
			function(string $message) use ($eventObj): void {
				$this->relayDiscordMessage($eventObj->discord_message->member, $message);
			}
		);
	}

	/**
	 * Recursively resolve all mentions in $message and then call $callback
	 */
	public function resolveDiscordMentions(?string $guildId, string $message, callable $callback): void {
		if (!preg_match("/<@!?(\d+)>/", $message, $matches)) {
			$callback($message);
			return;
		}
		$niceName = $this->discordGatewayCommandHandler->getNameForDiscordId($matches[1]);
		if (isset($niceName)) {
			$message = preg_replace("/<@!?" . $matches[1] . ">/", "@{$niceName}", $message);
			$this->resolveDiscordMentions($guildId, $message, $callback);
			return;
		}
		if (isset($guildId)) {
			$this->discordAPIClient->getGuildMember(
				$guildId,
				$matches[1],
				function(GuildMember $member, string $guildId, string $message, callable $callback) {
					$message = preg_replace("/<@!?" . $member->user->id . ">/", "@" . $member->getName(), $message);
					$this->resolveDiscordMentions($guildId, $message, $callback);
				},
				$guildId,
				$message,
				$callback
			);
			return;
		}
		$this->discordAPIClient->getUser(
			$matches[1],
			function(DiscordUser $user, ?int $guildId, string $message, callable $callback) {
				$message = preg_replace("/<@!?" . $user->id . ">/", "@{$user->username}", $message);
				$this->resolveDiscordMentions($guildId, $message, $callback);
			},
			$guildId,
			$message,
			$callback
		);
	}

	public function relayDiscordMessage(GuildMember $member, string $message, bool $formatMessage=true): void {
		$escapedMessage = $message;
		if ($formatMessage) {
			$escapedMessage = $this->formatMessage($message);
		}
		$senderName = $this->discordGatewayCommandHandler->getNameForDiscordId($member->user->id??"") ?? $member->getName();
		$discordColorChannel = $this->settingManager->getString('discord_color_channel');
		$message = "{$discordColorChannel}[Discord]<end> ";
		if (($this->settingManager->getInt("discord_relay") & 1) === 1) {
			$discordColorSenderPriv = $this->settingManager->getString('discord_color_sender_priv');
			$discordColorPriv = $this->settingManager->getString('discord_color_priv');
			$this->chatBot->sendPrivate(
				$message . "{$discordColorSenderPriv}{$senderName}<end>: {$discordColorPriv}{$escapedMessage}<end>",
				true
			);
		}
		if (($this->settingManager->getInt("discord_relay") & 2) === 2) {
			$discordColorSenderGuild = $this->settingManager->getString('discord_color_sender_guild');
			$discordColorGuild = $this->settingManager->getString('discord_color_guild');
			$this->chatBot->sendGuild(
				$message . "{$discordColorSenderGuild}{$senderName}<end>: {$discordColorGuild}{$escapedMessage}<end>",
				true
			);
		}
	}

	public function formatMessage(string $text): string {
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
		$text = preg_replace("/\*\*(.+?)\*\*/", "<highlight>$1<end>", $text);
		$text = preg_replace("/\*(.+?)\*/", "<i>$1</i>", $text);
		$text = preg_replace("/`(.+?)`/", "$1", $text);
		$text = str_replace(
			array_keys($smileyMapping),
			array_values($smileyMapping),
			$text
		);
		$text = htmlspecialchars($text);
		return $this->text->formatMessage($text);
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
		$this->discordAPIClient->sendToChannel($relayChannel, $discordMsg->toJSON());
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
		$msg = $this->privateChannelController->getLogonMessage($sender, true);
		if ($msg === null) {
			return;
		}
		$this->relayPrivOnlineEvent($msg);
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
		$this->discordAPIClient->sendToChannel($relayChannel, $discordMsg->toJSON());
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
		$msg = $this->guildController->getLogonMessage($sender, true);
		if ($msg === null) {
			return;
		}
		$this->relayOrgOnlineEvent($msg);
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
