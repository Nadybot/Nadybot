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
	ModuleInstance,
	SettingManager
};
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\Exporter('raids'),
	NCA\Importer('raids', ExportRaid::class),
]
class RaidExporter extends ModuleInstance implements ExporterInterface, ImporterInterface {
	#[Inject]
	private BotConfig $config;

	#[Inject]
	private SettingManager $settingManager;

	/** @return list<ExportRaid> */
	public function export(DB $db, LoggerInterface $logger): array {
		$data = $db->table(RaidLog::getTable())
			->orderBy('raid_id')
			->asObjArr(RaidLog::class);

		/** @var array<string,ExportRaid> */
		$raids = [];
		foreach ($data as $raid) {
			$raids[$raid->raid_id->toString()] ??= new ExportRaid(
				raidId: $raid->raid_id->toString(),
				time: $raid->time,
				raidDescription: $raid->description,
				raidLocked: $raid->locked,
				raidAnnounceInterval: $raid->announce_interval,
				raiders: [],
				history: [],
			);
			if ($raid->seconds_per_point > 0) {
				$raids[$raid->raid_id->toString()]->raidSecondsPerPoint = $raid->seconds_per_point;
			}
			$raids[$raid->raid_id->toString()]->history []= new ExportRaidState(
				time: $raid->time,
				raidDescription: $raid->description,
				raidLocked: $raid->locked,
				raidAnnounceInterval: $raid->announce_interval,
				raidSecondsPerPoint: ($raid->seconds_per_point === 0) ? null : $raid->seconds_per_point,
			);
		}

		$data = $db->table(RaidMember::getTable())
			->asObjArr(RaidMember::class);
		foreach ($data as $raidMember) {
			$raider = new ExportRaider(
				character: new ExportCharacter(name: $raidMember->player),
				joinTime: $raidMember->joined,
			);
			if (isset($raidMember->left)) {
				$raider->leaveTime = $raidMember->left;
			}
			$raids[$raidMember->raid_id->toString()]->raiders []= $raider;
		}
		return array_values($raids);
	}

	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$logger->notice('Importing {num_raids} raids', [
			'num_raids' => count($data),
		]);
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all raids');
			$db->table(Raid::getTable())->truncate();
			$db->table(RaidLog::getTable())->truncate();
			$db->table(RaidMember::getTable())->truncate();
			foreach ($data as $raid) {
				if (!($raid instanceof ExportRaid)) {
					throw new InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . '() called with wrong data');
				}
				$this->importRaid($db, $raid);
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
		$logger->notice('All raids imported');
	}

	/** Import a single raid into the database */
	private function importRaid(DB $db, ExportRaid $raid): void {
		$history = $raid->history ?? [];
		usort(
			$history,
			static function (ExportRaidState $o1, ExportRaidState $o2): int {
				return $o1->time <=> $o2->time; // @phpstan-ignore-line
			}
		);
		$lastEntry = null;
		if (count($history) > 0) {
			$lastEntry = $history[count($history)-1] ?? null;
		}
		$entry = new Raid(
			started: $raid->time ?? time(),
			started_by: $this->config->main->character,
			stopped: isset($lastEntry) ? $lastEntry->time : $raid->time ?? time(),
			stopped_by: $this->config->main->character,
			description: $raid->raidDescription ?? 'No description',
			seconds_per_point: $raid->raidSecondsPerPoint ?? 0,
			announce_interval: $raid->raidAnnounceInterval ?? $this->settingManager->getInt('raid_announcement_interval') ?? 0,
			locked: $raid->raidLocked ?? false,
		);
		$db->insert($entry);
		$historyEntry = new RaidLog(
			description: $entry->description,
			seconds_per_point: $entry->seconds_per_point,
			announce_interval: $entry->announce_interval,
			locked: $entry->locked,
			raid_id: $entry->raid_id,
			time: time(),
		);
		foreach ($raid->raiders??[] as $raider) {
			$name = $raider->character->tryGetName();
			if (!isset($name)) {
				continue;
			}
			$raiderEntry = new RaidMember(
				raid_id: $entry->raid_id,
				player: $name,
				joined: $raider->joinTime ?? time(),
				left: $raider->leaveTime ?? time(),
			);
			$db->insert($raiderEntry);
		}
		foreach ($history as $state) {
			$historyEntry->time = $state->time ?? time();
			if (isset($state->raidDescription)) {
				$historyEntry->description = $state->raidDescription;
			}
			if (isset($state->raidLocked)) {
				$historyEntry->locked = $state->raidLocked;
			}
			if (isset($state->raidAnnounceInterval)) {
				$historyEntry->announce_interval = $state->raidAnnounceInterval;
			}
			if (isset($state->raidSecondsPerPoint)) {
				$historyEntry->seconds_per_point = $state->raidSecondsPerPoint;
			}
			$db->insert($historyEntry);
		}
		if (!count($history)) {
			$db->insert($historyEntry);
		}
	}
}
