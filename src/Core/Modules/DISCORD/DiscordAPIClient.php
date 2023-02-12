<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use function Amp\{call, delay};
use function Safe\{json_decode, json_encode};
use Amp\Http\Client\Body\JsonBody;
use Amp\Http\Client\Interceptor\SetRequestHeaderIfUnset;
use Amp\Http\Client\{HttpClient, HttpClientBuilder, Request, Response};
use Amp\{Deferred, Promise, Success};
use Exception;
use Generator;
use Nadybot\Core\{
	AsyncHttp,
	Attributes as NCA,
	Http,
	JSONDataModel,
	LoggerWrapper,
	ModuleInstance,
	SettingManager,
};
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\{ApplicationCommand, GuildMember};
use Safe\Exceptions\JsonException;
use stdClass;

/**
 * A Discord API-client
 */
#[NCA\Instance]
class DiscordAPIClient extends ModuleInstance {
	public const DISCORD_API = "https://discord.com/api/v10";
	#[NCA\Inject]
	public Http $http;

	#[NCA\Inject]
	public DiscordController $discordCtrl;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public HttpClientBuilder $builder;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** @var ChannelQueueItem[] */
	protected array $outQueue = [];
	protected bool $queueProcessing = false;

	/** @var WebhookQueueItem[] */
	protected array $webhookQueue = [];
	protected bool $webhookQueueProcessing = false;

	/** @var array<string,array<string,GuildMember>> */
	protected $guildMemberCache = [];

	/** @var array<string,DiscordUser> */
	protected $userCache = [];

	/**
	 * Encode the given data for sending it with the API
	 *
	 * @param mixed $data The data to be encoded
	 *
	 * @throws JsonException on encoding error
	 */
	public static function encode(mixed $data): string {
		$data = json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
		$data = preg_replace('/,"[^"]+":null/', '', $data);
		$data = preg_replace('/"[^"]+":null,/', '', $data);
		$data = preg_replace('/"[^"]+":null/', '', $data);
		return $data;
	}

	/** @deprecated */
	public function get(string $uri): AsyncHttp {
		$botToken = $this->discordCtrl->discordBotToken;
		return $this->http
			->get($uri)
			->withHeader('Authorization', "Bot {$botToken}");
	}

	/** @deprecated */
	public function post(string $uri, string $data): AsyncHttp {
		$botToken = $this->discordCtrl->discordBotToken;
		return $this->http
			->post($uri)
			->withPostData($data)
			->withHeader('Authorization', "Bot {$botToken}")
			->withHeader('Content-Type', 'application/json');
	}

	/** @deprecated */
	public function patch(string $uri, string $data): AsyncHttp {
		$botToken = $this->discordCtrl->discordBotToken;
		return $this->http
			->patch($uri)
			->withPostData($data)
			->withHeader('Authorization', "Bot {$botToken}")
			->withHeader('Content-Type', 'application/json');
	}

	/** @deprecated */
	public function put(string $uri, string $data): AsyncHttp {
		$botToken = $this->discordCtrl->discordBotToken;
		return $this->http
			->put($uri)
			->withPostData($data)
			->withHeader('Authorization', "Bot {$botToken}")
			->withHeader('Content-Type', 'application/json');
	}

	/** @deprecated */
	public function delete(string $uri): AsyncHttp {
		$botToken = $this->discordCtrl->discordBotToken;
		return $this->http
			->delete($uri)
			->withHeader('Authorization', "Bot {$botToken}");
	}

	/** @return Promise<DiscordGateway> */
	public function getGateway(): Promise {
		return $this->sendRequest(
			new Request(self::DISCORD_API . "/gateway/bot"),
			new DiscordGateway(),
		);
	}

	/** @return Promise<stdClass> */
	public function modifyGuildMember(string $guildId, string $userId, string $data): Promise {
		$uri = self::DISCORD_API . "/guilds/{$guildId}/members/{$userId}";
		$request = new Request($uri, "PATCH");
		$request->setBody(new DiscordBody($data));
		return $this->sendRequest($request, new stdClass());
	}

	/** @return Promise<ApplicationCommand[]> */
	public function registerGlobalApplicationCommands(
		string $applicationId,
		string $message,
	): Promise {
		$url = self::DISCORD_API . "/applications/{$applicationId}/commands";
		$request = new Request($url, "PUT");
		$request->setBody(new DiscordBody($message));
		return $this->sendRequest($request, [new ApplicationCommand()]);
	}

	/** @return Promise<stdClass> */
	public function deleteGlobalApplicationCommand(
		string $applicationId,
		string $commandId,
	): Promise {
		$url = self::DISCORD_API . "/applications/{$applicationId}/commands/{$commandId}";
		return $this->sendRequest(new Request($url, "DELETE"), new stdClass());
	}

	/** @return Promise<array<ApplicationCommand>> */
	public function getGlobalApplicationCommands(string $applicationId): Promise {
		return $this->sendRequest(
			new Request(self::DISCORD_API . "/applications/{$applicationId}/commands"),
			[new ApplicationCommand()]
		);
	}

	/** @return Promise<stdClass> */
	public function sendInteractionResponse(
		string $interactionId,
		string $interactionToken,
		string $message,
	): Promise {
		$url = DiscordAPIClient::DISCORD_API . "/interactions/{$interactionId}/{$interactionToken}/callback";
		$request = new Request($url, "POST");
		$request->setBody(new DiscordBody($message));
		return $this->sendRequest($request, new stdClass());
	}

	/** @return Promise<stdClass> */
	public function leaveGuild(string $guildId): Promise {
		return $this->sendRequest(new Request(
			self::DISCORD_API . "/users/@me/guilds/{$guildId}",
			"DELETE"
		), new stdClass());
	}

	/** @return Promise<void> */
	public function queueToChannel(string $channel, string $message): Promise {
		$this->logger->info("Adding discord message to end of channel queue {channel}", [
			"channel" => $channel,
		]);
		$deferred = new Deferred();
		$this->outQueue []= new ChannelQueueItem($channel, $message, $deferred);
		if ($this->queueProcessing === false) {
			$this->processQueue();
		}
		return $deferred->promise();
	}

	/** @return Promise<void> */
	public function sendToChannel(string $channel, string $message): Promise {
		$this->logger->info("Adding discord message to front of channel queue {channel}", [
			"channel" => $channel,
		]);
		$deferred = new Deferred();
		array_unshift($this->outQueue, new ChannelQueueItem($channel, $message, $deferred));
		if ($this->queueProcessing === false) {
			$this->processQueue();
		}
		return $deferred->promise();
	}

	/** @return Promise<void> */
	public function queueToWebhook(string $applicationId, string $interactionToken, string $message): Promise {
		$this->logger->info("Adding discord message to end of webhook queue {interaction}", [
			"channel" => $interactionToken,
		]);
		$deferred = new Deferred();
		$this->webhookQueue []= new WebhookQueueItem($applicationId, $interactionToken, $message, $deferred);
		if ($this->webhookQueueProcessing === false) {
			$this->processWebhookQueue();
		}
		return $deferred->promise();
	}

	/** @return Promise<void> */
	public function sendToUser(string $user, string $message): Promise {
		return call(function () use ($user, $message): Generator {
			$this->logger->info("Sending message to discord user {user}", [
				"user" => $user,
				"message" => $message,
			]);
			$request = new Request(self::DISCORD_API . "/users/@me/channels", "POST");
			$request->setBody(new JsonBody((object)["recipient_id" => $user]));
			$channel = yield $this->sendRequest($request, new DiscordChannel());
			yield $this->queueToChannel($channel->id, $message);
		});
	}

	public function cacheUser(DiscordUser $user): void {
		$this->userCache[$user->id] = $user;
	}

	/** @return Promise<DiscordChannel> */
	public function getChannel(string $channelId): Promise {
		$this->logger->info("Looking up discord channel {channelId}", [
			"channelId" => $channelId,
		]);
		$request = new Request(self::DISCORD_API . "/channels/{$channelId}");
		return $this->sendRequest($request, new DiscordChannel());
	}

	/** @return Promise<DiscordUser> */
	public function getUser(string $userId): Promise {
		$this->logger->info("Looking up discord user {userId}", [
			"userId" => $userId,
		]);
		if (isset($this->userCache[$userId])) {
			$this->logger->debug("Information found in cache", [
				"cache" => $this->userCache[$userId],
			]);
			return new Success($this->userCache[$userId]);
		}
		return call(function () use ($userId): Generator {
			$request = new Request(self::DISCORD_API . "/users/{$userId}");
			$user = yield $this->sendRequest($request, new DiscordUser());
			$this->cacheUser($user);
			return $user;
		});
	}

	public function cacheGuildMember(string $guildId, GuildMember $member): void {
		$this->guildMemberCache[$guildId] ??= [];
		if (isset($member->user)) {
			$this->guildMemberCache[$guildId][$member->user->id] = $member;
		}
	}

	/** @return Promise<GuildMember> */
	public function getGuildMember(string $guildId, string $userId): Promise {
		$this->logger->info("Looking up discord guild {guildId} member {userId}", [
			"guildId" => $guildId,
			"userId" => $userId,
		]);
		if (isset($this->guildMemberCache[$guildId][$userId])) {
			$this->logger->debug("Information found in cache", [
				"cache" => $this->guildMemberCache[$guildId][$userId],
			]);
			return new Success($this->guildMemberCache[$guildId][$userId]);
		}
		return call(function () use ($guildId, $userId): Generator {
			$request = new Request(self::DISCORD_API . "/guilds/{$guildId}/members/{$userId}");
			$member = yield $this->sendRequest($request, new GuildMember());
			$this->cacheGuildMember($guildId, $member);
			return $member;
		});
	}

	/**
	 * Create a new channel invite
	 *
	 * @return Promise<DiscordChannelInvite>
	 */
	public function createChannelInvite(string $channelId, int $maxAge, int $maxUses): Promise {
		$request = new Request(self::DISCORD_API . "/channels/{$channelId}/invites", "POST");
		$request->setBody(new JsonBody((object)[
				"max_age" => $maxAge,
				"max_uses" => $maxUses,
				"unique" => true,
			]));
		return $this->sendRequest($request, new DiscordChannelInvite());
	}

	/**
	 * Get all currently valid guild invites for $guildId
	 *
	 * @return Promise<DiscordChannelInvite[]>
	 */
	public function getGuildInvites(string $guildId): Promise {
		$request = new Request(self::DISCORD_API . "/guilds/{$guildId}/invites");
		return $this->sendRequest($request, [new DiscordChannelInvite()]);
	}

	private function getClient(): HttpClient {
		$botToken = $this->discordCtrl->discordBotToken;
		$client = $this->builder
			->intercept(new SetRequestHeaderIfUnset("Authorization", "Bot {$botToken}"))
			->intercept(new RetryRateLimits())
			->build();
		return $client;
	}

	private function processQueue(): void {
		if (empty($this->outQueue)) {
			$this->logger->info("Channel queue empty, stopping processing");
			$this->queueProcessing = false;
			return;
		}
		$this->queueProcessing = true;
		$item = array_shift($this->outQueue);
		Promise\rethrow($this->immediatelySendToChannel($item));
	}

	/** @return Promise<void> */
	private function immediatelySendToChannel(ChannelQueueItem $item): Promise {
		return call(function () use ($item): Generator {
			$this->logger->info("Sending message to discord channel {channel}", [
				"channel" => $item->channelId,
				"message" => $item->message,
			]);
			$url = self::DISCORD_API . "/channels/{$item->channelId}/messages";
			$request = new Request($url, "POST");
			$request->setBody(new DiscordBody($item->message));
			yield $this->sendRequest($request, new stdClass());
			if (isset($item->callback)) {
				$item->callback->resolve();
			}
			$this->processQueue();
		});
	}

	private function processWebhookQueue(): void {
		if (empty($this->webhookQueue)) {
			$this->webhookQueueProcessing = false;
			return;
		}
		$this->webhookQueueProcessing = true;
		$item = array_shift($this->webhookQueue);
		Promise\rethrow($this->immediatelySendToWebhook($item));
	}

	/** @return Promise<void> */
	private function immediatelySendToWebhook(WebhookQueueItem $item): Promise {
		return call(function () use ($item): Generator {
			$this->logger->info("Sending message to discord webhook {webhook}", [
				"webhook" => $item->interactionToken,
				"message" => $item->message,
			]);
			$url = self::DISCORD_API . "/webhooks/{$item->applicationId}/{$item->interactionToken}";
			$request = new Request($url, "POST");
			$request->setBody(new DiscordBody($item->message));
			yield $this->sendRequest($request, new stdClass());
			if (isset($item->deferred)) {
				$item->deferred->resolve();
			}
			$this->processWebhookQueue();
		});
	}

	/**
	 * @template T of JSONDataModel|stdClass|JSONDataModel[]
	 *
	 * @param T $o
	 *
	 * @return Promise<T>
	 */
	private function sendRequest(Request $request, JSONDataModel|stdClass|array $o): Promise {
		return call(function () use ($request, $o) {
			$client = $this->getClient();

			$retries = 3;
			do {
				$retry = false;
				$retries--;

				/** @var Response */
				$response = yield $client->request($request);
				$body = yield $response->getBody()->buffer();
				if ($response->getStatus() >= 500 && $response->getStatus() < 600 && $retries > 0) {
					$delayMs = 500;
					$this->logger->warning(
						"Got a {code} when sending message to Discord, retrying in {delay}ms",
						[
							"code" => $response->getStatus(),
							"delay" => $delayMs,
						]
					);
					$retry = true;
					yield delay($delayMs);
					continue;
				}
				if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
					throw new DiscordException(
						'Error received while sending message to Discord. '.
						'Status-Code: ' . $response->getStatus().
						", Content: {$body}, URL: ".$request->getUri(),
						$response->getStatus()
					);
				}
			} while ($retry && $retries > 0);
			if ($response->getStatus() === 204) {
				return new stdClass();
			}
			if ($response->getHeader('content-type') !== 'application/json') {
				throw new Exception(
					'Non-JSON reply received from Discord Server. '.
					'Content-Type: ' . ($response->getHeader('content-type') ?? '<empty>')
				);
			}
			$reply = json_decode($body);
			if (is_array($o)) {
				$result = [];
				foreach ($reply as $element) {
					$obj = clone $o[0];
					$obj->fromJSON($element);
					$result []= $obj;
				}
				$reply = $result;
			} elseif (is_object($o) && $o instanceof JSONDataModel) {
				$o->fromJSON($reply);
				$reply = $o;
			}
			if (is_object($reply)) {
				$this->logger->info("Decoded discord reply into {class}", [
					"class" => basename(str_replace('\\', '/', get_class($reply))),
					"object" => $reply,
				]);
			} elseif (is_array($reply)) {
				$this->logger->info("Decoded discord reply into an array", [
					"object" => $reply,
				]);
			}
			return $reply;
		});
	}
}
