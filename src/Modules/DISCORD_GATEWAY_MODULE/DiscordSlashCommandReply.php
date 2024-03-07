<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use function Amp\async;
use Nadybot\Core\{
	Attributes as NCA,
	CommandReply,
	Config\BotConfig,
	MessageHub,
	Modules\DISCORD\DiscordAPIClient,
	Modules\DISCORD\DiscordChannel,
	Modules\DISCORD\DiscordController,
	Nadybot,
	Routing\Character,
	Routing\RoutableMessage,
	Routing\Source,
};

use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\{
	InteractionCallbackData,
	InteractionResponse,
};

class DiscordSlashCommandReply implements CommandReply {
	#[NCA\Inject]
	public DiscordAPIClient $discordAPIClient;

	#[NCA\Inject]
	public DiscordController $discordController;

	#[NCA\Inject]
	public DiscordGatewayController $gw;

	#[NCA\Inject]
	public DiscordSlashCommandController $slashCtrl;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public BotConfig $config;

	public function __construct(
		public string $applicationId,
		public string $interactionId,
		public string $interactionToken,
		public ?string $channelId,
		public bool $isDirectMsg=false,
	) {
	}

	/**
	 * Set our status to "XXX is thinking"
	 * This is needed, because the interactionToken is only valid for 3s.
	 * After these 3s, we can only send replies via regular webhooks.
	 * Some commands can take longer than 3s, so let's do this and add
	 * the actual result later.
	 */
	public function sendStateUpdate(): void {
		$response = new InteractionResponse();
		$response->type = $response::TYPE_DEFERRED_CHANNEL_MESSAGE_WITH_SOURCE;
		$response->data = new InteractionCallbackData();
		$response->data->flags = $this->slashCtrl->discordSlashCommands === $this->slashCtrl::SLASH_EPHEMERAL
			? InteractionCallbackData::EPHEMERAL
			: null;
		async(
			$this->discordAPIClient->sendInteractionResponse(...),
			$this->interactionId,
			$this->interactionToken,
			$this->discordAPIClient->encode($response),
		);
	}

	public function reply($msg): void {
		if (!is_array($msg)) {
			$msg = [$msg];
		}
		if (empty($msg)) {
			return;
		}

		if (!$this->isDirectMsg
			&& isset($this->channelId)
			&& $this->slashCtrl->discordSlashCommands === $this->slashCtrl::SLASH_REGULAR
		) {
			$channel = $this->gw->lookupChannel($this->channelId);
			if (isset($channel)) {
				foreach ($msg as $msgPack) {
					$this->routeToHub($channel, $msgPack);
				}
			}
		}
		$this->sendReplyToDiscord(...$msg);
	}

	/** Route the message to the MessageHub */
	protected function routeToHub(DiscordChannel $channel, string $message): void {
		$rMessage = new RoutableMessage($message);
		$rMessage->setCharacter(
			new Character($this->config->main->character, $this->chatBot->char->id)
		);
		$guilds = $this->gw->getGuilds();
		$guild = $guilds[$channel->guild_id] ?? null;
		$rMessage->prependPath(new Source(
			Source::DISCORD_PRIV,
			$channel->name ?? $channel->id,
			null,
			isset($guild) ? (int)$guild->id : null
		));
		$this->messageHub->handle($rMessage);
	}

	/** Send the given message-chunks to Discord via Webhook */
	private function sendReplyToDiscord(string ...$msg): void {
		for ($i = 0; $i < count($msg); $i++) {
			$msgPack = $msg[$i];
			$messageObj = $this->discordController->formatMessage(
				$msgPack,
				$this->gw->getChannelGuild($this->channelId)
			);
			$messageObj->flags = $this->slashCtrl->discordSlashCommands === $this->slashCtrl::SLASH_EPHEMERAL
				? InteractionCallbackData::EPHEMERAL
				: null;
			foreach ($messageObj->split() as $msgPart) {
				$this->discordAPIClient->queueToWebhook(
					$this->applicationId,
					$this->interactionToken,
					$this->discordAPIClient->encode($msgPart),
				);
			}
		}
	}
}
