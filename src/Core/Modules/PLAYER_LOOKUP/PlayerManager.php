<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use AO\Utils;
use Nadybot\Core\{
	Attributes as NCA,
	Collection,
	Config\BotConfig,
	DB,
	DBSchema\Player,
	Exceptions\SQLException,
	ModuleInstance,
	Nadybot,
	Registry,
	Safe,
	Types\Dimension,
	Types\Status,
};
use Nadybot\Core\Attributes\ExposeToAI;
use Nadybot\Core\Modules\PLAYER_LOOKUP\Directory\{DirectoryFeature, DirectoryRegistry, DirectoryUnavailableException};
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

/**
 * @author Tyrence (RK2)
 */
#[NCA\Instance]
class PlayerManager extends ModuleInstance {
	/** @var int */
	public const CACHE_GRACE_TIME = 87_000;

	/** How many jobs in parallel to run to lookup missing character data */
	#[NCA\Setting\Options(options: ['Off' => 0, 1, 2, 3, 4, 5, 10])]
	public int $lookupJobs = 0;

	/** Which service to use for character look-ups on RK4-6 */
	#[NCA\Setting\Text(
		options: [
			'bork.aobots.org (Nadybot)' => DirectoryRegistry::BORK_URL,
			'people.anarchy-online.com (Funcom)' => DirectoryRegistry::PORK_URL,
		]
	)]
	public string $porkUrl = DirectoryRegistry::BORK_URL;

	public ?PlayerLookupJob $playerLookupJob = null;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private DirectoryRegistry $directoryRegistry;

	/** Offer all known providers as choices for the preferred services */
	#[NCA\Setup]
	public function setup(): void {
		$this->directoryRegistry->updateSettingOptions(
			'pork_url',
			DirectoryFeature::Character,
			Dimension::FUNCOM_DIMENSIONS,
		);
	}

	/** Periodically lookup missing or outdated player data */
	#[NCA\Timer(interval: '1h', defaultStatus: Status::Enabled)]
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

	/**
	 * Get information about a character in the game
	 *
	 * @param string   $name        Name of the character to look up
	 * @param null|int $dimension   The server (dimension) to look the character up on.
	 *                              Defaults to the current one
	 * @param bool     $forceUpdate Force loading data from the Anarchy Online server and ignore cached data
	 *
	 * @return null|Player null if the character doesn't exist, otherwise a player object
	 */
	#[ExposeToAI(name: 'whois')]
	public function byName(string $name, ?int $dimension=null, bool $forceUpdate=false): ?Player {
		$dimension ??= $this->config->main->dimension;

		$name = Utils::normalizeCharacter($name);

		if (!Safe::pregMatches('/^[A-Z][a-z0-9-]{3,11}$/', $name)) {
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

	/**
	 * Look up a character from the providers for $dimension, without using the database
	 *
	 * Providers are asked in order of preference, and the next one is only
	 * asked if the previous one could not answer.
	 */
	public function lookup(string $name, int $dimension): ?Player {
		$player = null;
		$providers = $this->directoryRegistry->characterProviders($dimension, $this->porkUrl);
		foreach ($providers as $provider) {
			try {
				$player = $provider->lookupCharacter($name, $dimension);
				break;
			} catch (DirectoryUnavailableException $e) {
				$this->logger->info('{provider} could not look up {name}.{dimension}: {error}', [
					'provider' => $provider->getName(),
					'name' => $name,
					'dimension' => $dimension,
					'error' => $e->getMessage(),
					'exception' => $e,
				]);
			}
		}
		if (isset($player) && $player->name === $name) {
			$player->dimension = $dimension;
			return $player;
		}
		$this->logger->info('No char information found about {character} on RK{dimension}', [
			'character' => $name,
			'dimension' => $dimension,
		]);
		return null;
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
	 * @param null|int $dimension Dimension to limit search to
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
}
