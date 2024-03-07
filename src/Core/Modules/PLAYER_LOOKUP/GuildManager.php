<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use function Amp\Future\await;
use function Amp\{async, delay};
use function Safe\json_decode;
use Amp\Http\Client\{HttpClientBuilder, Request, TimeoutException};
use Amp\TimeoutCancellation;

use Closure;
use DateInterval;
use DateTime;
use DateTimeZone;
use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	CacheManager,
	Config\BotConfig,
	DB,
	DBSchema\Player,
	EventManager,
	LoggerWrapper,
	ModuleInstance,
	Nadybot,
};
use Safe\Exceptions\JsonException;

/**
 * @author Tyrence (RK2)
 */
#[NCA\Instance]
class GuildManager extends ModuleInstance {
	#[NCA\Inject]
	public HttpClientBuilder $builder;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public BotConfig $config;

	#[NCA\Inject]
	public CacheManager $cacheManager;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Setup]
	public function setup(): void {
		mkdir($this->config->paths->cache . '/guild_roster');
	}

	/** @psalm-param callable(?Guild, mixed...) $callback */
	public function getByIdAsync(int $guildId, ?int $dimension, bool $forceUpdate, callable $callback, mixed ...$args): void {
		async(function () use ($guildId, $dimension, $forceUpdate, $callback, $args): void {
			$guild =  $this->byId($guildId, $dimension, $forceUpdate);
			$callback($guild, ...$args);
		});
	}

	public function byId(int $guildID, ?int $dimension=null, bool $forceUpdate=false): ?Guild {
		// if no server number is specified use the one on which the bot is logged in
		$dimension ??= $this->config->main->dimension;
		$body = null;

		$baseUrl = $this->playerManager->porkUrl;
		$maxCacheAge = 86400;
		if ($this->isMyGuild($guildID)) {
			$maxCacheAge = 21600;
		}

		/** @todo FileCache
		 * $cache = new FileCache(
		 * $this->config->paths->cache . '/guild_roster',
		 * new LocalKeyedMutex()
		 * );
		 * $cacheKey = "{$guildID}.{$dimension}";
		 * $fromCache = true;
		 * if (!$forceUpdate) {
		 * $body = yield $cache->get($cacheKey);
		 * }
		 */
		$try = 0;
		while ((!isset($body) || $body === '') && $try < 3) {
			try {
				$url = $baseUrl . "/org/stats/d/{$dimension}/name/{$guildID}/basicstats.xml?data_type=json";
				$start = microtime(true);
				$try++;
				$client = $this->builder->build();
				$timeout = null;
				if (str_contains($url, "bork")) {
					$timeout = new TimeoutCancellation(10);
				}
				$response = $client->request(new Request($url), $timeout);

				$body = $response->getBody()->buffer();
				$end = microtime(true);
				$this->logger->info("Getting {url} took {duration}ms", [
					"url" => $url,
					"duration" => $end - $start,
				]);
				// $cache->set($cacheKey, $body, $maxCacheAge);
				$fromCache = false;
			} catch (\Amp\TimeoutException) {
				$baseUrl = $this->playerManager::PORK_URL;
			} catch (TimeoutException $e) {
				/** @psalm-suppress RedundantCast */
				$delay = (int)pow($try, 2);
				$this->logger->info("Lookup for ORG {guild} D{dimension} timed out, retrying in {delay}s ({try}/{retries})", [
					"guild" => $guildID,
					"dimension" => $dimension,
					"try" => $try,
					"delay" => $delay,
					"retries" => 3,
				]);
				if ($try < 3) {
					delay($delay);
				}
			}
		}

		if (!isset($body) || $body === '') {
			throw new Exception("Empty data received when reading org data");
		}

		[$orgInfo, $members, $lastUpdated] = json_decode($body);

		if ($orgInfo->NAME === null) {
			return null;
		}

		// parsing of the member data
		$guild = new Guild();
		$guild->guild_id = $guildID;
		$guild->governing_form = $orgInfo->GOVERNINGNAME;
		$guild->orgname = $orgInfo->NAME;
		$guild->orgside = $orgInfo->SIDE_NAME;
		$luDateTime = DateTime::createFromFormat("Y/m/d H:i:s", $lastUpdated, new DateTimeZone("UTC"));
		// Try to reduce the cache time to the last updated time + 24h
		if ($luDateTime) {
			$newCacheDuration = max(60, 86400 - (time() - $luDateTime->getTimestamp()));
			// $cache->set($cacheKey, $body, $newCacheDuration);
		}
		if ($luDateTime && $this->isMyGuild($guild->guild_id)) {
			$guild->last_update = $luDateTime->getTimestamp();
			// Try to time the next rosterupdate to occur 1 day and 10m after the last export
			$key = $this->eventManager->getKeyForCronEvent(24*3600, 'guildcontroller.downloadOrgRosterEvent');
			if (isset($key)) {
				$nextTime = $luDateTime->add(new DateInterval("P1DT10M"));
				if ($nextTime->getTimestamp() > time()) {
					$this->eventManager->setCronNextEvent($key, $nextTime->getTimestamp());
				}
			}
		}

		// pre-fetch the charids...this speeds things up immensely
		$futures = [];
		foreach ($members as $member) {
			$name = $member->NAME;
			if (($this->chatBot->getUid($name, true) === null) && !isset($member->CHAR_INSTANCE)) {
				$futures []= async($this->chatBot->getUid(...), $name);
			}
		}
		await($futures);

		foreach ($members as $member) {
			/** @var string */
			$name = $member->NAME;
			$charid = $member->CHAR_INSTANCE ?? $this->chatBot->getUid($name, true);
			if ($charid === null || $charid === false) {
				$charid = 0;
			}

			$guild->members[$name]                = new Player();
			$guild->members[$name]->charid        = $charid;
			$guild->members[$name]->firstname     = trim($member->FIRSTNAME);
			$guild->members[$name]->name          = $name;
			$guild->members[$name]->lastname      = trim($member->LASTNAME);
			$guild->members[$name]->level         = $member->LEVELX;
			$guild->members[$name]->breed         = $member->BREED;
			$guild->members[$name]->gender        = $member->SEX;
			$guild->members[$name]->faction       = $guild->orgside;
			$guild->members[$name]->profession    = $member->PROF;
			$guild->members[$name]->prof_title    = $member->PROF_TITLE;
			$guild->members[$name]->ai_rank       = $member->DEFENDER_RANK_TITLE;
			$guild->members[$name]->ai_level      = $member->ALIENLEVEL;
			$guild->members[$name]->guild_id      = $guild->guild_id;
			$guild->members[$name]->guild         = $guild->orgname;
			$guild->members[$name]->guild_rank    = $member->RANK_TITLE;
			$guild->members[$name]->guild_rank_id = $member->RANK;
			$guild->members[$name]->dimension     = $dimension;
			$guild->members[$name]->source        = 'org_roster';

			$guild->members[$name]->head_id       = $member->HEADID;
			$guild->members[$name]->pvp_rating    = $member->PVPRATING;
			$guild->members[$name]->pvp_title     = $member->PVPTITLE;
		}

		// If this result is from our cache, then this information is already present
		// if ($fromCache) {
		// 	return $guild;
		// }
		$this->db->awaitBeginTransaction();

		$this->db->table("players")
			->where("guild_id", $guild->guild_id)
			->where("dimension", $dimension)
			->update([
				"guild_id" => 0,
				"guild" => "",
			]);

		foreach ($guild->members as $member) {
			$this->playerManager->update($member);
		}

		$this->db->commit();
		return $guild;
	}

	/** Check if $guildId is the bot's guild id */
	public function isMyGuild(int $guildId): bool {
		return isset($this->config->orgId)
			&& $this->config->orgId === $guildId;
	}

	protected function getJsonValidator(): Closure {
		return function (?string $data): bool {
			try {
				if ($data === null) {
					return false;
				}
				$result = json_decode($data);
				return $result !== null;
			} catch (JsonException $e) {
				return false;
			}
		};
	}
}
