<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CommandReply,
	MessageHub,
	Modules\DISCORD\DiscordAPIClient,
	Modules\DISCORD\DiscordChannel,
	Modules\DISCORD\DiscordController,
	Nadybot,
	Routing\Character,
	Routing\RoutableMessage,
	Routing\Source,
};
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\GuildMember;
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\InteractionCallbackData;
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\InteractionResponse;

class DiscordSlashCommandReply implements CommandReply {
	#[NCA\Inject]
	public DiscordAPIClient $discordAPIClient;

	#[NCA\Inject]
	public DiscordController $discordController;

	#[NCA\Inject]
	public DiscordGatewayController $discordGatewayController;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public Nadybot $chatBot;

	public function __construct(
		public string $interactionId,
		public string $interactionToken,
		public ?string $channelId,
		public bool $isDirectMsg=false,
	) {
	}

	public function reply($msg): void {
		if (!is_array($msg)) {
			$msg = [$msg];
		}
		$fakeGM = new GuildMember();
		$fakeGM->nick = $this->chatBot->char->name;
		$gw = $this->discordGatewayController;

		if (!$this->isDirectMsg
			&& isset($this->channelId)
			&& $gw->discordSlashCommands === $gw::SLASH_REGULAR
		) {
			$gw->lookupChannel(
				$this->channelId,
				function (DiscordChannel $channel, array $msg): void {
					foreach ($msg as $msgPack) {
						$this->routeToHub($channel, $msgPack);
					}
				},
				$msg
			);
		}

		foreach ($msg as $msgPack) {
			$messageObj = $this->discordController->formatMessage($msgPack);
			$response = new InteractionResponse();
			$response->type = $response::TYPE_CHANNEL_MESSAGE_WITH_SOURCE;
			$data = new InteractionCallbackData();
			$data->flags = $gw->discordSlashCommands === $gw::SLASH_EMPHEMERAL ? $data::EPHEMERAL : null;
			$data->allowed_mentions = $messageObj->allowed_mentions;
			$data->embeds = $messageObj->embeds;
			$data->content = $messageObj->content;
			$data->tts = $messageObj->tts;
			$response->data = $data;
			$this->discordAPIClient->post(
				DiscordAPIClient::DISCORD_API . "/interactions/{$this->interactionId}/{$this->interactionToken}/callback",
				\Safe\json_encode($response)
			)->withCallback(
				function(object $response): void {
				},
			);
		}
	}

	protected function routeToHub(DiscordChannel $channel, string $message): void {
		$rMessage = new RoutableMessage($message);
		$rMessage->setCharacter(
			new Character($this->chatBot->char->name, $this->chatBot->char->id)
		);
		$guilds = $this->discordGatewayController->getGuilds();
		$guild = $guilds[$channel->guild_id] ?? null;
		$rMessage->prependPath(new Source(
			Source::DISCORD_PRIV,
			$channel->name ?? $channel->id,
			null,
			isset($guild) ? (int)$guild->id : null
		));
		$this->messageHub->handle($rMessage);
	}
}
