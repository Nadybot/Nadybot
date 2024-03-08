<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use function Amp\async;
use Nadybot\Core\{
	Attributes as NCA,
	Channels\DiscordChannel as ChannelsDiscordChannel,
	CommandReply,
	Config\BotConfig,
	MessageEmitter,
	MessageHub,
	Modules\DISCORD\DiscordAPIClient,
	Modules\DISCORD\DiscordChannel,
	Modules\DISCORD\DiscordController,
	Modules\DISCORD\DiscordMessageIn,
	Nadybot,
	Routing\Character,
	Routing\RoutableMessage,
	Routing\Source,
};

use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\GuildMember;

class DiscordMessageCommandReply implements CommandReply, MessageEmitter {
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

	#[NCA\Inject]
	public BotConfig $config;

	protected string $channelId;
	protected bool $isDirectMsg;
	protected ?DiscordMessageIn $message;

	public function __construct(string $channelId, bool $isDirectMsg=false, ?DiscordMessageIn $message=null) {
		$this->channelId = $channelId;
		$this->isDirectMsg = $isDirectMsg;
		$this->message = $message;
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

	public function reply($msg): void {
		if (!is_array($msg)) {
			$msg = [$msg];
		}
		$fakeGM = new GuildMember();
		$fakeGM->nick = $this->config->main->character;
		if (!$this->isDirectMsg) {
			$channel = $this->discordGatewayController->lookupChannel($this->channelId);
			if (isset($channel)) {
				foreach ($msg as $msgPack) {
					$this->routeToHub($channel, $msgPack);
				}
			}
		}
		foreach ($msg as $msgPack) {
			$messageObj = $this->discordController->formatMessage(
				$msgPack,
				$this->discordGatewayController->getChannelGuild($this->channelId)
					?? array_values($this->discordGatewayController->getGuilds())[0]
					?? null
			);
			if (isset($this->message)) {
				$messageObj->message_reference = (object)[
					"message_id" => $this->message->id,
					"channel_id" => $this->channelId,
				];
			}
			foreach ($messageObj->split() as $msgPart) {
				async($this->discordAPIClient->queueToChannel(...), $this->channelId, $msgPart->toJSON());
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
