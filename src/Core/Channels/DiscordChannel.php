<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\AccessManager;
use Nadybot\Core\MessageHub;
use Nadybot\Core\MessageReceiver;
use Nadybot\Core\Modules\DISCORD\DiscordAPIClient;
use Nadybot\Core\Modules\DISCORD\DiscordController;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SettingManager;

class DiscordChannel implements MessageReceiver {
	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public DiscordAPIClient $discordAPIClient;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public DiscordController $discordController;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public SettingManager $settingManager;

	protected string $channel;
	protected string $id;

	public function __construct(string $channel, string $id) {
		$this->channel = $channel;
		$this->id = $id;
	}

	public function getChannelID(): string {
		return $this->id;
	}

	public function getChannelName(): string {
		return Source::DISCORD_PRIV . "({$this->channel})";
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		$renderPath = true;
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			if (!is_string($event->data->message??null)) {
				return false;
			}
			$msg = $event->data->message;
			$renderPath =$event->data->renderPath;
		} else {
			$msg = $event->getData();
		}
		$message = ($renderPath ? $this->messageHub->renderPath($event, $this->getChannelName()) : "").
			$msg;
		$discordMsg = $this->discordController->formatMessage($message);

		if (isset($event->char)) {
			$minRankForMentions = $this->settingManager->getString('discord_relay_mention_rank');
			$sendersRank = $this->accessManager->getAccessLevelForCharacter($event->char->name);
			if ($this->accessManager->compareAccessLevels($sendersRank, $minRankForMentions) < 0) {
				$discordMsg->allowed_mentions = (object)[
					"parse" => ["users"]
				];
			}
		}

		//Relay the message to the discord channel
		$this->discordAPIClient->queueToChannel($this->id, $discordMsg->toJSON());
		return true;
	}
}
