<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use Safe\DateTime;
use DateTimeZone;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	ConfigFile,
	DB,
	DBSchema\Player,
	Http,
	HttpResponse,
	ModuleInstance,
	LoggerWrapper,
	Nadybot,
	Registry,
	SettingManager,
	SQLException,
	Util,
};

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

	#[NCA\Inject]
	public Http $http;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	public ?PlayerLookupJob $playerLookupJob = null;

	#[NCA\Setup]
	public function setup(): void {
		$this->settingManager->add(
			module: $this->moduleName,
			name: "lookup_jobs",
			description: "How many jobs in parallel to run to lookup missing character data",
			mode: "edit",
			type: "options",
			value: "0",
			options: "Off;1;2;3;4;5;10",
			intoptions: "0;1;2;3;4;5;10"
		);
	}

	#[NCA\Event(
		name: "timer(1h)",
		description: "Periodically lookup missing or outdated player data",
		defaultStatus: 1
	)]
	public function lookupMissingCharacterData(): void {
		if ($this->settingManager->getInt('lookup_jobs') === 0) {
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

	public function getByName(string $name, int $dimension=null, bool $forceUpdate=false): ?Player {
		$result = null;
		$this->getByNameCallback(
			function(?Player $player) use (&$result): void {
				$result = $player;
			},
			true,
			$name,
			$dimension,
			$forceUpdate
		);
		return $result;
	}

	/**
	 * @psalm-param callable(array<string,?Player>) $callback
	 * @param string[] $names
	 */
	public function massGetByNameAsync(callable $callback, array $names, int $dimension=null, bool $forceUpdate=false): void {
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

	/** @psalm-param callable(?Player) $callback */
	public function getByNameAsync(callable $callback, string $name, int $dimension=null, bool $forceUpdate=false): void {
		$this->getByNameCallback($callback, false, $name, $dimension, $forceUpdate);
	}

	/** @psalm-param callable(?Player) $callback */
	public function getByNameCallback(callable $callback, bool $sync, string $name, ?int $dimension=null, bool $forceUpdate=false): void {
		$dimension ??= $this->config->dimension;

		$name = ucfirst(strtolower($name));

		$charid = '';
		if (!preg_match("/^[A-Z][a-z0-9-]{3,11}$/", $name)) {
			$callback(null);
			return;
		}
		if ($dimension === $this->config->dimension) {
			$charid = $this->chatBot->get_uid($name);
		}

		$player = $this->findInDb($name, $dimension);
		$lookup = [$this, "lookupAsync"];
		if ($sync) {
			/** @psalm-param callable(?Player) $handler */
			$lookup = function(string $name, int $dimension, callable $handler): void {
				$player = $this->lookup($name, $dimension);
				$handler($player);
			};
		}

		if ($player === null || $forceUpdate) {
			$lookup(
				$name,
				$dimension,
				function(?Player $player) use ($charid, $callback): void {
					if ($player !== null && is_int($charid)) {
						$player->charid = $charid;
						$this->update($player);
					}
					$callback($player);
				}
			);
		} elseif ($player->last_update < (time() - static::CACHE_GRACE_TIME)) {
			// We cache for 24h plus 10 minutes grace for Funcom
			$lookup(
				$name,
				$dimension,
				function(?Player $player2) use ($charid, $callback, $player): void {
					if ($player2 !== null) {
						$player = $player2;
						if (is_int($charid)) {
							$player->charid = $charid;
							$this->update($player);
						}
					} else {
						$player->source .= ' (old-cache)';
					}
					$callback($player);
				}
			);
		} else {
			$player->source .= ' (current-cache)';
			$callback($player);
		}
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
			$this->logger->info("Found cached information found about {character} on RK{dimension}", [
				"character" => $name,
				"dimension" => $dimension,
				"data" => $player,
			]);
		} else {
			$this->logger->info("No cached information found about {character} on RK{dimension}", [
				"character" => $name,
				"dimension" => $dimension,
			]);
		}
		return  $player;
	}

	public function lookup(string $name, int $dimension): ?Player {
		$obj = $this->lookupUrl("http://people.anarchy-online.com/character/bio/d/$dimension/name/$name/bio.xml?data_type=json");
		if (isset($obj) && $obj->name === $name) {
			$obj->source = 'people.anarchy-online.com';
			$obj->dimension = $dimension;
			return $obj;
		}
		$this->logger->info("No char information found about {character} on RK{dimension}", [
			"character" => $name,
			"dimension" => $dimension,
		]);

		return null;
	}

	/** @psalm-param callable(?Player, mixed...) $callback */
	public function lookupAsync(string $name, int $dimension, callable $callback, mixed ...$args): void {
		$this->lookupUrlAsync(
			"http://people.anarchy-online.com/character/bio/d/$dimension/name/$name/bio.xml?data_type=json",
			function (?Player $player) use ($dimension, $name, $callback, $args): void {
				if (isset($player) && $player->name === $name) {
					$player->source = 'people.anarchy-online.com';
					$player->dimension = $dimension;
				} else {
					$this->logger->info("No char information found about {character} on RK{dimension}", [
						"character" => $name,
						"dimension" => $dimension,
					]);
				}
				$callback($player, ...$args);
			}
		);
	}

	private function lookupUrl(string $url): ?Player {
		$response = $this->http->get($url)->waitAndReturnResponse();
		return $this->parsePlayerFromLookup($response);
	}

	/** @psalm-param callable(?Player) $callback */
	private function lookupUrlAsync(string $url, callable $callback): void {
		$this->http
			->get($url)
			->withTimeout(10)
			->withCallback(
				function(HttpResponse $response) use ($callback): void {
					$callback($this->parsePlayerFromLookup($response));
				}
			);
	}

	private function parsePlayerFromLookup(HttpResponse $response): ?Player {
		if ($response->headers["status-code"] !== "200") {
			return null;
		}
		if (!isset($response->body) || $response->body === "null") {
			return null;
		}
		[$char, $org, $lastUpdated] = \Safe\json_decode($response->body);

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
