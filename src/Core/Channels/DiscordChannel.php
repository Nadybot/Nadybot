<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use function Amp\async;
use function Safe\preg_replace;

use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	MessageHub,
	MessageReceiver,
	Modules\DISCORD\DiscordAPIClient,
	Modules\DISCORD\DiscordController,
	Routing\Events\Base,
	Routing\Events\Online,
	Routing\RoutableEvent,
	Routing\Source,
	SettingManager,
	Text,
};

use Nadybot\Modules\DISCORD_GATEWAY_MODULE\DiscordGatewayController;

class DiscordChannel implements MessageReceiver {
	protected string $channel;
	protected string $id;

	#[NCA\Inject]
	private DiscordAPIClient $discordAPIClient;

	#[NCA\Inject]
	private DiscordGatewayController $discordGatewayController;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private DiscordController $discordController;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private Text $text;

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
		$guild = $this->discordGatewayController->getChannelGuild($this->id);
		$discordMsg = $this->discordController->formatMessage($message, $guild);

		if (isset($event->char)) {
			$minRankForMentions = $this->settingManager->getString('discord_relay_mention_rank') ?? "superadmin";
			$sendersRank = $this->accessManager->getAccessLevelForCharacter($event->char->name);
			if ($this->accessManager->compareAccessLevels($sendersRank, $minRankForMentions) < 0) {
				$discordMsg->allowed_mentions = (object)[
					"parse" => ["users", "everyone"],
				];
			}
		} else {
			$discordMsg->allowed_mentions = (object)[
				"parse" => ["everyone"],
			];
		}

		// Relay the message to the discord channel
		foreach ($discordMsg->split() as $msgPart) {
			async($this->discordAPIClient->queueToChannel(...), $this->id, $msgPart->toJSON())->ignore();
		}
		return true;
	}
}
