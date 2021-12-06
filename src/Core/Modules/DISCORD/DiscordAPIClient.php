<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Closure;
use JsonException;
use stdClass;
use Nadybot\Core\{
	AsyncHttp,
	Http,
	HttpResponse,
	JSONDataModel,
	LoggerWrapper,
	SettingManager,
	Timer,
};
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\GuildMember;

/**
 * A Discord API-client
 * @Instance
 */
class DiscordAPIClient {
	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Http $http;

	/** @Inject */
	public Timer $timer;

	/** @Logger */
	public LoggerWrapper $logger;

	protected array $outQueue = [];
	protected bool $queueProcessing = false;

	/** @var array<string,array<string,GuildMember>> */
	protected $guildMemberCache = [];

	/** @var array<string,DiscordUser> */
	protected $userCache = [];

	public const DISCORD_API = "https://discord.com/api/v9";

	public function get(string $uri): AsyncHttp {
		$botToken = $this->settingManager->getString('discord_bot_token');
		return $this->http
			->get($uri)
			->withHeader('Authorization', "Bot $botToken");
	}

	public function post(string $uri, string $data): AsyncHttp {
		$botToken = $this->settingManager->getString('discord_bot_token');
		return $this->http
			->post($uri)
			->withPostData($data)
			->withHeader('Authorization', "Bot $botToken")
			->withHeader('Content-Type', 'application/json');
	}

	public function queueToChannel(string $channel, string $message, ?callable $callback=null): void {
		$this->outQueue []= func_get_args();
		if ($this->queueProcessing === false) {
			$this->processQueue();
		}
	}

	public function sendToChannel(string $channel, string $message, ?callable $callback=null): void {
		array_unshift($this->outQueue, func_get_args());
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
		$this->immediatelySendToChannel(...$params);
	}

	protected function immediatelySendToChannel(string $channel, string $message, ?callable $callback=null): void {
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
		$this->post(
			self::DISCORD_API . "/users/@me/channels",
			json_encode((object)["recipient_id" => $user]),
		)->withCallback(
			$this->getErrorWrapper(
				new DiscordChannel(),
				[$this, "parseSendToUserReply"],
				$message->toJSON(),
				$callback
			)
		);
	}

	public function cacheUser(DiscordUser $user): void {
		$this->userCache[$user->id] = $user;
	}

	/** @psalm-param null|callable(DiscordUser, mixed...) $callback */
	public function cacheUserLookup(DiscordUser $user, ?callable $callback, ...$args): void {
		$this->cacheUser($user);
		if (isset($callback)) {
			$callback($user, ...$args);
		}
	}

	/** @psalm-param callable(DiscordChannel, mixed...) $callback */
	public function getChannel(string $channelId, callable $callback, ...$args): void {
		$this->get(
			self::DISCORD_API . "/channels/{$channelId}"
		)->withCallback(
			$this->getErrorWrapper(
				new DiscordChannel(),
				$callback,
				...$args
			)
		);
	}

	/** @psalm-param callable(DiscordUser, mixed...) $callback */
	public function getUser(string $userId, callable $callback, ...$args): void {
		if (isset($this->userCache[$userId])) {
			$callback($this->userCache[$userId], ...$args);
			return;
		}
		$this->get(
			self::DISCORD_API . "/users/{$userId}"
		)->withCallback(
			$this->getErrorWrapper(
				new DiscordUser(),
				[$this, "cacheUserLookup"],
				$callback,
				...$args
			)
		);
	}

	public function cacheGuildMember(string $guildId, GuildMember $member): void {
		$this->guildMemberCache[$guildId] ??= [];
		if (isset($member->user)) {
			$this->guildMemberCache[$guildId][$member->user->id] = $member;
		}
	}

	public function getGuildMember(string $guildId, string $userId, callable $callback, ...$args): void {
		if (isset($this->guildMemberCache[$guildId][$userId])) {
			$callback($this->guildMemberCache[$guildId][$userId], ...$args);
			return;
		}
		$this->get(
			self::DISCORD_API . "/guilds/{$guildId}/members/{$userId}"
		)->withCallback(
			$this->getErrorWrapper(
				new GuildMember(),
				[$this, "cacheGuildMemberLookup"],
				$guildId,
				$callback,
				...$args
			)
		);
	}

	protected function cacheGuildMemberLookup(GuildMember $member, string $guildId, ?callable $callback, ...$args): void {
		$this->cacheGuildMember($guildId, $member);
		if (isset($callback)) {
			$callback($member, ...$args);
		}
	}

	protected function getErrorWrapper(?JSONDataModel $o, ?callable $callback, ...$args): Closure {
		return function(HttpResponse $response) use ($o, $callback, $args) {
			if (isset($response->error)) {
				$this->logger->error($response->error);
				return;
			}
			if (substr($response->headers['status-code'], 0, 1) !== "2") {
				$this->logger->error(
					'Error received while sending message to Discord. Status-Code: '.
					$response->headers['status-code'].
					', Content: '.($response->body ?? '') . ", URL: ".(
					isset($response->request) ? $response->request->getURI() : "unknown")
				);
				return;
			}
			if ((int)$response->headers['status-code'] === 204) {
				if (isset($callback)) {
					$callback(new stdClass(), ...$args);
				}
				return;
			}
			if ($response->headers['content-type'] !== 'application/json') {
				$this->logger->error(
					'Non-JSON reply received from Discord Server. Content-Type: '.
					$response->headers['content-type']
				);
				return;
			}
			try {
				$reply = json_decode($response->body??"null", false, 512, JSON_THROW_ON_ERROR);
			} catch (JsonException $e) {
				$this->logger->error(
					'Error decoding JSON response from Discord-Server: '.
					$e->getMessage(),
					["exception" => $e]
				);
				return;
			}
			if (isset($o)) {
				$o->fromJSON($reply);
				$reply = $o;
			}
			if (isset($callback)) {
				$callback($reply, ...$args);
			}
		};
	}

	protected function parseSendToUserReply(DiscordChannel $channel, string $message, ?callable $callback=null): void {
		$this->queueToChannel($channel->id, $message, $callback);
	}
}
