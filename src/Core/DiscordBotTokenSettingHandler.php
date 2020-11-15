<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use JsonException;
use Nadybot\Core\Modules\DISCORD\DiscordAPIClient;
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\DiscordGatewayController;

/**
 * Class to represent a setting with a text value for BudaBot
 */
class DiscordBotTokenSettingHandler extends SettingHandler {

	/** @Inject */
	public Http $http;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public DiscordGatewayController $discordGatewayController;

	/** @Inject */
	public AccessManager $accessManager;

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string {
		$msg = "For this setting you need to enter a Discord token (59 characters).\n".
			"You can get the ID for your bot on the Discord developer portal.\n".
			"Check <a href='chatcmd:///start https://github.com/Nadybot/Nadybot/wiki/Discord'>".
			"our wiki</a> for a detailed description how to obtain it.\n\n";
		$msg .= "To change this setting:\n\n";
		$msg .= "<highlight>/tell <myname> settings save {$this->row->name} <i>token</i><end>\n\n";
		return $msg;
	}

	/**
	 * @throws \Exception when the Discord token is invalid
	 */
	public function save(string $newValue): string {
		if ($newValue === "off") {
			return $newValue;
		}
		$response = $this->http
			->get(DiscordAPIClient::DISCORD_API . "/users/@me")
			->withHeader('Authorization', 'Bot ' . $newValue)
			->withTimeout(10)
			->waitAndReturnResponse();
		if ($response->headers["status-code"] !== "200") {
			throw new Exception("<highlight>{$newValue}<end> is not a valid Discord Bot Token");
		}
		return $newValue;
	}

	public function displayValue(string $sender): string {
		$newValue = $this->row->value;
		if ($newValue === "off") {
			return "<highlight>{$newValue}<end>";
		}
		if (!$this->accessManager->checkAccess($sender, $this->row->admin)) {
			return "<highlight>*********<end>";
		}
		return "<highlight>{$newValue}<end>";
	}
}
