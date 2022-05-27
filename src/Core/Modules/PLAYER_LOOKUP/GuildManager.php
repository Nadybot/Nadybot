<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use function Amp\call;
use function Amp\asyncCall;
use function Safe\json_decode;

use Amp\Cache\FileCache;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Promise;
use Amp\Sync\LocalKeyedMutex;

use Closure;
use DateInterval;
use DateTime;
use DateTimeZone;
use Exception;
use Generator;
use Safe\Exceptions\JsonException;
use Nadybot\Core\{
	Attributes as NCA,
	CacheManager,
	CacheResult,
	ConfigFile,
	DB,
	DBSchema\Player,
	EventManager,
	ModuleInstance,
	Nadybot,
};

/**
 * @author Tyrence (RK2)
 */
#[NCA\Instance]
class GuildManager extends ModuleInstance {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public CacheManager $cacheManager;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Setup]
	public function setup(): void {
		mkdir($this->config->cacheFolder . '/guild_roster');
	}

	protected function getJsonValidator(): Closure {
		return function(?string $data): bool {
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

	/**
	 * @psalm-param callable(?Guild, mixed...) $callback
	 */
	public function getByIdAsync(int $guildId, ?int $dimension, bool $forceUpdate, callable $callback, mixed ...$args): void {
		asyncCall(function() use ($guildId, $dimension, $forceUpdate, $callback, $args): Generator {
			$guild = yield $this->byId($guildId, $dimension, $forceUpdate);
			$callback($guild, ...$args);
		});
	}

	/** @return Promise<?Guild> */
	public function byId(int $guildID, ?int $dimension=null, bool $forceUpdate=false): Promise {
		return call(function() use ($guildID, $dimension, $forceUpdate): Generator {
			// if no server number is specified use the one on which the bot is logged in
			$dimension ??= $this->config->dimension;

			$url = "http://people.anarchy-online.com/org/stats/d/{$dimension}/name/{$guildID}/basicstats.xml?data_type=json";
			$maxCacheAge = 86400;
			if ($this->isMyGuild($guildID)) {
				$maxCacheAge = 21600;
			}
			$cache = new FileCache(
				$this->config->cacheFolder . '/guild_roster',
				new LocalKeyedMutex()
			);
			$cacheKey = "{$guildID}.{$dimension}";
			if (!$forceUpdate) {
				$cachedData = yield $cache->get($cacheKey);
				if (isset($cachedData)) {
					$body = $cachedData;
				}
			}
			if (!isset($body) || $body === '') {
				$client = HttpClientBuilder::buildDefault();
				/** @var Response */
				$response = yield $client->request(new Request($url));
				$body = yield $response->getBody()->buffer();
				$cache->set($cacheKey, $body, $maxCacheAge);
			}

			if ($body === '') {
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
			$promises = [];
			foreach ($members as $member) {
				$name = $member->NAME;
				if (!isset($this->chatBot->id[$name]) && !isset($member->CHAR_INSTANCE)) {
					$promises []= $this->chatBot->getUid2($name);
				}
			}
			yield \Amp\Promise\all($promises);

			foreach ($members as $member) {
				/** @var string */
				$name = $member->NAME;
				$charid = $member->CHAR_INSTANCE ?? $this->chatBot->id[$name] ?? null;
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

			$this->db->beginTransaction();

			$this->db->table("players")
				->where("guild_id", $guild->guild_id)
				->where("dimension", $dimension)
				->update([
					"guild_id" => 0,
					"guild" => ""
				]);

			foreach ($guild->members as $member) {
				$this->playerManager->update($member);
			}

			$this->db->commit();
			return $guild;
		});
	}

	/** @deprecated */
	public function getById(int $guildID, int $dimension=null, bool $forceUpdate=false): ?Guild {
		// if no server number is specified use the one on which the bot is logged in
		$dimension ??= $this->config->dimension;

		$url = "http://people.anarchy-online.com/org/stats/d/$dimension/name/$guildID/basicstats.xml?data_type=json";
		$groupName = "guild_roster";
		$filename = "$guildID.$dimension.json";
		$maxCacheAge = 86400;
		if ($this->isMyGuild($guildID)) {
			$maxCacheAge = 21600;
		}
		$cb = $this->getJsonValidator();

		$cacheResult = $this->cacheManager->lookup($url, $groupName, $filename, $cb, $maxCacheAge, $forceUpdate);
		$result = null;
		$this->handleGuildLookup(
			$cacheResult,
			$guildID,
			$dimension,
			function(?Guild $guild) use (&$result): void {
				$result = $guild;
			}
		);
		return $result;
	}

	/**
	 * Check if $guildId is the bot's guild id
	 */
	public function isMyGuild(int $guildId): bool {
		return isset($this->config->orgId)
			&& $this->config->orgId === $guildId;
	}

	/** @psalm-param callable(?Guild, mixed...) $callback */
	private function handleGuildLookup(CacheResult $cacheResult, int $guildID, int $dimension, callable $callback, mixed ...$args): void {

		// if there is still no valid data available give an error back
		if ($cacheResult->success !== true) {
			$callback(null, ...$args);
			return;
		}

		[$orgInfo, $members, $lastUpdated] = json_decode($cacheResult->data??"");

		if ($orgInfo->NAME === null) {
			$callback(null, ...$args);
			return;
		}

		// parsing of the member data
		$guild = new Guild();
		$guild->guild_id = $guildID;
		$guild->governing_form = $orgInfo->GOVERNINGNAME;
		$guild->orgname = $orgInfo->NAME;
		$guild->orgside = $orgInfo->SIDE_NAME;
		$luDateTime = DateTime::createFromFormat("Y/m/d H:i:s", $lastUpdated, new DateTimeZone("UTC"));
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
		foreach ($members as $member) {
			$name = $member->NAME;
			if (!isset($this->chatBot->id[$name]) && !isset($member->CHAR_INSTANCE)) {
				$this->chatBot->sendLookupPacket($name);
			}
		}

		foreach ($members as $member) {
			/** @var string */
			$name = $member->NAME;
			$charid = $member->CHAR_INSTANCE ?? $this->chatBot->get_uid($name);
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

		// this is done separately from the loop above to prevent nested transaction errors from occurring
		// when looking up charids for characters
		if ($cacheResult->usedCache === false) {
			$this->db->beginTransaction();

			$this->db->table("players")
				->where("guild_id", $guild->guild_id)
				->where("dimension", $dimension)
				->update([
					"guild_id" => 0,
					"guild" => ""
				]);

			foreach ($guild->members as $member) {
				$this->playerManager->update($member);
			}

			$this->db->commit();
		}

		$callback($guild, ...$args);
	}
}
