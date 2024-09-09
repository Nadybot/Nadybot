<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use InvalidArgumentException;
use Nadybot\Core\{
	Attributes as NCA,
	DB,
	ExportCharacter,
	ModuleInstance,
	Types\ExporterInterface,
	Types\ImporterInterface
};
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\Exporter('raidPoints'),
	NCA\Importer('raidPoints', ExportRaidPointEntry::class),
]
class RaidPointsExporter extends ModuleInstance implements ExporterInterface, ImporterInterface {
	/** @return list<ExportRaidPointEntry> */
	public function export(DB $db, LoggerInterface $logger): array {
		return $db->table(RaidPoints::getTable())
			->orderBy('username')
			->asObj(RaidPoints::class)
			->map(static function (RaidPoints $datum): ExportRaidPointEntry {
				return new ExportRaidPointEntry(
					character: new ExportCharacter(name: $datum->username),
					raidPoints: (float)$datum->points,
				);
			})->toList();
	}

	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$logger->notice('Importing {num_points} raid points', [
			'num_points' => count($data),
		]);
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all raid points');
			$db->table(RaidPoints::getTable())->truncate();
			foreach ($data as $point) {
				if (!($point instanceof ExportRaidPointEntry)) {
					throw new InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . '() called with wrong data');
				}
				$name = $point->character->tryGetName();
				if (!isset($name)) {
					continue;
				}
				$db->insert(new RaidPoints(
					username: $name,
					points: (int)floor($point->raidPoints),
				));
			}
		} catch (Throwable $e) {
			$logger->error('{error}. Rolling back changes.', [
				'error' => rtrim($e->getMessage(), '.'),
				'exception' => $e,
			]);
			$db->rollback();
			return;
		}
		$db->commit();
		$logger->notice('All raid points imported');
	}
}
