<?php

namespace Budabot\Core\Modules\DISCORD;

use StdClass;
use Exception;

/**
 * @author Nadyita (RK5)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'discord',
 *		accessLevel = 'mod',
 *		description = 'Send a message to discord'
 *	)
 */
class DiscordController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public $moduleName;

	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;

	/**
	 * @var \Budabot\Core\SettingManager $settingManager
	 * @Inject
	 */
	public $settingManager;

	/**
	 * @var \Budabot\Core\Http $http
	 * @Inject
	 */

	public $http;

	/**
	 * @var \Budabot\Core\LoggerWrapper $logger
	 * @Logger
	 */
	public $logger;

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup() {
		$this->settingManager->add(
			$this->moduleName,
			'discord_webhook',
			'The Discord webhook to send messages to',
			'edit',
			'text',
			'',
			'',
			'admin'
		);
		$this->settingManager->registerChangeListener(
			"discord_webhook",
			[$this, "validateWebhook"]
		);
		$this->settingManager->add(
			$this->moduleName,
			'discord_avatar_url',
			'URL to an Avatar for Discord posts',
			'edit',
			'text',
			'serveradmin',
			'',
			'admin'
		);
	}

	public function validateWebhook($settingName, $oldValue, $newValue, $data) {
		if ($this->isDiscordWebhook($newValue) === false) {
			throw new Exception("<highlight>{$newValue}<end> is not a valid Discord Webhook");
		}
		$response = $this->http
			->post($newValue)
			->withQueryParams(['content' => ''])
			->withTimeout(10)
			->waitAndReturnResponse();
		if ($response->headers["status-code"] != "400") {
			throw new Exception("<highlight>{$newValue}<end> is not a valid Discord Webhook");
		}
		$responseData = @json_decode($response->body);
		if ($responseData !== null && $responseData->code !== 50006) {
			throw new Exception("<highlight>{$newValue}<end> is not a valid Discord Webhook");
		}
	}
	
	/**
	 * @HandlesCommand("discord")
	 * @Matches("/^discord\s+(.+)$/i")
	 */
	public function discordCommand($message, $channel, $sender, $sendto, $args) {
		$msg = "Message sent successfully.";
		if ($this->sendMessage($sender . ": " . $args[1]) === false) {
			$msg = "Error sending message, please check configuration.";
		}
		$sendto->reply($msg);
	}

	/**
	 * Check if a given URL is a discord webhook
	 *
	 * @param string $url The URL to check
	 * @return bool
	 */
	public function isDiscordWebhook($url) {
		return (bool)@preg_match(
			'|^https://discordapp\.com/api/webhooks/\d{18}/[a-zA-Z0-9_-]{68}$|',
			$url
		);
	}

	/**
	 * Send a message to the Discord webhook - if configured
	 *
	 * @param string $text The message to send
	 * @return bool true if sent successfully, else false
	 */
	public function sendMessage($text) {
		$webhookURL = $this->settingManager->get('discord_webhook');
		if ($this->isDiscordWebhook($webhookURL) === false) {
			return false;
		}
		$avatarURL = $this->settingManager->get('discord_avatar_url');
		$message = [
			'content' => $this->formatMessage($text),
			'username' => $this->chatBot->vars['name'],
		];
		if (strlen($avatarURL) && filter_var($avatarURL, \FILTER_VALIDATE_URL) === true) {
			$message['avatar_url'] = $avatarURL;
		}
		$this->http
			->post($webhookURL)
			->withQueryParams($message)
			->withTimeout(10)
			->withCallback([$this, "handleWebhookResponse"]);
		return true;
	}

	/**
	 * Reformat a Budabot message for Discord
	 *
	 * @param string $text The message to reformat
	 * @return string
	 */
	public function formatMessage($text) {
		$text = preg_replace('/([~`_])/', "\\$1", $text);
		$text = preg_replace('/<highlight>(.*?)<end>/', '**$1**', $text);
		$text = str_replace("<myname>", $this->chatBot->vars["name"], $text);
		$text = str_replace("<myguild>", $this->chatBot->vars["my_guild"], $text);
		$text = str_replace("<symbol>", $this->settingManager->get("symbol"), $text);
		$text = str_replace("<br>", "\n", $text);
		$text = str_replace("<tab>", "\t", $text);
		$text = preg_replace('/<[a-z]+?>(.*?)<end>/', '*$1*', $text);
			
		$text = strip_tags($text);
		return $text;
	}

	/**
	 * Handle the async reply of the Discord webhook
	 *
	 * @param \StdClass $response The HTTP response object
	 * @return void
	 */
	protected function handleWebhookResponse(StdClass $response) {
		if (substr($response->headers["status-code"], 0, 1) === "2") {
			return;
		}
		$responseData = @json_decode($response->body);
		if ($responseData === null) {
			$responseData = "Code " . $response->headers["status-code"];
		}
		$this->logger->log(
			"ERROR",
			"Error sending message to Discord Webhook: ".
			$responseData->message
		);
	}
}
