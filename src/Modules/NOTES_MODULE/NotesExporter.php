<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

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
	NCA\Exporter('notes'),
	NCA\Importer('notes', ExportNote::class),
]
class NotesExporter extends ModuleInstance implements ExporterInterface, ImporterInterface {
	/** @return list<ExportNote> */
	public function export(DB $db, LoggerInterface $logger): array {
		return $db->table(Note::getTable())
			->asObj(Note::class)
			->map(static function (Note $note): ExportNote {
				$data = new ExportNote(
					owner: new ExportCharacter(name: $note->owner),
					author: new ExportCharacter(name: $note->added_by),
					creationTime: $note->dt,
					text: $note->note,
				);
				if ($note->reminder === Note::REMIND_ALL) {
					$data->remind = 'all';
				} elseif ($note->reminder === Note::REMIND_SELF) {
					$data->remind = 'author';
				}
				return $data;
			})->toList();
	}

	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$logger->notice('Importing {num_notes} notes', [
			'num_notes' => count($data),
		]);
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all notes');
			$db->table(Note::getTable())->truncate();
			foreach ($data as $note) {
				if (!($note instanceof ExportNote)) {
					throw new InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . '() called with wrong data');
				}
				$owner = $note->owner->tryGetName();
				if (!isset($owner)) {
					continue;
				}
				$reminder = $note->remind ?? null;
				$reminderInt = ($reminder === 'all')
					? Note::REMIND_ALL
					: (($reminder === 'author')
						? Note::REMIND_SELF
						: Note::REMIND_NONE);
				$db->insert(new Note(
					owner: $owner,
					added_by: $note->author?->tryGetName() ?? $owner,
					note: $note->text,
					dt: $note->creationTime ?? null,
					reminder: $reminderInt,
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
		$logger->notice('All notes imported');
	}
}
