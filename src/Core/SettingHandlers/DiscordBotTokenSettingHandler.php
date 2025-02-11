<?php declare(strict_types=1);

namespace Nadybot\Core\SettingHandlers;

use Amp\Http\Client\Interceptor\AddRequestHeader;
use Amp\Http\Client\{HttpClientBuilder, Request};
use Exception;
use Nadybot\Core\Modules\DISCORD\DiscordAPIClient;
use Nadybot\Core\{AccessManager, Attributes as NCA};

/**
 * Class to represent a discord bot token setting
 */
#[NCA\SettingHandler('discord_bot_token')]
class DiscordBotTokenSettingHandler extends SettingHandler {
	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Inject]
	private AccessManager $accessManager;

	/** @inheritDoc */
	public function getDescription(): string {
		$msg = "For this setting you need to enter a Discord token (59 characters).\n".
			"You can get the ID for your bot on the Discord developer portal.\n".
			"Check <a href='chatcmd:///start https://github.com/Nadybot/Nadybot/wiki/Discord'>".
			"our wiki</a> for a detailed description how to obtain it.\n\n";
		$msg .= "To change this setting:\n\n";
		$msg .= "<highlight>/tell <myname> settings save {$this->row->name} <i>token</i><end>\n\n";
		return $msg;
	}

	/** @throws \Exception when the Discord token is invalid */
	public function save(string $newValue): string {
		if ($newValue === 'off') {
			return $newValue;
		}
		$client = $this->builder
			->intercept(new AddRequestHeader('Authorization', 'Bot ' . $newValue))
			->build();

		$response = $client->request(new Request(DiscordAPIClient::DISCORD_API . '/users/@me'));
		if ($response->getStatus() !== 200) {
			throw new Exception("<highlight>{$newValue}<end> is not a valid Discord Bot Token");
		}
		return $newValue;
	}

	public function displayValue(string $sender): string {
		$newValue = $this->row->value;
		if ($newValue === 'off') {
			return "<highlight>{$newValue}<end>";
		}
		if (!$this->accessManager->checkAccess($sender, $this->row->admin??'all')) {
			return '<highlight>*********<end>';
		}
		return "<highlight>{$newValue}<end>";
	}
}
