<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Amp\Promise;
use Nadybot\Core\{
	Attributes as NCA,
	AccessManager,
	MessageHub,
	MessageReceiver,
	Modules\DISCORD\DiscordAPIClient,
	Modules\DISCORD\DiscordController,
	Nadybot,
	Routing\Events\Base,
	Routing\Events\Online,
	Routing\RoutableEvent,
	Routing\Source,
	SettingManager,
	Text,
};

class DiscordMsg implements MessageReceiver {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public DiscordAPIClient $discordAPIClient;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public DiscordController $discordController;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Text $text;

	protected string $channel;
	protected string $id;

	public function getChannelName(): string {
		return Source::DISCORD_MSG . "(*)";
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		$renderPath = true;
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			$baseEvent = $event->data??null;
			if (!isset($baseEvent) || !($baseEvent instanceof Base) || !isset($baseEvent->message)) {
				return false;
			}
			$msg = $baseEvent->message;
			$renderPath = $baseEvent->renderPath;
			if ($baseEvent->type === Online::TYPE) {
				$msg = $this->text->removePopups($msg);
			}
		} else {
			$msg = $event->getData();
		}
		$pathText = "";
		if ($renderPath) {
			$pathText = $this->messageHub->renderPath($event, $this->getChannelName());
		}
		if (isset($event->char)) {
			$pathText = preg_replace("/<a\s[^>]*href=['\"]?user.*?>(.+)<\/a>/s", '<highlight>$1<end>', $pathText);
			$pathText = preg_replace("/(\s)([^:\s]+): $/s", '$1<highlight>$2<end>: ', $pathText);
		}
		$message = $pathText.$msg;
		$discordMsg = $this->discordController->formatMessage($message);

		if (isset($event->char)) {
			$minRankForMentions = $this->settingManager->getString('discord_relay_mention_rank') ?? "superadmin";
			$sendersRank = $this->accessManager->getAccessLevelForCharacter($event->char->name);
			if ($this->accessManager->compareAccessLevels($sendersRank, $minRankForMentions) < 0) {
				$discordMsg->allowed_mentions = (object)[
					"parse" => ["users"]
				];
			}
		}

		//Relay the message to the discord channel
		if (preg_match("/^\d+$/", $destination)) {
			Promise\rethrow($this->discordAPIClient->queueToChannel($destination, $discordMsg->toJSON()));
		} else {
			Promise\rethrow($this->discordAPIClient->sendToUser($destination, $discordMsg->toJSON()));
		}
		return true;
	}
}
