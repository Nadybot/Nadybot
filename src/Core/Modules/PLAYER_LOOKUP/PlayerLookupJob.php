<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use function Amp\Future\await;
use function Amp\{async, delay};

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	DB,
	DBSchema\Player,
	Nadybot,
	QueryBuilder,
};
use Psr\Log\LoggerInterface;
use Throwable;

class PlayerLookupJob {
	/** @var Collection<Player> */
	public Collection $toUpdate;

	protected int $numActiveThreads = 0;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	/**
	 * Get a list of character names in need of updates
	 *
	 * @return Collection<Player>
	 */
	public function getOudatedCharacters(): Collection {
		return $this->db->table("players")
			->where("last_update", "<", time() - PlayerManager::CACHE_GRACE_TIME)
			->asObj(Player::class);
	}

	/**
	 * Get a list of character names who are alts without info
	 *
	 * @return Collection<Player>
	 */
	public function getMissingAlts(): Collection {
		/** @var Collection<Player> */
		$result = $this->db->table("alts")
			->whereNotExists(function (QueryBuilder $query): void {
				$query->from("players")
					->whereColumn("alts.alt", "players.name");
			})->select("alt")
			->pluckStrings("alt")
			->map(function (string $alt): Player {
				$result = new Player();
				$result->name = $alt;
				$result->dimension = $this->db->getDim();
				return $result;
			});
		return $result;
	}

	/**
	 * Start the lookup job and call the callback when done
	 *
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
		async(function () use ($numJobs, $callback, $args): void {
			$threads = [];
			for ($i = 0; $i < $numJobs; $i++) {
				$this->numActiveThreads++;
				$this->logger->info('Spawning lookup thread #' . $this->numActiveThreads);
				$threads []= async($this->startThread(...), $i+1);
			}
			await($threads);
			$this->logger->info("All threads done, stopping lookup.");
			$callback(...$args);
		});
	}

	private function startThread(int $threadNum): void {
		while ($todo = $this->toUpdate->shift()) {
			/** @var Player $todo */
			$this->logger->debug("[Thread #{thread_num}] Looking up {character}", [
				"thread_num" => $threadNum,
				"character" => $todo->name,
			]);
			try {
				$uid = $this->chatBot->getUid($todo->name);
				if (!isset($uid)) {
					$this->logger->debug("[Thread #{thread_num}] Character {character} is inactive, not updating.", [
						"thread_num" => $threadNum,
						"character" => $todo->name,
					]);
					continue;
				}
				$start = microtime(true);
				$player = $this->playerManager->byName($todo->name, $todo->dimension, true);
				$duration = round((microtime(true) - $start) * 1000, 1);
				$this->logger->debug(
					"[Thread #{thread_num}] PORK lookup for {character} done after {duration}s: {result}",
					[
						"thread_num" => $threadNum,
						"character" => $todo->name,
						"result" => isset($player) ? 'data updated' : 'no data found',
						"duration" => $duration,
					]
				);
				delay(0.5);
			} catch (Throwable $e) {
				$this->logger->error("[Thread #{thread_num}] Exception looking up {character}: {error}", [
					"thread_num" => $threadNum,
					"character" => $todo->name,
					"error" => $e->getMessage(),
					"Exception" => $e,
				]);
			}
		}
		$this->logger->debug("[Thread #{thread_num}] Queue empty, stopping thread.", [
			"thread_num" => $threadNum,
		]);
	}
}
