<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	Blob,
	Config\BotConfig,
	MessageHub,
	Modules\DISCORD\DiscordAPIClient,
	Modules\DISCORD\DiscordChannel,
	Modules\DISCORD\DiscordController,
	Nadybot,
	Routing\Character,
	Routing\RoutableMessage,
	Routing\Source,
	Types\CommandReply,
};

use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\{
	InteractionCallbackData,
	InteractionResponse,
};
use Revolt\EventLoop;

class DiscordSlashCommandReply implements CommandReply {
	#[NCA\Inject]
	private DiscordAPIClient $discordAPIClient;

	#[NCA\Inject]
	private DiscordController $discordController;

	#[NCA\Inject]
	private DiscordGatewayController $gw;

	#[NCA\Inject]
	private DiscordSlashCommandController $slashCtrl;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BotConfig $config;

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
		$response = new InteractionResponse(
			type: InteractionResponse::TYPE_DEFERRED_CHANNEL_MESSAGE_WITH_SOURCE,
			data: new InteractionCallbackData(
				flags: $this->slashCtrl->discordSlashCommands === $this->slashCtrl::SLASH_EPHEMERAL
					? InteractionCallbackData::EPHEMERAL
					: null
			)
		);
		EventLoop::queue(
			$this->discordAPIClient->sendInteractionResponse(...),
			$this->interactionId,
			$this->interactionToken,
			$this->discordAPIClient::encode($response),
		);
	}

	/** {@inheritDoc} */
	public function reply(string|array $msg): void {
		if (!is_array($msg)) {
			$msg = [$msg];
		}
		if (!count($msg)) {
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
			new Character($this->config->main->character, $this->chatBot->char?->id)
		);
		$guilds = $this->gw->getGuilds();
		$guild = isset($channel->guild_id) ? ($guilds[$channel->guild_id] ?? null) : null;
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
				Blob::create($msgPack)->getText(),
				$this->gw->getChannelGuild($this->channelId)
			);
			$messageObj->flags = $this->slashCtrl->discordSlashCommands === $this->slashCtrl::SLASH_EPHEMERAL
				? InteractionCallbackData::EPHEMERAL
				: null;
			foreach ($messageObj->split() as $msgPart) {
				$this->discordAPIClient->queueToWebhook(
					$this->applicationId,
					$this->interactionToken,
					$this->discordAPIClient::encode($msgPart),
				);
			}
		}
	}
}
