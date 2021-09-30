<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\Channels\DiscordChannel as ChannelsDiscordChannel;
use Nadybot\Core\CommandReply;
use Nadybot\Core\MessageEmitter;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Modules\DISCORD\DiscordAPIClient;
use Nadybot\Core\Modules\DISCORD\DiscordChannel;
use Nadybot\Core\Modules\DISCORD\DiscordController;
use Nadybot\Core\Modules\DISCORD\DiscordMessageIn;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\Character;
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\GuildMember;

class DiscordMessageCommandReply implements CommandReply, MessageEmitter {
	/** @Inject */
	public DiscordAPIClient $discordAPIClient;

	/** @Inject */
	public DiscordController $discordController;

	/** @Inject */
	public DiscordGatewayController $discordGatewayController;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public Nadybot $chatBot;

	protected string $channelId;
	protected bool $isDirectMsg;
	protected ?DiscordMessageIn $message;

	public function __construct(string $channelId, bool $isDirectMsg=false, ?DiscordMessageIn $message=null) {
		$this->channelId = $channelId;
		$this->isDirectMsg = $isDirectMsg;
		$this->message = $message;
	}

	public function getChannelName(): string {
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
		$fakeGM->nick = $this->chatBot->vars["name"];
		if (!$this->isDirectMsg) {
			$this->discordGatewayController->lookupChannel(
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
			if (isset($this->message)) {
				$messageObj->message_reference = (object)["message_id" => $this->message->id];
			}
			$this->discordAPIClient->queueToChannel($this->channelId, $messageObj->toJSON());
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
			$channel->name,
			null,
			isset($guild) ? (int)$guild->id : null
		));
		$this->messageHub->handle($rMessage);
	}
}
