<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use Illuminate\Support\Collection;
use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Nadybot;
use Nadybot\Core\QueryBuilder;
use Nadybot\Core\SettingManager;
use Nadybot\Core\Timer;

class PlayerLookupJob {
	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Timer $timer;

	/** @var Collection<Player> */
	public Collection $toUpdate;

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
			->asObj()
			->pluck("alt")
			->map(function (string $alt): Player {
				$result = new Player();
				$result->name = $alt;
				$result->dimension = $this->db->getDim();
				return $result;
			});
	}

	/** Start the lookup job and call the callback when done */
	public function run(callable $callback, ...$args): void {
		$numJobs = $this->settingManager->getInt('lookup_jobs');
		if ($numJobs === 0) {
			$callback(...$args);
			return;
		}
		$this->toUpdate = $this->getOudatedCharacters()
			->concat($this->getMissingAlts());
		for ($i = 0; $i < $numJobs; $i++) {
			$this->startThread($callback, ...$args);
		}
	}

	public function startThread(callable $callback, ...$args): void {
		if ($this->toUpdate->isEmpty()) {
			$callback(...$args);
			return;
		}
		/** @var Player */
		$todo = $this->toUpdate->shift();
		$this->chatBot->getUid(
			$todo->name,
			[$this, "asyncPlayerLookup"],
			$todo,
			$callback,
			...$args
		);
	}

	public function asyncPlayerLookup(?int $uid, Player $todo, callable $callback, ...$args): void {
		if ($uid === null) {
			$this->timer->callLater(0, [$this, "startThread"], $callback, ...$args);
			return;
		}
		$this->playerManager->getByNameAsync(
			function(?Player $player) use ($callback, $args): void {
				$this->timer->callLater(1, [$this, "startThread"], $callback, ...$args);
			},
			$todo->name,
			$todo->dimension
		);
	}
}
