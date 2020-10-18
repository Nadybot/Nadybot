<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\{
	Event,
	CommandReply,
	Nadybot,
	SettingManager,
	Text,
};
use Nadybot\Core\Modules\DISCORD\DiscordAPIClient;
use Nadybot\Core\Modules\DISCORD\DiscordChannel;
use Nadybot\Core\Modules\DISCORD\DiscordController;
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\GuildMember;
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
			"discord_relay_channel",
			"Discord channel to relay into",
			"edit",
			"discord_channel",
			"off"
		);
		$this->settingManager->add(
			$this->moduleName,
			"discord_color_guild",
			"Discord relay color in guild channel",
			"edit",
			"color",
			"<font color=#C3C3C3>"
		);
		$this->settingManager->add(
			$this->moduleName,
			"discord_color_priv",
			"Discord relay color in private channel",
			"edit",
			"color",
			"<font color=#C3C3C3>"
		);
		$this->settingManager->add(
			$this->moduleName,
			"discord_color_channel",
			"Color for Discord Channel relay(ChannelName)",
			"edit",
			"color",
			"<font color=#C3C3C3>"
		);
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
		$msg = "Now relaying into <highlight>{$guild->name}<end>\<highlight>#{$channel->name}<end> (ID {$channelId})";
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
		$msg = "[Guest] {$sender}: {$message}";
		$discordMsg = $this->discordController->formatMessage($msg);

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
		$guildNameForRelay = $this->relayController->getGuildAbbreviation();
		$msg = "[{$guildNameForRelay}] {$sender}: {$message}";
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
		$this->relayDiscordMessage($eventObj->discord_message->member, $message);
	}

	public function relayDiscordMessage(GuildMember $member, string $message, bool $formatMessage=true): void {
		$escapedMessage = $message;
		if ($formatMessage) {
			$escapedMessage = $this->formatMessage($message);
		}
		$senderName = $this->discordGatewayCommandHandler->getNameForDiscordId($member->user->id??"") ?? $member->getName();
		$discordColorChannel = $this->settingManager->getString('discord_color_channel');
		$message = "{$discordColorChannel}[Discord]<end> {$senderName}: ";
		if (($this->settingManager->getInt("discord_relay") & 1) === 1) {
			$discordColorPriv = $this->settingManager->getString('discord_color_priv');
			$this->chatBot->sendPrivate(
				$message . "{$discordColorPriv}{$escapedMessage}<end>",
				true
			);
		}
		if (($this->settingManager->getInt("discord_relay") & 2) === 2) {
			$discordColorGuild = $this->settingManager->getString('discord_color_guild');
			$this->chatBot->sendGuild(
				$message . "{$discordColorGuild}{$escapedMessage}<end>",
				true
			);
		}
	}

	public function formatMessage(string $text): string {
		$text = htmlspecialchars($text);
		$text = preg_replace("/\*\*(.+?)\*\*/", "<highlight>$1<end>", $text);
		$text = preg_replace("/\*(.+?)\*/", "<i>$1</i>", $text);
		$text = preg_replace("/`(.+?)`/", "$1", $text);
		return $this->text->formatMessage($text);
	}
}
