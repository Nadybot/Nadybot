<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\MessageHub;
use Nadybot\Core\MessageReceiver;
use Nadybot\Core\Modules\DISCORD\DiscordAPIClient;
use Nadybot\Core\Modules\DISCORD\DiscordController;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\Source;

class DiscordChannel implements MessageReceiver {
	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public DiscordAPIClient $discordAPIClient;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public DiscordController $discordController;

	protected string $channel;
	protected string $id;

	public function __construct(string $channel, string $id) {
		$this->channel = $channel;
		$this->id = $id;
	}

	public function getChannelName(): string {
		return Source::DISCORD_PRIV . "({$this->channel})";
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			return false;
		}
		$message = $this->messageHub->renderPath($event).
			$event->getData();
		$discordMsg = $this->discordController->formatMessage($message);

		//Relay the message to the discord channel
		$this->discordAPIClient->queueToChannel($this->id, $discordMsg->toJSON());
		return true;
	}
}
