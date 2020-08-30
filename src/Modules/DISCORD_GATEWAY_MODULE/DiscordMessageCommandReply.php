<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\Modules\DISCORD\DiscordAPIClient;
use Nadybot\Core\Modules\DISCORD\DiscordController;

class DiscordMessageCommandReply implements CommandReply {
	/** @Inject */
	public DiscordAPIClient $discordAPIClient;

	/** @Inject */
	public DiscordController $discordController;

	protected string $channelId;

	public function __construct(string $channelId) {
		$this->channelId = $channelId;
	}

	public function reply($msg): void {
		if (!is_array($msg)) {
			$msg = [$msg];
		}
		foreach ($msg as $msgPack) {
			$messageObj = $this->discordController->formatMessage($msgPack);
			$this->discordAPIClient->sendToChannel($this->channelId, $messageObj->toJSON());
		}
	}
}
