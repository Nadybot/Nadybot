<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

use InvalidArgumentException;
use Nadybot\Core\Attributes\Inject;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\{
	Attributes as NCA,
	DB,
	ExportCharacter,
	ModuleInstance,
	Types\ExporterInterface,
	Types\ImporterInterface,
};
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Safe\DateTimeImmutable;
use Throwable;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\Exporter('trackedCharacters'),
	NCA\Importer('trackedCharacters', ExportTrackedCharacter::class),
]
class TrackedCharactersExporter extends ModuleInstance implements ExporterInterface, ImporterInterface {
	#[Inject]
	private BotConfig $config;

	/** @return list<ExportTrackedCharacter> */
	public function export(DB $db, LoggerInterface $logger): array {
		$users = $db->table(TrackedUser::getTable())
			->orderBy('added_dt')
			->asObjArr(TrackedUser::class);
		$result = [];
		foreach ($users as $user) {
			$result[$user->uid] = new ExportTrackedCharacter(
				character: new ExportCharacter(name: $user->name, id: $user->uid),
				addedTime: $user->added_dt,
				addedBy: new ExportCharacter(name: $user->added_by),
				events: [],
			);
		}

		$events = $db->table(Tracking::getTable())
			->orderBy('dt')
			->asObj(Tracking::class);
		foreach ($events as $event) {
			if (!isset($result[$event->uid])) {
				continue;
			}
			$result[$event->uid]->events []= new ExportTrackerEvent(
				time: $event->dt,
				event: $event->event,
			);
		}
		return array_values($result);
	}

	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$logger->notice('Importing {num_tracked_users} tracked users', [
			'num_tracked_users' => count($data),
		]);
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all tracked users');
			$db->table(TrackedUser::getTable())->truncate();
			$db->table(Tracking::getTable())->truncate();
			foreach ($data as $trackedUser) {
				if (!($trackedUser instanceof ExportTrackedCharacter)) {
					throw new InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . '() called with wrong data');
				}
				$name = $trackedUser->character->tryGetName();
				if (!isset($name)) {
					continue;
				}
				$id = $trackedUser->character->tryGetID();
				if (!isset($id)) {
					continue;
				}
				$db->insert(new TrackedUser(
					uid: $id,
					name: $name,
					added_by: $trackedUser->addedBy?->tryGetName() ?? $this->config->main->character,
					added_dt: $trackedUser->addedTime ?? time(),
				));
				foreach ($trackedUser->events??[] as $event) {
					$db->insert(new Tracking(
						id: Uuid::uuid7((new DateTimeImmutable())->setTimestamp($event->time)),
						uid: $id,
						dt: $event->time,
						event: $event->event,
					));
				}
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
		$logger->notice('All raid blocks imported');
	}
}
