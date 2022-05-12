<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use function Safe\json_encode;
use Closure;
use Safe\Exceptions\JsonException;
use stdClass;
use Nadybot\Core\{
	AsyncHttp,
	Attributes as NCA,
	Http,
	HttpResponse,
	ModuleInstance,
	JSONDataModel,
	LoggerWrapper,
	Timer,
};
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\ApplicationCommand;
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\GuildMember;

/**
 * A Discord API-client
 */
#[NCA\Instance]
class DiscordAPIClient extends ModuleInstance {
	#[NCA\Inject]
	public Http $http;

	#[NCA\Inject]
	public Timer $timer;

	#[NCA\Inject]
	public DiscordController $discordCtrl;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/**
	 * @phpstan-var array{0: string, 1: string, 2: ?callable}[]
	 * @psalm-var array{0: string, 1: string, 2: ?callable}[]
	 */
	protected array $outQueue = [];
	protected bool $queueProcessing = false;

	/** @var array<string,array<string,GuildMember>> */
	protected $guildMemberCache = [];

	/** @var array<string,DiscordUser> */
	protected $userCache = [];

	public const DISCORD_API = "https://discord.com/api/v10";

	public function get(string $uri): AsyncHttp {
		$botToken = $this->discordCtrl->discordBotToken;
		return $this->http
			->get($uri)
			->withHeader('Authorization', "Bot $botToken");
	}

	public function post(string $uri, string $data): AsyncHttp {
		$botToken = $this->discordCtrl->discordBotToken;
		return $this->http
			->post($uri)
			->withPostData($data)
			->withHeader('Authorization', "Bot $botToken")
			->withHeader('Content-Type', 'application/json');
	}

	public function patch(string $uri, string $data): AsyncHttp {
		$botToken = $this->discordCtrl->discordBotToken;
		return $this->http
			->patch($uri)
			->withPostData($data)
			->withHeader('Authorization', "Bot $botToken")
			->withHeader('Content-Type', 'application/json');
	}

	public function put(string $uri, string $data): AsyncHttp {
		$botToken = $this->discordCtrl->discordBotToken;
		return $this->http
			->put($uri)
			->withPostData($data)
			->withHeader('Authorization', "Bot $botToken")
			->withHeader('Content-Type', 'application/json');
	}

	public function delete(string $uri): AsyncHttp {
		$botToken = $this->discordCtrl->discordBotToken;
		return $this->http
			->delete($uri)
			->withHeader('Authorization', "Bot $botToken");
	}

	public function getGateway(callable $callback): void {
		$this->get(
			self::DISCORD_API . "/gateway/bot"
		)->withCallback(
			$this->getErrorWrapper(
				new DiscordGateway(),
				$callback,
			)
		);
	}

	/** @phpstan-param callable(ApplicationCommand[]):void $success */
	public function registerGlobalApplicationCommands(
		string $applicationId,
		string $message,
		?callable $success=null,
		?callable $failure=null,
	): void {
		$this->put(
			self::DISCORD_API . "/applications/{$applicationId}/commands",
			$message,
		)->withCallback(
			$this->getErrorWrapper(
				null,
				function (array $commands) use ($success): void {
					$this->handleApplicationCommands($commands, $success);
				},
				$failure
			)
		);
	}

	/** @phpstan-param callable(ApplicationCommand):void $success */
	public function registerGuildApplicationCommand(
		string $guildId,
		string $applicationId,
		string $message,
		?callable $success=null,
		?callable $failure=null,
	): void {
		$this->post(
			self::DISCORD_API . "/applications/{$applicationId}/guilds/{$guildId}/commands",
			$message,
		)->withCallback(
			$this->getErrorWrapper(new ApplicationCommand(), $success, $failure)
		);
	}

	public function deleteGuildApplicationCommand(
		string $guildId,
		string $applicationId,
		string $commandId,
		?callable $success=null,
		?callable $failure=null,
	): void {
		$this->delete(
			self::DISCORD_API . "/applications/{$applicationId}/guilds/{$guildId}/commands/{$commandId}",
		)->withCallback(
			$this->getErrorWrapper(null, $success, $failure)
		);
	}

	public function deleteGlobalApplicationCommand(
		string $applicationId,
		string $commandId,
		?callable $success=null,
		?callable $failure=null,
	): void {
		$this->delete(
			self::DISCORD_API . "/applications/{$applicationId}/commands/{$commandId}",
		)->withCallback(
			$this->getErrorWrapper(null, $success, $failure)
		);
	}

	/** @phpstan-param callable(string, ApplicationCommand[]):void $success */
	public function getGuildApplicationCommands(
		string $guildId,
		string $applicationId,
		?callable $success=null,
		?callable $failure=null,
	): void {
		$this->get(
			self::DISCORD_API . "/applications/{$applicationId}/guilds/{$guildId}/commands",
		)->withCallback(
			$this->getErrorWrapper(
				null,
				function (array $commands) use ($guildId, $success): void {
					$this->handleGuildApplicationCommands($commands, $guildId, $success);
				},
				$failure
			)
		);
	}

	/** @phpstan-param callable(ApplicationCommand[]):void $success */
	public function getGlobalApplicationCommands(
		string $applicationId,
		?callable $success=null,
		?callable $failure=null,
	): void {
		$this->get(
			self::DISCORD_API . "/applications/{$applicationId}/commands",
		)->withCallback(
			$this->getErrorWrapper(
				null,
				function (array $commands) use ($success): void {
					$this->handleApplicationCommands($commands, $success);
				},
				$failure
			)
		);
	}

	/**
	 * @param stdClass[] $commands
	 * @phpstan-param callable(ApplicationCommand[]):void $callback
	 */
	protected function handleApplicationCommands(array $commands, ?callable $callback=null): void {
		$result = [];
		foreach ($commands as $command) {
			$appCmd = new ApplicationCommand();
			$appCmd->fromJSON($command);
			$result []= $appCmd;
		}
		if (isset($callback)) {
			$callback($result);
		}
	}

	/**
	 * @param stdClass[] $commands
	 * @phpstan-param callable(string, ApplicationCommand[]):void $callback
	 */
	protected function handleGuildApplicationCommands(array $commands, string $guildId, ?callable $callback=null): void {
		$result = [];
		foreach ($commands as $command) {
			$appCmd = new ApplicationCommand();
			$appCmd->fromJSON($command);
			$result []= $appCmd;
		}
		if (isset($callback)) {
			$callback($guildId, $result);
		}
	}

	public function sendInteractionResponse(
		string $interactionId,
		string $interactionToken,
		string $message,
		?callable $success=null,
		?callable $failure=null
	): void {
		$this->post(
			DiscordAPIClient::DISCORD_API . "/interactions/{$interactionId}/{$interactionToken}/callback",
			$message,
		)->withCallback(
			$this->getErrorWrapper(null, $success, $failure)
		);
	}

	public function leaveGuild(string $guildId, ?callable $success, ?callable $failure): void {
		$this->delete(
			self::DISCORD_API . "/users/@me/guilds/{$guildId}"
		)->withCallback(
			$this->getErrorWrapper(null, $success, $failure)
		);
	}

	public function queueToChannel(string $channel, string $message, ?callable $callback=null): void {
		$this->logger->info("Adding discord message to end of channel queue {channel}", [
			"channel" => $channel,
		]);
		$this->outQueue []= [$channel, $message, $callback];
		if ($this->queueProcessing === false) {
			$this->processQueue();
		}
	}

	public function sendToChannel(string $channel, string $message, ?callable $callback=null): void {
		$this->logger->info("Adding discord message to front of channel queue {channel}", [
			"channel" => $channel,
		]);
		array_unshift($this->outQueue, [$channel, $message, $callback]);
		if ($this->queueProcessing === false) {
			$this->processQueue();
		}
	}

	public function processQueue(): void {
		if (empty($this->outQueue)) {
			$this->queueProcessing = false;
			return;
		}
		$this->queueProcessing = true;
		$params = array_shift($this->outQueue);
		/** @psalm-suppress TooFewArguments */
		$this->immediatelySendToChannel(...$params);
	}

	protected function immediatelySendToChannel(string $channel, string $message, ?callable $callback=null): void {
		$this->logger->info("Sending message to discord channel {channel}", [
			"channel" => $channel,
			"message" => $message,
		]);
		$errorHandler = $this->getErrorWrapper(new DiscordMessageIn(), $callback);
		$this->post(
			self::DISCORD_API . "/channels/{$channel}/messages",
			$message
		)->withCallback(
			function(HttpResponse $response, array $message) use ($errorHandler): void {
				if (isset($response->headers) && $response->headers["status-code"] === "429") {
					array_unshift($this->outQueue, $message);
					$this->timer->callLater((int)($response->headers["retry-after"]??1), [$this, "processQueue"]);
				} else {
					$this->processQueue();
					$errorHandler(...func_get_args());
				}
			},
			func_get_args()
		);
	}

	public function sendToUser(string $user, DiscordMessageOut $message, ?callable $callback=null): void {
		$this->logger->info("Sending message to discord user {user}", [
			"user" => $user,
			"message" => $message,
		]);
		$this->post(
			self::DISCORD_API . "/users/@me/channels",
			json_encode((object)["recipient_id" => $user]),
		)->withCallback(
			$this->getErrorWrapper(
				new DiscordChannel(),
				function (DiscordChannel $channel) use ($message, $callback): void {
					$this->parseSendToUserReply($channel, $message->toJSON(), $callback);
				}
			)
		);
	}

	public function cacheUser(DiscordUser $user): void {
		$this->userCache[$user->id] = $user;
	}

	/** @psalm-param null|callable(DiscordUser, mixed...) $callback */
	public function cacheUserLookup(DiscordUser $user, ?callable $callback, mixed ...$args): void {
		$this->cacheUser($user);
		if (isset($callback)) {
			$callback($user, ...$args);
		}
	}

	/** @psalm-param callable(DiscordChannel, mixed...) $callback */
	public function getChannel(string $channelId, callable $callback, mixed ...$args): void {
		$this->logger->info("Looking up discord channel {channelId}", [
			"channelId" => $channelId,
		]);
		$this->get(
			self::DISCORD_API . "/channels/{$channelId}"
		)->withCallback(
			$this->getErrorWrapper(
				new DiscordChannel(),
				function (DiscordChannel $channel) use ($callback, $args): void {
					$callback($channel, ...$args);
				}
			)
		);
	}

	/** @psalm-param callable(DiscordUser, mixed...) $callback */
	public function getUser(string $userId, callable $callback, mixed ...$args): void {
		$this->logger->info("Looking up discord user {userId}", [
			"userId" => $userId,
		]);
		if (isset($this->userCache[$userId])) {
			$this->logger->debug("InformatiVon found in cache", [
				"cache" => $this->userCache[$userId],
			]);
			$callback($this->userCache[$userId], ...$args);
			return;
		}
		$this->get(
			self::DISCORD_API . "/users/{$userId}"
		)->withCallback(
			$this->getErrorWrapper(
				new DiscordUser(),
				function (DiscordUser $user) use ($callback, $args): void {
					$this->cacheUserLookup($user, $callback, ...$args);
				}
			)
		);
	}

	public function cacheGuildMember(string $guildId, GuildMember $member): void {
		$this->guildMemberCache[$guildId] ??= [];
		if (isset($member->user)) {
			$this->guildMemberCache[$guildId][$member->user->id] = $member;
		}
	}

	public function getGuildMembers(string $guildId, callable $callback, mixed ...$args): void {
		$this->getGuildMembersLowlevel($guildId, 2, null, [], $callback, ...$args);
	}

	/**
	 * @param stdClass[] $carry
	 */
	protected function getGuildMembersLowlevel(string $guildId, int $limit, ?string $after, array $carry, callable $callback, mixed ...$args): void {
		$this->logger->info("Looking up discord guild {guildId} members", [
			"guildId" => $guildId,
			"limit" => $limit,
			"after" => $after,
		]);
		$params = ["limit" => $limit];
		if (isset($after)) {
			$params["after"] = $after;
		}
		$this->get(
			self::DISCORD_API . "/guilds/{$guildId}/members"
		)->withQueryParams($params)
		->withCallback(
			$this->getErrorWrapper(
				null,
				function (array $members) use ($guildId, $limit, $after, $carry, $callback, $args): void {
					$this->handleGuildMembers($members, $guildId, $limit, $after, $carry, $callback, ...$args);
				}
			)
		);
	}

	/**
	 * @param stdClass[] $members
	 * @param stdClass[] $carry
	 */
	protected function handleGuildMembers(array $members, string $guildId, int $limit, ?string $after, array $carry, callable $callback, mixed ...$args): void {
		$carry = array_merge($carry, $members);
		if (count($members) === $limit) {
			$lastMember = $members[count($members)-1]->user?->id ?? null;
			$this->getGuildMembersLowlevel($guildId, $limit, $lastMember, $carry, $callback, ...$args);
			return;
		}
		$result = [];
		foreach ($carry as $member) {
			$o = new GuildMember();
			$o->fromJSON($member);
			if (!isset($o->user)) {
				continue;
			}
			$this->guildMemberCache[$guildId][$o->user->id] = $o;
			$result []= $o;
		}
		$callback($result, ...$args);
	}

	public function getGuildMember(string $guildId, string $userId, callable $callback, mixed ...$args): void {
		$this->logger->info("Looking up discord guild {guildId} member {userId}", [
			"guildId" => $guildId,
			"userId" => $userId,
		]);
		if (isset($this->guildMemberCache[$guildId][$userId])) {
			$this->logger->debug("Information found in cache", [
				"cache" => $this->guildMemberCache[$guildId][$userId],
			]);
			$callback($this->guildMemberCache[$guildId][$userId], ...$args);
			return;
		}
		$this->get(
			self::DISCORD_API . "/guilds/{$guildId}/members/{$userId}"
		)->withCallback(
			$this->getErrorWrapper(
				new GuildMember(),
				function (GuildMember $member) use ($guildId, $callback, $args): void {
					$this->cacheGuildMemberLookup($member, $guildId, $callback, ...$args);
				}
			)
		);
	}

	protected function cacheGuildMemberLookup(GuildMember $member, string $guildId, ?callable $callback, mixed ...$args): void {
		$this->cacheGuildMember($guildId, $member);
		if (isset($callback)) {
			$callback($member, ...$args);
		}
	}

	/**
	 * Create a new channel invite
	 * @phpstan-param callable(DiscordChannelInvite, mixed...):void $callback
	 */
	public function createChannelInvite(string $channelId, int $maxAge, int $maxUses, callable $callback, mixed ...$args): void {
		$this->post(
			self::DISCORD_API . "/channels/{$channelId}/invites",
			json_encode((object)[
				"max_age" => $maxAge,
				"max_uses" => $maxUses,
				"unique" => true,
			])
		)->withCallback(
			$this->getErrorWrapper(
				new DiscordChannelInvite(),
				function (DiscordChannelInvite $invite) use ($callback, $args): void {
					$callback($invite, ...$args);
				}
			)
		);
	}

	/**
	 * Get all currently valid guild invites for $guildId
	 * @phpstan-param callable(string, DiscordChannelInvite[]):void $success
	 * @phpstan-param null|callable(HttpResponse):bool $failure
	 */
	public function getGuildInvites(string $guildId, callable $success, ?callable $failure=null): void {
		$this->get(
			self::DISCORD_API . "/guilds/{$guildId}/invites"
		)->withCallback(
			$this->getErrorWrapper(
				null,
				function (array $invites) use ($guildId, $success): void {
					$this->handleChannelInvites($invites, $guildId, $success);
				},
				$failure
			)
		);
	}

	/**
	 * @param stdClass[] $invites
	 * @phpstan-param callable(string, DiscordChannelInvite[]):void $callback
	 */
	protected function handleChannelInvites(array $invites, string $guildId, callable $callback): void {
		$result = [];
		foreach ($invites as $invite) {
			$invObj = new DiscordChannelInvite();
			$invObj->fromJSON($invite);
			$result []= $invObj;
		}
		$callback($guildId, $result);
	}

	/**
	 * @phpstan-param callable(mixed): void $success
	 * @phpstan-param callable(HttpResponse): bool $failure
	 */
	protected function getErrorWrapper(?JSONDataModel $o, ?callable $success, ?callable $failure=null): Closure {
		return function(HttpResponse $response) use ($o, $success, $failure) {
			if (isset($response->error)) {
				$this->logger->error("Error from discord server: {error}", [
					"error" => $response->error,
					"response" => $response
				]);
				return;
			}
			// If we run into a ratelimit error, retry later
			if ($response->headers['status-code'] === "429" && isset($response->request)) {
				$waitFor = (int)ceil((float)$response->headers['x-ratelimit-reset-after']);
				$this->timer->callLater(
					$waitFor,
					function() use ($response, $o, $success, $failure): void {
						$request = $response->request;
						$method = strtolower($request->getMethod());
						if (!method_exists($this, $method)) {
							return;
						}
						$params = [$request->getURI()];
						if (in_array($method, ["post", "patch"])) {
							$params []= $request->getPostData()??"";
						}
						$this->{$method}(...$params)
							->withCallback($this->getErrorWrapper($o, $success, $failure));
					}
				);
				$this->logger->notice("Waiting for {$waitFor}s to retry...");
				return;
			}
			if (substr($response->headers['status-code'], 0, 1) !== "2") {
				if (isset($failure)) {
					$handled = $failure($response);
					if ($handled) {
						return;
					}
				}
				$this->logger->error(
					'Error received while sending message to Discord. ".
					"Status-Code: {statusCode}, Content: {content}, URL: {url}',
					[
						"statusCode" => $response->headers['status-code'],
						"content" => $response->body ?? '',
						"url" => (isset($response->request) ? $response->request->getURI() : "unknown"),
					]
				);
				return;
			}
			if ((int)$response->headers['status-code'] === 204) {
				if (isset($success)) {
					$success(new stdClass());
				}
				return;
			}
			if ($response->headers['content-type'] !== 'application/json') {
				$this->logger->error(
					'Non-JSON reply received from Discord Server. Content-Type: {contentType}',
					[
						"contentType" => $response->headers['content-type'],
						"response" => $response
					]
				);
				return;
			}
			try {
				$reply = \Safe\json_decode($response->body??"null");
			} catch (JsonException $e) {
				$this->logger->error('Error decoding JSON response from Discord-Server: {error}', [
					"error" => $e->getMessage(),
					"response" => $response->body,
					"exception" => $e,
				]);
				return;
			}
			if (isset($o)) {
				$o->fromJSON($reply);
				$this->logger->info("Decoded discord reply into {class}", [
					"class" => basename(str_replace('\\', '/', get_class($o))),
					"object" => $o,
				]);
				$reply = $o;
			}
			if (isset($success)) {
				$this->logger->info("Decoded discord reply into {class}", [
					"class" => "stdClass",
					"object" => $reply,
				]);
				$success($reply);
			}
		};
	}

	protected function parseSendToUserReply(DiscordChannel $channel, string $message, ?callable $callback=null): void {
		$this->queueToChannel($channel->id, $message, $callback);
	}
}
