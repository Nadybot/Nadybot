<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use function Amp\delay;
use function Safe\{json_decode, json_encode};
use Amp\Http\Client\{BufferedContent, HttpClient, HttpClientBuilder, Request};
use Amp\Http\Client\Interceptor\SetRequestHeaderIfUnset;
use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	HttpRetryRateLimits,
	Hydrator,
	ModuleInstance,
	Safe,
};
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use RuntimeException;
use Safe\Exceptions\JsonException;
use stdClass;
use Throwable;

/**
 * A Discord API-client
 *
 * @psalm-suppress InternalMethod
 */
#[NCA\Instance]
class DiscordAPIClient extends ModuleInstance {
	public const DISCORD_API = 'https://discord.com/api/v10';

	/** @var list<ChannelQueueItem> */
	protected array $outQueue = [];
	protected bool $queueProcessing = false;

	/** @var list<WebhookQueueItem> */
	protected array $webhookQueue = [];
	protected bool $webhookQueueProcessing = false;

	/** @var array<string,array<string,GuildMember>> */
	protected array $guildMemberCache = [];

	/** @var array<string,DiscordUser> */
	protected array $userCache = [];

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DiscordController $discordCtrl;

	#[NCA\Inject]
	private HttpClientBuilder $builder;

	/**
	 * Encode the given data for sending it with the API
	 *
	 * @param mixed $data The data to be encoded
	 *
	 * @throws JsonException on encoding error
	 */
	public static function encode(mixed $data): string {
		$data = json_encode($data, \JSON_UNESCAPED_SLASHES|\JSON_UNESCAPED_UNICODE|\JSON_INVALID_UTF8_SUBSTITUTE);
		$data = Safe::pregReplace('/,"[^"]+":null/', '', $data);
		$data = Safe::pregReplace('/"[^"]+":null,/', '', $data);
		$data = Safe::pregReplace('/"[^"]+":null/', '', $data);
		return $data;
	}

	public function getGateway(): DiscordGateway {
		$body = $this->sendRequest(new Request(self::DISCORD_API . '/gateway/bot'));
		return Hydrator::hydrate(DiscordGateway::class, json_decode($body, true));
	}

	public function modifyGuildMember(string $guildId, string $userId, string $data): stdClass {
		$uri = self::DISCORD_API . "/guilds/{$guildId}/members/{$userId}";
		$request = new Request($uri, 'PATCH');
		$request->setBody(new DiscordBody($data));
		return json_decode($this->sendRequest($request), false);
	}

	/** @return list<ApplicationCommand> */
	public function registerGlobalApplicationCommands(
		string $applicationId,
		string $message,
	): array {
		$url = self::DISCORD_API . "/applications/{$applicationId}/commands";
		$request = new Request($url, 'PUT');
		$request->setBody(new DiscordBody($message));
		$json = $this->sendRequest($request);
		return Hydrator::hydrateObjects(ApplicationCommand::class, json_decode($json, true))->toArray();
	}

	public function deleteGlobalApplicationCommand(
		string $applicationId,
		string $commandId,
	): stdClass {
		$url = self::DISCORD_API . "/applications/{$applicationId}/commands/{$commandId}";
		return json_decode($this->sendRequest(new Request($url, 'DELETE')), false);
	}

	/** @return list<ApplicationCommand> */
	public function getGlobalApplicationCommands(string $applicationId): array {
		$json = $this->sendRequest(new Request(self::DISCORD_API . "/applications/{$applicationId}/commands"));
		return Hydrator::hydrateObjects(ApplicationCommand::class, json_decode($json, true))->toArray();
	}

	public function sendInteractionResponse(
		string $interactionId,
		string $interactionToken,
		string $message,
	): stdClass {
		$url = DiscordAPIClient::DISCORD_API . "/interactions/{$interactionId}/{$interactionToken}/callback";
		$request = new Request($url, 'POST');
		$request->setBody(new DiscordBody($message));
		return json_decode($this->sendRequest($request), false);
	}

	public function leaveGuild(string $guildId): stdClass {
		$request = new Request(self::DISCORD_API . "/users/@me/guilds/{$guildId}", 'DELETE');
		return json_decode($this->sendRequest($request), false);
	}

	public function queueToChannel(string $channel, string $message): void {
		$this->logger->info('Adding discord message to end of channel queue {channel}', [
			'channel' => $channel,
		]);
		$suspension = EventLoop::getSuspension();
		$this->outQueue []= new ChannelQueueItem($channel, $message, $suspension);
		if ($this->queueProcessing === false) {
			$this->processQueue();
		}
		$suspension->suspend();
	}

	public function sendToChannel(string $channel, string $message): void {
		$this->logger->info('Adding discord message to front of channel queue {channel}', [
			'channel' => $channel,
		]);
		$suspension = EventLoop::getSuspension();
		array_unshift($this->outQueue, new ChannelQueueItem($channel, $message, $suspension));
		if ($this->queueProcessing === false) {
			$this->processQueue();
		}
		$suspension->suspend();
	}

	public function queueToWebhook(string $applicationId, string $interactionToken, string $message): void {
		$this->logger->info('Adding discord message to end of webhook queue {interaction}', [
			'interaction' => $interactionToken,
		]);
		$suspension = EventLoop::getSuspension();
		$this->webhookQueue []= new WebhookQueueItem($applicationId, $interactionToken, $message, $suspension);
		if ($this->webhookQueueProcessing === false) {
			$this->processWebhookQueue();
		}
		$suspension->suspend();
	}

	public function sendToUser(string $user, string $message): void {
		$this->logger->info('Sending message to discord user {user}', [
			'user' => $user,
			'message' => $message,
		]);
		$request = new Request(self::DISCORD_API . '/users/@me/channels', 'POST');
		$request->setBody(BufferedContent::fromString(
			json_encode(['recipient_id' => $user]),
			'application/json; charset=utf-8'
		));
		$json = $this->sendRequest($request);
		$channel = Hydrator::hydrate(DiscordChannel::class, json_decode($json, true));
		$this->queueToChannel($channel->id, $message);
	}

	public function cacheUser(DiscordUser $user): void {
		$this->userCache[$user->id] = $user;
	}

	public function getChannel(string $channelId): DiscordChannel {
		$this->logger->info('Looking up discord channel {channelId}', [
			'channelId' => $channelId,
		]);
		$request = new Request(self::DISCORD_API . "/channels/{$channelId}");
		$json = $this->sendRequest($request);
		return Hydrator::hydrate(DiscordChannel::class, json_decode($json, true));
	}

	public function getUser(string $userId): DiscordUser {
		$this->logger->info('Looking up discord user {userId}', [
			'userId' => $userId,
		]);
		if (isset($this->userCache[$userId])) {
			$this->logger->debug('Information found in cache', [
				'cache' => $this->userCache[$userId],
			]);
			return $this->userCache[$userId];
		}
		$request = new Request(self::DISCORD_API . "/users/{$userId}");
		$json = $this->sendRequest($request);
		$user = Hydrator::hydrate(DiscordUser::class, json_decode($json, true));
		$this->cacheUser($user);
		return $user;
	}

	public function cacheGuildMember(string $guildId, GuildMember $member): void {
		$this->guildMemberCache[$guildId] ??= [];
		if (isset($member->user)) {
			$this->guildMemberCache[$guildId][$member->user->id] = $member;
		}
	}

	public function getGuildMember(string $guildId, string $userId): GuildMember {
		$this->logger->info('Looking up discord guild {guildId} member {userId}', [
			'guildId' => $guildId,
			'userId' => $userId,
		]);
		if (isset($this->guildMemberCache[$guildId][$userId])) {
			$this->logger->debug('Information found in cache', [
				'cache' => $this->guildMemberCache[$guildId][$userId],
			]);
			return $this->guildMemberCache[$guildId][$userId];
		}
		$request = new Request(self::DISCORD_API . "/guilds/{$guildId}/members/{$userId}");
		$json = $this->sendRequest($request);
		$member = Hydrator::hydrate(GuildMember::class, json_decode($json, true));
		$this->cacheGuildMember($guildId, $member);
		return $member;
	}

	/** Create a new channel invite */
	public function createChannelInvite(string $channelId, int $maxAge, int $maxUses): DiscordChannelInvite {
		$request = new Request(self::DISCORD_API . "/channels/{$channelId}/invites", 'POST');
		$request->setBody(BufferedContent::fromString(
			json_encode([
				'max_age' => $maxAge,
				'max_uses' => $maxUses,
				'unique' => true,
			]),
			'application/json; charset=utf-8'
		));
		$json = $this->sendRequest($request);
		return Hydrator::hydrate(DiscordChannelInvite::class, json_decode($json, true));
	}

	/**
	 * Get all currently valid guild invites for $guildId
	 *
	 * @return list<DiscordChannelInvite>
	 */
	public function getGuildInvites(string $guildId): array {
		$request = new Request(self::DISCORD_API . "/guilds/{$guildId}/invites");
		$json = json_decode($this->sendRequest($request), true);
		return Hydrator::hydrateObjects(DiscordChannelInvite::class, $json)->toArray();
	}

	/**
	 * Get all currently set guild events from Discord for $guildId
	 *
	 * @return list<DiscordScheduledEvent>
	 */
	public function getGuildEvents(string $guildId): array {
		$request = new Request(self::DISCORD_API . "/guilds/{$guildId}/scheduled-events?with_user_count=true");
		$json = json_decode($this->sendRequest($request), true);
		return Hydrator::hydrateObjects(DiscordScheduledEvent::class, $json)->toArray();
	}

	/**
	 * Get all currently registered Emojis for $guildId
	 *
	 * @return list<Emoji>
	 */
	public function getEmojis(string $guildId): array {
		$request = new Request(self::DISCORD_API . "/guilds/{$guildId}/emojis");
		$json = json_decode($this->sendRequest($request), true);
		return Hydrator::hydrateObjects(Emoji::class, $json)->toArray();
	}

	/** Register a new Emoji in the $guildId */
	public function createEmoji(string $guildId, string $name, string $image): Emoji {
		$request = new Request(self::DISCORD_API . "/guilds/{$guildId}/emojis", 'POST');
		$request->setBody(BufferedContent::fromString(
			json_encode([
				'name' => $name,
				'image' => $image,
				'roles' => [],
			]),
			'application/json; charset=utf-8'
		));
		$json = json_decode($this->sendRequest($request), true);
		return Hydrator::hydrate(Emoji::class, $json);
	}

	/** Change the data for an already existing Emoji */
	public function changeEmoji(string $guildId, Emoji $emoji, string $image): Emoji {
		if (!isset($emoji->id) || !isset($emoji->name)) {
			throw new RuntimeException('Wrong emoji given');
		}
		$request = new Request(self::DISCORD_API . "/guilds/{$guildId}/emojis/{$emoji->id}", 'PATCH');
		$request->setBody(BufferedContent::fromString(
			json_encode([
				'name' => $emoji->name,
				'image' => $image,
				'roles' => [],
			]),
			'application/json; charset=utf-8'
		));
		$json = json_decode($this->sendRequest($request), true);
		return Hydrator::hydrate(Emoji::class, $json);
	}

	/** Delete an already existing emoji */
	public function deleteEmoji(string $guildId, string $emojiId): stdClass {
		$request = new Request(self::DISCORD_API . "/guilds/{$guildId}/emojis/{$emojiId}", 'DELETE');
		return json_decode($this->sendRequest($request), false);
	}

	private function getClient(): HttpClient {
		$botToken = $this->discordCtrl->discordBotToken;
		$client = $this->builder
			->intercept(new SetRequestHeaderIfUnset('Authorization', "Bot {$botToken}"))
			->intercept(new HttpRetryRateLimits())
			->build();
		return $client;
	}

	private function processQueue(): void {
		$this->logger->info('Processing discord-queue');
		if (!count($this->outQueue)) {
			$this->logger->info('Channel queue empty, stopping processing');
			$this->queueProcessing = false;
			return;
		}
		$this->queueProcessing = true;
		$item = array_shift($this->outQueue);
		EventLoop::queue($this->immediatelySendToChannel(...), $item);
	}

	private function immediatelySendToChannel(ChannelQueueItem $item): void {
		try {
			$this->logger->info('Sending message to discord channel {channel}', [
				'channel' => $item->channelId,
				'message' => $item->message,
			]);
			$url = self::DISCORD_API . "/channels/{$item->channelId}/messages";
			$request = new Request($url, 'POST');
			$request->setBody(new DiscordBody($item->message));
			$result = $this->sendRequest($request);
			if (isset($item->suspension)) {
				$item->suspension->resume($result);
			}
		} catch (Throwable $e) {
			$this->logger->error('Sending message failed: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			if (isset($item->suspension)) {
				$item->suspension->throw($e);
			}
		}
		$this->processQueue();
	}

	private function processWebhookQueue(): void {
		if (!count($this->webhookQueue)) {
			$this->webhookQueueProcessing = false;
			return;
		}
		$this->webhookQueueProcessing = true;
		$item = array_shift($this->webhookQueue);
		EventLoop::queue($this->immediatelySendToWebhook(...), $item);
	}

	private function immediatelySendToWebhook(WebhookQueueItem $item): void {
		try {
			$this->logger->info('Sending message to discord webhook {webhook}', [
				'webhook' => $item->interactionToken,
				'message' => $item->message,
			]);
			$url = self::DISCORD_API . "/webhooks/{$item->applicationId}/{$item->interactionToken}";
			$request = new Request($url, 'POST');
			$request->setBody(new DiscordBody($item->message));
			$result = $this->sendRequest($request);
			if (isset($item->suspension)) {
				$item->suspension->resume($result);
			}
		} catch (Throwable $e) {
			$this->logger->error('Error sending request: {error}. Dropping message', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			if (isset($item->suspension)) {
				$item->suspension->throw($e);
			}
		}
		$this->processWebhookQueue();
	}

	private function sendRequest(Request $request): string {
		$client = $this->getClient();
		$maxTries = 3;
		$retries = $maxTries;
		$response = null;
		do {
			$retry = false;
			$retries--;
			try {
				if ($retries < $maxTries -1) {
					$this->logger->notice('Retrying discord-message');
				}

				$response = $client->request($request);

				$body = $response->getBody()->buffer();
				if ($response->getStatus() >= 500 && $response->getStatus() < 600) {
					$delay = 0.5;
					$this->logger->warning(
						'Got a {code} when sending message to Discord{retry}',
						[
							'retry' => ($retries > 0) ? ", retrying in {$delay}s" : '',
							'code' => $response->getStatus(),
						]
					);
					$retry = true;
					if ($retries > 0) {
						delay($delay);
					}
					continue;
				}
			} catch (\Exception $e) {
				$delay = 0.5;
				$this->logger->error(
					'Error sending message to discord: {error}{retry}',
					[
						'retry' => ($retries > 0) ? ", retrying in {$delay}s" : '',
						'error' => $e->getMessage(),
						'delay' => $delay,
						'exception' => $e,
					]
				);
				$retry = true;
				if ($retries > 0) {
					delay($delay);
				}
				continue;
			}
			if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
				throw new DiscordException(
					'Error received while sending message to Discord. '.
					'Status-Code: ' . $response->getStatus().
					", Content: {$body}, URL: " . (string)$request->getUri(),
					$response->getStatus()
				);
			}
		} while ($retry && $retries > 0);

		if (!isset($response) || !isset($body)) {
			throw new DiscordException("Unable to send message with {$maxTries} tries");
		}
		if ($retries !== $maxTries -1) {
			$this->logger->notice('Message sent successfully.');
		}
		if ($response->getStatus() === 204) {
			return '{}';
		}
		if ($response->getHeader('content-type') !== 'application/json') {
			throw new Exception(
				'Non-JSON reply received from Discord Server. '.
				'Content-Type: ' . ($response->getHeader('content-type') ?? '<empty>')
			);
		}
		return $body;
	}
}
