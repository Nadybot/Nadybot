<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use function Amp\delay;
use function Safe\{json_decode, parse_url, preg_match};

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
	Exceptions\SQLException,
	ModuleInstance,
	Nadybot,
	Registry,
	Types\Faction,
	Types\Profession,
	Types\Status,
};
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Revolt\EventLoop;
use Safe\DateTimeImmutable;
use Safe\Exceptions\JsonException;

/**
 * @author Tyrence (RK2)
 */
#[NCA\Instance]
class PlayerManager extends ModuleInstance {
	/** @var int */
	public const CACHE_GRACE_TIME = 87_000;
	public const PORK_URL = 'http://people.anarchy-online.com';
	public const BORK_URL = 'https://bork.aobots.org';

	/** How many jobs in parallel to run to lookup missing character data */
	#[NCA\Setting\Options(options: ['Off' => 0, 1, 2, 3, 4, 5, 10])]
	public int $lookupJobs = 0;

	/** Which service to use for character look-ups */
	#[NCA\Setting\Text(
		options: [
			'bork.aobots.org (Nadybot)' => self::BORK_URL,
			'people.anarchy-online.com (Funcom)' => self::PORK_URL,
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

	#[NCA\Cache(prefix: 'players')]
	private CacheInterface $cache;

	/** Periodically lookup missing or outdated player data */
	#[NCA\HandlesEvent(mask: 'timer(1h)', defaultStatus: Status::Enabled)]
	public function lookupMissingCharacterData(): void {
		if ($this->lookupJobs === 0) {
			return;
		}
		if (isset($this->playerLookupJob)) {
			return;
		}
		$this->playerLookupJob = new PlayerLookupJob();
		Registry::injectDependencies($this->playerLookupJob);
		EventLoop::queue(function (): void {
			$this->playerLookupJob?->run();
			$this->playerLookupJob = null;
			$this->db->table(Player::getTable())
				->where('last_update', '<', time() - 5*static::CACHE_GRACE_TIME)
				->delete();
		});
	}

	public function byName(string $name, ?int $dimension=null, bool $forceUpdate=false): ?Player {
		$dimension ??= $this->config->main->dimension;

		$name = Utils::normalizeCharacter($name);

		if (!preg_match('/^[A-Z][a-z0-9-]{3,11}$/', $name)) {
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
		} elseif (($player->last_update??0) < (time() - static::CACHE_GRACE_TIME)) {
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

	/** @return Collection<int,Player> */
	public function searchByNames(int $dimension, string ...$names): Collection {
		$names = array_map('ucfirst', array_map('strtolower', $names));
		return $this->db->table(Player::getTable())
			->where('dimension', $dimension)
			->whereIn('name', $names)
			->asObj(Player::class);
	}

	/** @return Collection<int,Player> */
	public function searchByUids(int $dimension, int ...$uids): Collection {
		return $this->db->table(Player::getTable())
			->where('dimension', $dimension)
			->whereIn('charid', $uids)
			->asObj(Player::class);
	}

	/** @return Collection<int,Player> */
	public function searchByColumn(int $dimension, string $column, mixed ...$values): Collection {
		return $this->db->table(Player::getTable())
			->where('dimension', $dimension)
			->whereIn($column, $values)
			->asObj(Player::class);
	}

	public function findInDb(string $name, int $dimension): ?Player {
		$player = $this->db->table(Player::getTable())
			->whereIlike('name', $name)
			->where('dimension', $dimension)
			->firstObj(Player::class);
		if (isset($player)) {
			$this->logger->info('Found cached information for {character} on RK{dimension}', [
				'character' => $name,
				'dimension' => $dimension,
				'data' => $player,
			]);
		} else {
			$this->logger->info('No cached information found for {character} on RK{dimension}', [
				'character' => $name,
				'dimension' => $dimension,
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

					$cacheKey = "{$name}.{$dimension}";
					$body = $this->cache->get($cacheKey);

					if (isset($body)) {
						$player = $this->parsePlayerFromBody($body);
						break;
					}

					$start = microtime(true);

					$timeout = null;
					if (str_contains($url, 'bork')) {
						$timeout = new TimeoutCancellation(1);
					}
					$response = $client->request(new Request($url), $timeout);

					if ($response->getStatus() === 200) {
						$body = $response->getBody()->buffer();
						$this->cache->set($cacheKey, $body, 60);
						$player = $this->parsePlayerFromBody($body);
					} else {
						$this->logger->debug('Looking up {name}.{dimension}: {code}', [
							'name' => $name,
							'dimension' => $dimension,
							'code' => $response->getStatus(),
						]);
					}
					$end = microtime(true);
					$this->logger->info('Lookup for {name} took {duration}ms', [
						'name' => $name,
						'duration' => $end - $start,
					]);
					break;
				} catch (\Amp\TimeoutException | \Amp\CancelledException) {
					$baseUrl = self::PORK_URL;
				} catch (TimeoutException) {
					/** @psalm-suppress RedundantCast */
					$delay = (int)pow($try, 2); // @phpstan-ignore-line
					$this->logger->info('Lookup for {name}.{dimension} timed out, retrying in {delay}s ({try}/{retries})', [
						'name' => $name,
						'dimension' => $dimension,
						'try' => $try,
						'delay' => $delay,
						'retries' => $retries,
					]);
					if ($try < $retries) {
						delay($delay);
					}
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Error looking up {name}.{dimension}: {error} ({class})', [
				'name' => $name,
				'dimension' => $dimension,
				'error' => $e->getMessage(),
				'class' => $e::class,
				'exception' => $e,
			]);
		}
		if (isset($player) && $player->name === $name) {
			/** @var ?string */
			$host = parse_url($url, \PHP_URL_HOST);
			$player->source = $host ?? 'people.anarchy-online.com';
			$player->dimension = $dimension;
		} else {
			$this->logger->info('No char information found about {character} on RK{dimension}', [
				'character' => $name,
				'dimension' => $dimension,
			]);
		}
		return $player;
	}

	public function update(Player $char): void {
		$save = clone $char;
		$save->last_update ??= time();
		$this->db->upsert($save);
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
	 * @return Collection<int,Player>
	 *
	 * @throws SQLException On error
	 */
	public function searchForPlayers(string $search, ?int $dimension=null): Collection {
		$query = $this->db->table(Player::getTable())->orderBy('name')->limit(100);
		$searchTerms = explode(' ', $search);
		$this->db->addWhereFromParams($query, $searchTerms, 'name');

		if ($dimension !== null) {
			$query->where('dimension', $dimension);
		}

		return $query->asObj(Player::class);
	}

	private function parsePlayerFromBody(string $body): ?Player {
		if ($body === 'null') {
			return null;
		}
		try {
			[$char, $org, $lastUpdated] = json_decode($body);
		} catch (JsonException) {
			return null;
		}

		$luDateTime = DateTimeImmutable::createFromFormat('Y/m/d H:i:s', $lastUpdated, new DateTimeZone('UTC'));
		$obj = new Player(
			firstname: trim($char->FIRSTNAME),
			name: $char->NAME,
			lastname: trim($char->LASTNAME),
			level: $char->LEVELX,
			breed: $char->BREED ?? '',
			gender: $char->SEX ?? '',
			faction: Faction::tryFrom($char->SIDE) ?? Faction::Unknown,
			profession: Profession::tryFrom($char->PROF),
			prof_title: $char->PROFNAME ?? '',
			ai_rank: $char->RANK_name ?? '',
			ai_level: $char->ALIENLEVEL,
			guild_id: $org->ORG_INSTANCE,
			guild: $org->NAME ?? '',
			guild_rank: $org->RANK_TITLE ?? '',
			guild_rank_id: $org->RANK,
			head_id: $char->HEADID,
			pvp_rating: $char->PVPRATING,
			pvp_title: $char->PVPTITLE,
			charid: $char->CHAR_INSTANCE,
			dimension: $char->CHAR_DIMENSION,
			last_update: $luDateTime->getTimestamp(),
		);

		return $obj;
	}
}
