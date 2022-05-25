<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use Amp\Promise;
use Generator;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	DB,
	DBSchema\Player,
	LoggerWrapper,
	Nadybot,
	QueryBuilder,
};
use Throwable;

use function Amp\call;
use function Amp\delay;

class PlayerLookupJob {
	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public PlayerManager $playerManager;

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
		call(function () use ($numJobs, $callback, $args): Generator {
			$threads = [];
			for ($i = 0; $i < $numJobs; $i++) {
				$this->numActiveThreads++;
				$this->logger->info('Spawning lookup thread #' . $this->numActiveThreads);
				$threads []= $this->startThread($i+1);
			}
			yield Promise\all($threads);
			$this->logger->info("All threads done, stopping lookup.");
			$callback(...$args);
		});
	}

	/** @return Promise<true> */
	private function startThread(int $threadNum): Promise {
		return call(function () use ($threadNum): Generator {
			while ($todo = $this->toUpdate->shift()) {
				/** @var Player $todo */
				$this->logger->debug("[Thread #{$threadNum}] Looking up " . $todo->name);
				try {
					$uid = yield $this->chatBot->getUid2($todo->name);
					if (!isset($uid)) {
						$this->logger->debug("[Thread #{$threadNum}] Player " . $todo->name . ' is inactive, not updating.');
						continue;
					}
					$start = microtime(true);
					$player = yield $this->playerManager->byName($todo->name, $todo->dimension, true);
					$duration = round((microtime(true) - $start) * 1000, 1);
					$this->logger->debug(
						"[Thread #{$threadNum}] PORK lookup for " . $todo->name . ' done, '.
						(isset($player) ? 'data updated' : 'no data found').
						" - took {$duration}ms"
					);
					yield delay(500);
				} catch (Throwable $e) {
					$this->logger->error("[Thread #{$threadNum}] Exception looking up {name}: {error}", [
						"name" => $todo->name,
						"error" => $e->getMessage(),
						"Exception" => $e,
					]);
				}
			}
			$this->logger->debug("[Thread #{$threadNum}] Queue empty, stopping thread.");
			return true;
		});
	}
}
