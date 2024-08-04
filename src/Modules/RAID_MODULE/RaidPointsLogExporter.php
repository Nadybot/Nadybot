<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use InvalidArgumentException;
use Nadybot\Core\Attributes\Inject;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\{
	Attributes as NCA,
	DB,
	ExportCharacter,
	ExporterInterface,
	ImporterInterface,
	ModuleInstance
};
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\Exporter('raidPointsLog'),
	NCA\Importer('raidPointsLog', ExportRaidPointLog::class),
]
class RaidPointsLogExporter extends ModuleInstance implements ExporterInterface, ImporterInterface {
	#[Inject]
	private BotConfig $config;

	/** @return list<ExportRaidPointLog> */
	public function export(DB $db, LoggerInterface $logger): array {
		return $db->table(RaidPointsLog::getTable())
			->orderBy('time')
			->orderBy('username')
			->asObj(RaidPointsLog::class)
			->map(static function (RaidPointsLog $datum): ExportRaidPointLog {
				$raidLog = new ExportRaidPointLog(
					character: new ExportCharacter(name: $datum->username),
					raidPoints: (float)$datum->delta,
					time: $datum->time,
					givenBy: new ExportCharacter(name: $datum->changed_by),
					reason: $datum->reason,
					givenByTick: $datum->ticker,
					givenIndividually: $datum->individual,
				);
				if (isset($datum->raid_id)) {
					$raidLog->raidId = $datum->raid_id->toString();
				}
				return $raidLog;
			})->toList();
	}

	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$logger->notice('Importing {num_point_logs} raid point logs', [
			'num_point_logs' => count($data),
		]);
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all raid point logs');
			$db->table(RaidPointsLog::getTable())->truncate();
			foreach ($data as $point) {
				if (!($point instanceof ExportRaidPointLog)) {
					throw new InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . '() called with wrong data');
				}
				$name = $point->character->tryGetName();
				if (!isset($name) || (int)floor($point->raidPoints) === 0) {
					continue;
				}
				$db->insert(new RaidPointsLog(
					username: $name,
					delta: (int)floor($point->raidPoints),
					time: $point->time ?? time(),
					changed_by: $point->givenBy?->tryGetName() ?? $this->config->main->character,
					individual: $point->givenIndividually ?? true,
					raid_id: isset($point->raidId) ? Uuid::fromString($point->raidId) : null,
					reason: $point->reason ?? 'Raid participation',
					ticker: $point->givenByTick ?? false,
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
		$logger->notice('All raid point logs imported');
	}
}
