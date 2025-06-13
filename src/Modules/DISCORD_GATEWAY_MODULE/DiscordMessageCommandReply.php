<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	Blob,
	Channels\DiscordChannel as ChannelsDiscordChannel,
	Config\BotConfig,
	MessageHub,
	Modules\DISCORD\DiscordAPIClient,
	Modules\DISCORD\DiscordChannel,
	Modules\DISCORD\DiscordController,
	Modules\DISCORD\DiscordMessageIn,
	Nadybot,
	Routing\Character,
	Routing\RoutableMessage,
	Routing\Source,
	Types\CommandReply,
	Types\MessageEmitter,
};
use Nadybot\Core\Modules\DISCORD\DiscordMessageReference;
use Revolt\EventLoop;

class DiscordMessageCommandReply implements CommandReply, MessageEmitter {
	#[NCA\Inject]
	private DiscordAPIClient $discordAPIClient;

	#[NCA\Inject]
	private DiscordController $discordController;

	#[NCA\Inject]
	private DiscordGatewayController $discordGatewayController;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BotConfig $config;

	public function __construct(
		private string $channelId,
		private bool $isDirectMsg=false,
		private ?DiscordMessageIn $message=null
	) {
	}

	public function getChannelName(): string {
		if ($this->isDirectMsg) {
			return Source::DISCORD_MSG . "({$this->channelId})";
		}
		$emitters = $this->messageHub->getEmitters();
		foreach ($emitters as $emitter) {
			if ($emitter instanceof ChannelsDiscordChannel
				&& $emitter->getChannelID() === $this->channelId
			) {
				return $emitter->getChannelName();
			}
		}
		return Source::DISCORD_PRIV . "({$this->channelId})";
	}

	/** {@inheritDoc} */
	public function reply(string|array $msg): void {
		if (!is_array($msg)) {
			$msg = [$msg];
		}
		if (!$this->isDirectMsg) {
			$channel = $this->discordGatewayController->lookupChannel($this->channelId);
			if (isset($channel)) {
				foreach ($msg as $msgPack) {
					$this->routeToHub($channel, $msgPack);
				}
			}
		}
		// @TODO: Move the logic of paging to formatMessage instead of hard-coding 3k
		$msg = (array)Blob::renderMulti(text: $msg, pageSize: 3_000, formatMessage: false);
		foreach ($msg as $msgPack) {
			$messageObj = $this->discordController->formatMessage(
				Blob::create($msgPack)->getText(),
				$this->discordGatewayController->getChannelGuild($this->channelId)
					?? array_values($this->discordGatewayController->getGuilds())[0]
					?? null
			);
			if (isset($this->message)) {
				$messageObj->message_reference = new DiscordMessageReference(
					message_id: $this->message->id,
					channel_id: $this->channelId,
				);
			}
			foreach ($messageObj->split() as $msgPart) {
				EventLoop::queue(
					$this->discordAPIClient->queueToChannel(...),
					$this->channelId,
					$msgPart->toJSON()
				);
			}
		}
	}

	protected function routeToHub(DiscordChannel $channel, string $message): void {
		$rMessage = new RoutableMessage($message);
		$rMessage->setCharacter(
			new Character($this->config->main->character, $this->chatBot->char?->id)
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
