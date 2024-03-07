<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\json_decode;
use Amp\Http\Client\Interceptor\AddRequestHeader;
use Amp\Http\Client\{HttpClientBuilder, Request};
use Exception;
use Nadybot\Core\Attributes as NCA;

use Nadybot\Modules\DISCORD_GATEWAY_MODULE\DiscordGatewayController;

use Safe\Exceptions\JsonException;

/**
 * Class to represent a setting with a discord channel value for NadyBot
 */
#[NCA\SettingHandler("discord_channel")]
class DiscordChannelSettingHandler extends SettingHandler {
	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public HttpClientBuilder $builder;

	#[NCA\Inject]
	public DiscordGatewayController $discordGatewayController;

	/** @inheritDoc */
	public function getDescription(): string {
		$msg = "For this setting you need to enter a Discord channel ID (number up to 20 digits).\n".
			"You can get the ID of a channel by turning on Developer mode in Discord, ".
			"right-clicking on a channel and choosing \"Copy ID\".\n".
			"To turn on Developer Mode in Discord:\n".
			"<tab>* press ctrl + , (opens User Settings)\n".
			"<tab>* choose \"Appearance\"\n".
			"<tab>* scroll down to \"Developer Mode\" and activate it\n\n";
		$msg .= "To change this setting:\n\n";
		$msg .= "<highlight>/tell <myname> settings save {$this->row->name} <i>channel id</i><end>\n\n";
		return $msg;
	}

	public function save(string $newValue): string {
		if ($newValue === "off") {
			return $newValue;
		}
		if (!preg_match("/^\d{1,20}$/", $newValue)) {
			throw new Exception("<highlight>{$newValue}<end> is not a valid Channel ID.");
		}
		$discordBotToken = $this->settingManager->getString('discord_bot_token');
		if (!isset($discordBotToken) || $discordBotToken === "" || $discordBotToken === 'off') {
			throw new Exception("You cannot set any Discord channels before configuring your Discord Bot Token.");
		}
		$channel = $this->discordGatewayController->getChannel($newValue);
		if ($channel !== null) {
			if ($channel->type !== $channel::GUILD_TEXT) {
				throw new Exception("Can only send message to text channels");
			}
			return $newValue;
		}
		$client = $this->builder
			->intercept(new AddRequestHeader('Authorization', 'Bot ' . $discordBotToken))
			->build();

		$response = $client->request(new Request("https://discord.com/api/channels/{$newValue}"));
		if ($response->getStatus() === 200) {
			return $newValue;
		}
		$body = $response->getBody()->buffer();
		try {
			$reply = json_decode($body);
		} catch (JsonException $e) {
			throw new Exception("Cannot use <highlight>{$newValue}<end> as value.");
		}
		if (isset($reply->message)) {
			throw new Exception("<highlight>{$newValue}<end>: {$reply->message}.");
		}
		throw new Exception("<highlight>{$newValue}<end>: Unknown error getting channel info.");
	}

	public function displayValue(string $sender): string {
		$newValue = $this->row->value;
		if ($newValue === "off" || !isset($newValue)) {
			return "<highlight>{$newValue}<end>";
		}
		$channel = $this->discordGatewayController->getChannel($newValue);
		if ($channel !== null) {
			$guild = $this->discordGatewayController->getGuilds()[$channel->guild_id]??null;
			if (isset($guild)) {
				return "<highlight>{$guild->name}<end> <img src=tdb://id:GFX_GUI_WINDOW_MAXIMIZE> #<highlight>{$channel->name}<end>";
			}
			return "#<highlight>{$channel->name}<end>";
		}
		return "<highlight>{$newValue}<end>";
	}
}
