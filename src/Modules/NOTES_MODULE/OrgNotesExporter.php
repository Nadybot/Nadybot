<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use InvalidArgumentException;
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
	NCA\Exporter('orgNotes'),
	NCA\Importer('orgNotes', ExportOrgNote::class),
]
class OrgNotesExporter extends ModuleInstance implements ExporterInterface, ImporterInterface {
	/** @return list<ExportOrgNote> */
	public function export(DB $db, LoggerInterface $logger): array {
		return $db->table(OrgNote::getTable())
			->asObj(OrgNote::class)
			->map(static function (OrgNote $note): ExportOrgNote {
				return new ExportOrgNote(
					author: new ExportCharacter(name: $note->added_by),
					creationTime: $note->added_on,
					text: $note->note,
					uuid: $note->id->toString(),
				);
			})->toList();
	}

	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$logger->notice('Importing {num_notes} org notes', [
			'num_notes' => count($data),
		]);
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all org notes');
			$db->table(OrgNote::getTable())->truncate();
			foreach ($data as $note) {
				if (!($note instanceof ExportOrgNote)) {
					throw new InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . '() called with wrong data');
				}
				$owner = $note->author?->tryGetName();
				if (!isset($owner)) {
					continue;
				}
				$db->insert(new OrgNote(
					added_by: $owner,
					note: $note->text,
					added_on: $note->creationTime ?? null,
					id: isset($note->uuid) ? Uuid::fromString($note->uuid) : null,
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
		$logger->notice('All org notes imported');
	}
}
