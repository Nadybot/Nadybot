<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use InvalidArgumentException;
use Nadybot\Core\DBSchema\Alt;
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
	NCA\Exporter('alts'),
	NCA\Importer(key: 'alts', class: AltMain::class),
]
class AltsExporter extends ModuleInstance implements ExporterInterface, ImporterInterface {
	/** @return list<AltMain> */
	public function export(DB $db, LoggerInterface $logger): array {
		$alts = $db->table(Alt::getTable())->asObj(Alt::class);

		/** @var array<string,list<AltChar>> */
		$data = [];
		foreach ($alts as $alt) {
			if ($alt->main === $alt->alt) {
				continue;
			}
			$data[$alt->main] ??= [];
			$data[$alt->main] []= new AltChar(
				alt: new ExportCharacter(name: $alt->alt),
				validatedByMain: $alt->validated_by_main ?? true,
				validatedByAlt: $alt->validated_by_alt ?? true,
			);
		}

		/** @var list<AltMain> */
		$result = [];
		foreach ($data as $main => $altInfo) {
			$result []= new AltMain(
				main: new ExportCharacter(name: $main),
				alts: $altInfo,
			);
		}

		return $result;
	}

	/** @param list<object> $data A list of all mains and their alts */
	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$logger->notice('Importing alts for {num_alts} character(s)', [
			'num_alts' => count($data),
		]);
		$numImported = 0;
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all alts');
			$db->table(Alt::getTable())->truncate();
			foreach ($data as $altData) {
				if (!($altData instanceof AltMain)) {
					throw new InvalidArgumentException('AltsExporter::import() received wrong data to import');
				}
				$mainName = $altData->main->tryGetName();
				if (!isset($mainName)) {
					continue;
				}
				foreach ($altData->alts as $alt) {
					$numImported += $this->importAlt($db, $mainName, $alt);
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
		$logger->notice('{num_imported} alt(s) imported', [
			'num_imported' => $numImported,
		]);
	}

	private function importAlt(DB $db, string $mainName, AltChar $alt): int {
		$altName = $alt->alt->tryGetName();
		if (!isset($altName)) {
			return 0;
		}
		$db->insert(new Alt(
			alt: $altName,
			main: $mainName,
			validated_by_main: $alt->validatedByMain ?? true,
			validated_by_alt: $alt->validatedByAlt ?? true,
			added_via: $db->getMyname(),
		));
		return 1;
	}
}
