<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use function Amp\{delay};
use function Safe\{json_decode, parse_url};

use Amp\File\Filesystem;
use Amp\Http\Client\{
	HttpClientBuilder,
	Request,
	TimeoutException,
};
use Amp\TimeoutCancellation;
use AO\Utils;
use DateTimeZone;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DB,
	DBSchema\Player,
	ModuleInstance,
	Nadybot,
	Registry,
	SQLException,
};
use Psr\Log\LoggerInterface;
use Safe\DateTime;
use Safe\Exceptions\JsonException;

/**
 * @author Tyrence (RK2)
 */
#[NCA\Instance]
class PlayerManager extends ModuleInstance {
	public const CACHE_GRACE_TIME = 87000;
	public const PORK_URL = "http://people.anarchy-online.com";
	public const BORK_URL = "https://bork.aobots.org";

	/** How many jobs in parallel to run to lookup missing character data */
	#[NCA\Setting\Options(options: ["Off" => 0, 1, 2, 3, 4, 5, 10])]
	public int $lookupJobs = 0;

	/** Which service to use for character lookups */
	#[NCA\Setting\Text(
		options: [
			"bork.aobots.org (Nadybot)" => self::BORK_URL,
			"people.anarchy-online.com (Funcom)" => self::PORK_URL,
		]
	)]
	public string $porkUrl = self::BORK_URL;

	public ?PlayerLookupJob $playerLookupJob = null;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private Filesystem $fs;

	#[NCA\Setup]
	public function setup(): void {
		$path = $this->config->paths->cache . '/players';
		if (!$this->fs->exists($path)) {
			$this->fs->createDirectory($path);
		}
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
		$this->playerLookupJob->run(function () {
			$this->playerLookupJob = null;
			$this->db->table("players")
				->where("last_update", "<", time() - 5*static::CACHE_GRACE_TIME)
				->delete();
		});
	}

	public function byName(string $name, ?int $dimension=null, bool $forceUpdate=false): ?Player {
		$dimension ??= $this->config->main->dimension;

		$name = Utils::normalizeCharacter($name);

		if (!preg_match("/^[A-Z][a-z0-9-]{3,11}$/", $name)) {
			return null;
		}
		$charid = null;
		if ($dimension === $this->config->main->dimension) {
			$charid = $this->chatBot->getUid($name);
		}

		$player = $this->findInDb($name, $dimension);

		if ($player === null || $forceUpdate) {
			$player = $this->lookup($name, $dimension);
			if ($player !== null && is_int($charid)) {
				$player->charid = $charid;
				$this->update($player);
			}
			return $player;
		} elseif ($player->last_update < (time() - static::CACHE_GRACE_TIME)) {
			// We cache for 24h plus 10 minutes grace for Funcom
			$player2 = $this->lookup($name, $dimension);
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
	}

	/** @return Collection<Player> */
	public function searchByNames(int $dimension, string ...$names): Collection {
		$names = array_map("ucfirst", array_map("strtolower", $names));
		return $this->db->table("players")
			->where("dimension", $dimension)
			->whereIn("name", $names)
			->asObj(Player::class);
	}

	/** @return Collection<Player> */
	public function searchByUids(int $dimension, int ...$uids): Collection {
		return $this->db->table("players")
			->where("dimension", $dimension)
			->whereIn("charid", $uids)
			->asObj(Player::class);
	}

	/** @return Collection<Player> */
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
		return $player;
	}

	public function lookup(string $name, int $dimension): ?Player {
		$client = $this->builder->build();
		$baseUrl = $this->porkUrl;
		$url = $baseUrl;
		$player = null;
		try {
			$try = 0;
			$retries = 5;
			while ($try++ < $retries) {
				try {
					$url = $baseUrl . "/character/bio/d/{$dimension}/name/{$name}/bio.xml?data_type=json";

					/** @todo Filecache
					 * $cache = new FileCache(
					 * $this->config->paths->cache . '/players',
					 * new LocalKeyedMutex()
					 * );
					 * $cacheKey = "{$name}.{$dimension}";
					 * $body = $cache->get($cacheKey);
					 */
					if (isset($body)) {
						$player = $this->parsePlayerFromBody($body);
						break;
					}

					$start = microtime(true);

					$timeout = null;
					if (str_contains($url, "bork")) {
						$timeout = new TimeoutCancellation(1);
					}
					$response = $client->request(new Request($url), $timeout);

					if ($response->getStatus() === 200) {
						$body = $response->getBody()->buffer();
						// $cache->set($cacheKey, $body, 60);
						$player = $this->parsePlayerFromBody($body);
					} else {
						$this->logger->debug("Looking up {name}.{dimension}: {code}", [
							"name" => $name,
							"dimension" => $dimension,
							"code" => $response->getStatus(),
						]);
					}
					$end = microtime(true);
					$this->logger->info("Lookup for {name} took {duration}ms", [
						"name" => $name,
						"duration" => $end - $start,
					]);
					break;
				} catch (\Amp\TimeoutException) {
					$baseUrl = self::PORK_URL;
				} catch (TimeoutException $e) {
					/** @psalm-suppress RedundantCast */
					$delay = (int)pow($try, 2);
					$this->logger->info("Lookup for {name}.{dimension} timed out, retrying in {delay}s ({try}/{retries})", [
						"name" => $name,
						"dimension" => $dimension,
						"try" => $try,
						"delay" => $delay,
						"retries" => $retries,
					]);
					if ($try < $retries) {
						delay($delay);
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
			/** @var ?string */
			$host = parse_url($url, PHP_URL_HOST);
			$player->source = $host ?? "people.anarchy-online.com";
			$player->dimension = $dimension;
		} else {
			$this->logger->info("No char information found about {character} on RK{dimension}", [
				"character" => $name,
				"dimension" => $dimension,
			]);
		}
		return $player;
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
		return $whois->getInfo($showFirstAndLastName);
	}

	/**
	 * Search for players in the database
	 *
	 * @param string   $search    Search term
	 * @param int|null $dimension Dimension to limit search to
	 *
	 * @return Player[]
	 *
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

		// $obj->charid        = $char->CHAR_INSTANCE;
		$obj->dimension      = $char->CHAR_DIMENSION;
		$luDateTime = DateTime::createFromFormat("Y/m/d H:i:s", $lastUpdated, new DateTimeZone("UTC"));
		$obj->last_update = $luDateTime->getTimestamp();

		return $obj;
	}
}
