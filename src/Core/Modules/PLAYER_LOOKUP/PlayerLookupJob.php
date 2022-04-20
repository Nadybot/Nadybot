<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	DB,
	DBSchema\Player,
	LoggerWrapper,
	Nadybot,
	QueryBuilder,
	Timer,
};

class PlayerLookupJob {
	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public Timer $timer;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** @var Collection<Player> */
	public Collection $toUpdate;

	protected int $numActiveThreads = 0;

	/**
	 * Get a list of character names in need of updates
	 * @return Collection<Player>
	 */
	public function getOudatedCharacters(): Collection {
		return $this->db->table("players")
			->where("last_update", "<", time() - PlayerManager::CACHE_GRACE_TIME)
			->asObj(Player::class);
	}

	/**
	 * Get a list of character names who are alts without info
	 * @return Collection<Player>
	 */
	public function getMissingAlts(): Collection {
		return $this->db->table("alts")
			->whereNotExists(function (QueryBuilder $query): void {
				$query->from("players")
					->whereColumn("alts.alt", "players.name");
			})->select("alt")
			->pluckAs("alt", "string")
			->map(function (string $alt): Player {
				$result = new Player();
				$result->name = $alt;
				$result->dimension = $this->db->getDim();
				return $result;
			});
	}

	/**
	 * Start the lookup job and call the callback when done
	 * @psalm-param callable(mixed...) $callback
	 */
	public function run(callable $callback, mixed ...$args): void {
		$numJobs = $this->playerManager->lookupJobs;
		if ($numJobs === 0) {
			$callback(...$args);
			return;
		}
		$this->toUpdate = $this->getMissingAlts()
			->concat($this->getOudatedCharacters());
		if ($this->toUpdate->isEmpty()) {
			$this->logger->info("No outdate player information found.");
			$callback(...$args);
			return;
		}
		$this->logger->info($this->toUpdate->count() . " missing / outdated characters found.");
		for ($i = 0; $i < $numJobs; $i++) {
			$this->numActiveThreads++;
			$this->logger->info('Spawning lookup thread #' . $this->numActiveThreads);
			$this->startThread($i+1, $callback, ...$args);
		}
	}

	/**
	 * @psalm-param callable(mixed...) $callback
	 */
	public function startThread(int $threadNum, callable $callback, mixed ...$args): void {
		if ($this->toUpdate->isEmpty()) {
			$this->logger->debug("[Thread #{$threadNum}] Queue empty, stopping thread.");
			$this->numActiveThreads--;
			if ($this->numActiveThreads === 0) {
				$this->logger->info("[Thread #{$threadNum}] All threads stopped, calling callback.");
				$callback(...$args);
			}
			return;
		}
		/** @var Player */
		$todo = $this->toUpdate->shift();
		$this->logger->debug("[Thread #{$threadNum}] Looking up " . $todo->name);
		$this->chatBot->getUid(
			$todo->name,
			[$this, "asyncPlayerLookup"],
			$threadNum,
			$todo,
			$callback,
			...$args
		);
	}

	/**
	 * @psalm-param callable(mixed...) $callback
	 */
	public function asyncPlayerLookup(?int $uid, int $threadNum, Player $todo, callable $callback, mixed ...$args): void {
		if ($uid === null) {
			$this->logger->debug("[Thread #{$threadNum}] Player " . $todo->name . ' is inactive, not updating.');
			$this->timer->callLater(0, [$this, "startThread"], $threadNum, $callback, ...$args);
			return;
		}
		$this->logger->debug("[Thread #{$threadNum}] Player " . $todo->name . ' is active, querying PORK.');
		$this->playerManager->getByNameAsync(
			function(?Player $player) use ($callback, $args, $todo, $threadNum): void {
				$this->logger->debug(
					"[Thread #{$threadNum}] PORK lookup for " . $todo->name . ' done, '.
					(isset($player) ? 'data updated' : 'no data found')
				);
				$this->timer->callLater(0, [$this, "startThread"], $threadNum, $callback, ...$args);
			},
			$todo->name,
			$todo->dimension
		);
	}
}
