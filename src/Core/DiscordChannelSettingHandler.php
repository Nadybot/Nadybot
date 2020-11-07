<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use JsonException;
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\DiscordGatewayController;

/**
 * Class to represent a setting with a text value for BudaBot
 */
class DiscordChannelSettingHandler extends SettingHandler {

	/** @Inject */
	public Http $http;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public DiscordGatewayController $discordGatewayController;

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string {
		$msg = "For this setting you need to enter a Discord channel ID (nmber up to 20 digits).\n".
			"You can get the ID of a channel by turning on Developer mode in Discord, ".
			"right-clicking on a channel and chosing \"Copy ID\".\n".
			"To turn on Developer Mode in Discord:\n".
			"<tab>* press ctrl + , (opens User Settings)\n".
			"<tab>* choose \"Appearance\"\n".
			"<tab>* scroll down to \"Developer Mode\" and activate it\n\n";
		$msg .= "To change this setting:\n\n";
		$msg .= "<highlight>/tell <myname> settings save {$this->row->name} <i>channel id</i><end>\n\n";
		return $msg;
	}

	/**
	 * @throws \Exception when the Channel ID is invalid
	 */
	public function save(string $newValue): string {
		if ($newValue === "off") {
			return $newValue;
		}
		if (!preg_match("/^\d{1,20}$/", $newValue)) {
			throw new Exception("<highlight>$newValue<end> is not a valid Channel ID.");
		}
		$discordBotToken = $this->settingManager->get('discord_bot_token');
		if (empty($discordBotToken)) {
			throw new Exception("You cannot set any Discord channels before configuring your Discord Bot Token.");
		}
		$channel = $this->discordGatewayController->getChannel($newValue);
		if ($channel !== null) {
			if ($channel->type !== $channel::GUILD_TEXT) {
				throw new Exception("Can only send message to text channels");
			}
			return $newValue;
		}
		$response = $this->http
			->get("https://discord.com/api/channels/{$newValue}")
			->withHeader('Authorization', 'Bot ' . $discordBotToken)
			->withTimeout(10)
			->waitAndReturnResponse();
		if ($response->headers["status-code"] !== "200") {
			try {
				$reply = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
			} catch (JsonException $e) {
				throw new Exception("Cannot use <highlight>{$newValue}<end> as value.");
			}
			throw new Exception("<highlight>{$newValue}<end>: {$reply->message}.");
		}
		return $newValue;
	}

	public function displayValue(): string {
		$newValue = $this->row->value;
		if ($newValue === "off") {
			return "<highlight>{$newValue}<end>";
		}
		$channel = $this->discordGatewayController->getChannel($newValue);
		if ($channel !== null) {
			$guild = $this->discordGatewayController->getGuilds()[$channel->guild_id];
			return "<highlight>{$guild->name}<end> <img src=tdb://id:GFX_GUI_WINDOW_MAXIMIZE> #<highlight>{$channel->name}<end>";
		}
		$discordBotToken = $this->settingManager->get('discord_bot_token');
		if (empty($discordBotToken)) {
			return $newValue;
		}
		$response = $this->http
			->get("https://discord.com/api/channels/{$newValue}")
			->withHeader('Authorization', 'Bot ' . $discordBotToken)
			->withTimeout(10)
			->waitAndReturnResponse();
		if ($response->headers["status-code"] !== "200") {
			return $newValue;
		}
		try {
			$reply = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			return $newValue;
		}
		if (!isset($reply->name)) {
			return $newValue;
		}
		return "<highlight>{$reply->name}<end>";
	}
}
