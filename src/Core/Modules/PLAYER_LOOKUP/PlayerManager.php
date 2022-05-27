<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use function Amp\call;
use function Amp\asyncCall;
use function Amp\delay;
use function Safe\json_decode;

use Amp\Cache\FileCache;
use Amp\Http\Client\Connection\UnprocessedRequestException;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Client\TimeoutException;
use Amp\Promise;
use Amp\Sync\LocalKeyedMutex;
use Safe\DateTime;
use DateTimeZone;
use Generator;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	ConfigFile,
	DB,
	DBSchema\Player,
	ModuleInstance,
	LoggerWrapper,
	Nadybot,
	Registry,
	SettingManager,
	SQLException,
	Util,
};
use Safe\Exceptions\JsonException;

/**
 * @author Tyrence (RK2)
 */
#[NCA\Instance]
class PlayerManager extends ModuleInstance {
	public const CACHE_GRACE_TIME = 87000;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** How many jobs in parallel to run to lookup missing character data */
	#[NCA\Setting\Options(options: ["Off" => 0, 1, 2, 3, 4, 5, 10])]
	public int $lookupJobs = 0;

	public ?PlayerLookupJob $playerLookupJob = null;

	#[NCA\Setup]
	public function setup(): void {
		mkdir($this->config->cacheFolder . '/players');
	}

	#[NCA\Event(
		name: "timer(1h)",
		description: "Periodically lookup missing or outdated player data",
		defaultStatus: 1
	)]
	public function lookupMissingCharacterData(): void {
		if ($this->lookupJobs === 0) {
			return;
		}
		if (isset($this->playerLookupJob)) {
			return;
		}
		$this->playerLookupJob = new PlayerLookupJob();
		Registry::injectDependencies($this->playerLookupJob);
		$this->playerLookupJob->run(function() {
			$this->playerLookupJob = null;
			$this->db->table("players")
				->where("last_update", "<", time() - 5*static::CACHE_GRACE_TIME)
				->delete();
		});
	}

	/**
	 * @psalm-param callable(array<string,?Player>) $callback
	 * @param string[] $names
	 * @deprecated use all(byName()) instead
	 */
	public function massGetByName(callable $callback, array $names, int $dimension=null, bool $forceUpdate=false): void {
		/** @var array<string,?Player> */
		$result = [];
		$left = count($names);
		if ($left === 0) {
			$callback([]);
			return;
		}
		foreach ($names as $name) {
			$this->getByNameAsync(
				function(?Player $player) use (&$result, &$left, $callback, $name): void {
					$result[$name] = $player;
					$left--;
					if ($left === 0) {
						$callback($result);
					}
				},
				$name,
				$dimension,
				$forceUpdate
			);
		}
	}

	/** @return Promise<?Player> */
	public function byName(string $name, ?int $dimension=null, bool $forceUpdate=false): Promise {
		return call(function() use ($name, $dimension, $forceUpdate): Generator {
			$dimension ??= $this->config->dimension;

			$name = ucfirst(strtolower($name));

			if (!preg_match("/^[A-Z][a-z0-9-]{3,11}$/", $name)) {
				return null;
			}
			$charid = null;
			if ($dimension === $this->config->dimension) {
				$charid = yield $this->chatBot->getUid2($name);
			}

			$player = $this->findInDb($name, $dimension);

			if ($player === null || $forceUpdate) {
				$player = yield $this->lookupAsync2($name, $dimension);
				if ($player !== null && is_int($charid)) {
					$player->charid = $charid;
					$this->update($player);
				}
				return $player;
			} elseif ($player->last_update < (time() - static::CACHE_GRACE_TIME)) {
				// We cache for 24h plus 10 minutes grace for Funcom
				$player2 = yield $this->lookupAsync2($name, $dimension);
				if ($player2 !== null) {
					$player = $player2;
					if (is_int($charid)) {
						$player->charid = $charid;
						$this->update($player);
					}
				} else {
					$player->source .= ' (old-cache)';
				}
				return $player;
			}
			$player->source .= ' (current-cache)';
			return $player;
		});
	}

	/** @psalm-param callable(?Player) $callback */
	public function getByNameAsync(callable $callback, string $name, ?int $dimension=null, bool $forceUpdate=false): void {
		asyncCall(function() use ($callback, $name, $dimension, $forceUpdate): Generator {
			$player = yield $this->byName($name, $dimension, $forceUpdate);
			$callback($player);
			return null;
		});
	}

	/**
	 * @return Collection<Player>
	 */
	public function searchByNames(int $dimension, string ...$names): Collection {
		$names = array_map("ucfirst", array_map("strtolower", $names));
		return $this->db->table("players")
			->where("dimension", $dimension)
			->whereIn("name", $names)
			->asObj(Player::class);
	}

	/**
	 * @return Collection<Player>
	 */
	public function searchByUids(int $dimension, int ...$uids): Collection {
		return $this->db->table("players")
			->where("dimension", $dimension)
			->whereIn("charid", $uids)
			->asObj(Player::class);
	}

	/**
	 * @return Collection<Player>
	 */
	public function searchByColumn(int $dimension, string $column, mixed ...$values): Collection {
		return $this->db->table("players")
			->where("dimension", $dimension)
			->whereIn($column, $values)
			->asObj(Player::class);
	}

	public function findInDb(string $name, int $dimension): ?Player {
		$player = $this->db->table("players")
			->whereIlike("name", $name)
			->where("dimension", $dimension)
			->limit(1)
			->asObj(Player::class)
			->first();
		if (isset($player)) {
			$this->logger->info("Found cached information for {character} on RK{dimension}", [
				"character" => $name,
				"dimension" => $dimension,
				"data" => $player,
			]);
		} else {
			$this->logger->info("No cached information found for {character} on RK{dimension}", [
				"character" => $name,
				"dimension" => $dimension,
			]);
		}
		return  $player;
	}

	/** @return Promise<?Player> */
	public function lookupAsync2(string $name, int $dimension): Promise {
		return call(function () use ($name, $dimension): Generator {
			$client = HttpClientBuilder::buildDefault();
			$url = "http://people.anarchy-online.com/character/bio/d/{$dimension}/name/{$name}/bio.xml?data_type=json";
			$player = null;
			try {
				$try = 0;
				$retries = 5;
				while ($try++ < $retries) {
					try {
						$cache = new FileCache(
							$this->config->cacheFolder . '/players',
							new LocalKeyedMutex()
						);
						$cacheKey = "{$name}.{$dimension}";
						$body = yield $cache->get($cacheKey);
						if (isset($body)) {
							$player = $this->parsePlayerFromBody($body);
							break;
						}
						/** @var Response */
						$response = yield $client->request(new Request($url));
						if ($response->getStatus() === 200) {
							$body = yield $response->getBody()->buffer();
							$cache->set($cacheKey, $body, 60);
							$player = $this->parsePlayerFromBody($body);
						} else {
							$this->logger->debug("Looking up {name}.{dimension}: {code}", [
								"name" => $name,
								"dimension" => $dimension,
								"code" => $response->getStatus(),
							]);
						}
						break;
					} catch (TimeoutException | UnprocessedRequestException $e) {
						$delay = (int)pow($try, 2);
						$this->logger->info("Lookup for {name}.{dimension} timed out, retrying in {delay}s ({try}/{retries})", [
							"name" => $name,
							"dimension" => $dimension,
							"try" => $try,
							"delay" => $delay,
							"retries" => $retries,
						]);
						if ($try < $retries) {
							yield delay($delay * 1000);
						}
					}
				}
			} catch (\Throwable $e) {
				$this->logger->warning("Error looking up {name}.{dimension}: {error}", [
					"name" => $name,
					"dimension" => $dimension,
					"error" => $e->getMessage(),
				]);
			}
			if (isset($player) && $player->name === $name) {
				$player->source = 'people.anarchy-online.com';
				$player->dimension = $dimension;
			} else {
				$this->logger->info("No char information found about {character} on RK{dimension}", [
					"character" => $name,
					"dimension" => $dimension,
				]);
			}
			return $player;
		});
	}

	/** @psalm-param callable(?Player, mixed...) $callback */
	public function lookupAsync(string $name, int $dimension, callable $callback, mixed ...$args): void {
		asyncCall(function () use ($name, $dimension, $callback, $args): Generator {
			$player = yield $this->lookupAsync2($name, $dimension);
			$callback($player, ...$args);
		});
	}

	private function parsePlayerFromBody(string $body): ?Player {
		if ($body === "null") {
			return null;
		}
		try {
			[$char, $org, $lastUpdated] = json_decode($body);
		} catch (JsonException) {
			return null;
		}

		$obj = new Player();

		// parsing of the player data
		$obj->firstname      = trim($char->FIRSTNAME);
		$obj->name           = $char->NAME;
		$obj->lastname       = trim($char->LASTNAME);
		$obj->level          = $char->LEVELX;
		$obj->breed          = $char->BREED ?? '';
		$obj->gender         = $char->SEX ?? '';
		$obj->faction        = $char->SIDE ?? '';
		$obj->profession     = $char->PROF;
		$obj->prof_title     = $char->PROFNAME ?? '';
		$obj->ai_rank        = $char->RANK_name ?? '';
		$obj->ai_level       = $char->ALIENLEVEL;
		$obj->guild_id       = $org->ORG_INSTANCE;
		$obj->guild          = $org->NAME ?? '';
		$obj->guild_rank     = $org->RANK_TITLE ?? '';
		$obj->guild_rank_id  = $org->RANK;

		$obj->head_id        = $char->HEADID;
		$obj->pvp_rating     = $char->PVPRATING;
		$obj->pvp_title      = $char->PVPTITLE;

		//$obj->charid        = $char->CHAR_INSTANCE;
		$obj->dimension      = $char->CHAR_DIMENSION;
		$luDateTime = DateTime::createFromFormat("Y/m/d H:i:s", $lastUpdated, new DateTimeZone("UTC"));
		$obj->last_update = $luDateTime->getTimestamp();

		return $obj;
	}

	public function update(Player $char): void {
		$this->db->table("players")
			->upsert(
				[
					"charid" =>        $char->charid,
					"firstname" =>     $char->firstname,
					"name" =>          $char->name,
					"lastname" =>      $char->lastname,
					"level" =>         $char->level,
					"breed" =>         $char->breed,
					"gender" =>        $char->gender,
					"faction" =>       $char->faction,
					"profession" =>    $char->profession,
					"prof_title" =>    $char->prof_title,
					"ai_rank" =>       $char->ai_rank,
					"ai_level" =>      $char->ai_level,
					"guild_id" =>      $char->guild_id ?? 0,
					"guild" =>         $char->guild,
					"guild_rank" =>    $char->guild_rank,
					"guild_rank_id" => $char->guild_rank_id,
					"dimension" =>     $char->dimension,
					"head_id" =>       $char->head_id,
					"pvp_rating" =>    $char->pvp_rating,
					"pvp_title" =>     $char->pvp_title,
					"source" =>        $char->source,
					"last_update" =>   $char->last_update ?? time(),
				],
				["name", "dimension"]
			);
	}

	public function getInfo(Player $whois, bool $showFirstAndLastName=true): string {
		$msg = '';

		if ($showFirstAndLastName && strlen($whois->firstname??"")) {
			$msg = $whois->firstname . " ";
		}

		$msg .= "<highlight>\"{$whois->name}\"<end> ";

		if ($showFirstAndLastName && strlen($whois->lastname??"")) {
			$msg .= $whois->lastname . " ";
		}

		$msg .= "(<highlight>{$whois->level}<end>/<green>{$whois->ai_level}<end>";
		$msg .= ", {$whois->gender} {$whois->breed} <highlight>{$whois->profession}<end>";
		$msg .= ", <" . strtolower($whois->faction) . ">$whois->faction<end>";

		if ($whois->guild) {
			$msg .= ", {$whois->guild_rank} of <" . strtolower($whois->faction) . ">{$whois->guild}<end>)";
		} else {
			$msg .= ", Not in a guild)";
		}

		return $msg;
	}

	/**
	 * Search for players in the database
	 * @param string $search Search term
	 * @param int|null $dimension Dimension to limit search to
	 * @return Player[]
	 * @throws SQLException On error
	 */
	public function searchForPlayers(string $search, ?int $dimension=null): array {
		$query = $this->db->table("players")->orderBy("name")->limit(100);
		$searchTerms = explode(' ', $search);
		$this->db->addWhereFromParams($query, $searchTerms, "name");

		if ($dimension !== null) {
			$query->where("dimension", $dimension);
		}

		return $query->asObj(Player::class)->toArray();
	}
}
