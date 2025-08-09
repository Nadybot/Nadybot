<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use function Amp\delay;

use Amp\Pipeline\Pipeline;
use Nadybot\Core\DBSchema\Alt;
use Nadybot\Core\{
	Attributes as NCA,
	Collection,
	DB,
	DBSchema\Player,
	Nadybot,
	QueryBuilder,
};
use Psr\Log\LoggerInterface;
use Throwable;

class PlayerLookupJob {
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
	 * @return Collection<int,Player>
	 */
	public function getOudatedCharacters(): Collection {
		return $this->db->table(Player::getTable())
			->where('last_update', '<', time() - PlayerManager::CACHE_GRACE_TIME)
			->asObj(Player::class);
	}

	/**
	 * Get a list of character names who are alts without info
	 *
	 * @return Collection<int,MissingAlt>
	 */
	public function getMissingAlts(): Collection {
		$result = $this->db->table(Alt::getTable())
			->whereNotExists(static function (QueryBuilder $query): void {
				$query->from('players')
					->whereColumn('alts.alt', 'players.name');
			})->select('alt')
			->pluckStrings('alt')
			->map(function (string $alt): MissingAlt {
				$result = new MissingAlt(
					name: $alt,
					dimension: $this->db->getDim(),
				);
				return $result;
			});
		return $result;
	}

	/** Start the lookup job */
	public function run(): void {
		$numJobs = $this->playerManager->lookupJobs;
		if ($numJobs === 0) {
			return;
		}

		/** @var Collection<array-key,MissingAlt|Player> */
		$toUpdate = $this->getMissingAlts()
			->concat($this->getOudatedCharacters());
		if ($toUpdate->isEmpty()) {
			$this->logger->info('No outdate player information found.');
			return;
		}
		$this->logger->info('{num_outdated}  missing / outdated characters found.', [
			'num_outdated' => $toUpdate->count(),
		]);

		/** @psalm-suppress MixedArgumentTypeCoercion */
		Pipeline::fromIterable($toUpdate)
			->concurrent($numJobs)
			->forEach($this->lookupInfo(...));
	}

	private function lookupInfo(MissingAlt|Player $todo): void {
		$this->logger->info('Looking up {character}', [
			'character' => $todo->name,
		]);
		try {
			$uid = $this->chatBot->getUid($todo->name);
			if (!isset($uid)) {
				$this->logger->debug('Character {character} is inactive, not updating.', [
					'character' => $todo->name,
				]);
				return;
			}
			$start = microtime(true);
			$player = $this->playerManager->byName($todo->name, $todo->dimension, true);
			$duration = round((microtime(true) - $start) * 1_000, 1);
			$this->logger->debug(
				'PORK lookup for {character} done after {duration}s: {result}',
				[
					'character' => $todo->name,
					'result' => isset($player) ? 'data updated' : 'no data found',
					'duration' => $duration,
				]
			);
			delay(0.5);
		} catch (Throwable $e) {
			$this->logger->error('Exception looking up {character}: {error}', [
				'character' => $todo->name,
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}
}
