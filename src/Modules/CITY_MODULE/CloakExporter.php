<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

use InvalidArgumentException;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
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
	NCA\Exporter('cityCloak'),
	NCA\Importer('cityCloak', ExportCloak::class),
]
class CloakExporter extends ModuleInstance implements ExporterInterface, ImporterInterface {
	#[NCA\Inject]
	private BotConfig $config;

	/** @return list<ExportCloak> */
	public function export(DB $db, LoggerInterface $logger): array {
		return $db->table(OrgCity::getTable())
			->asObj(OrgCity::class)
			->map(static function (OrgCity $cloakEntry): ExportCloak {
				return new ExportCloak(
					character: new ExportCharacter(name: rtrim($cloakEntry->player, '*')),
					manualEntry: str_ends_with($cloakEntry->player, '*'),
					cloakOn: $cloakEntry->action === 'on',
					time: $cloakEntry->time,
				);
			})->toList();
	}

	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$logger->notice('Importing {num_actions} cloak action(s)', [
			'num_actions' => count($data),
		]);
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all cloak actions');
			$db->table(OrgCity::getTable())->truncate();
			foreach ($data as $action) {
				if (!($action instanceof ExportCloak)) {
					throw new InvalidArgumentException('CloakExporter::import() called with wrong data');
				}
				$db->insert(new OrgCity(
					time: $action->time,
					action: $action->cloakOn ? 'on' : 'off',
					player: $action->character?->tryGetName() ?? $this->config->main->character,
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
		$logger->notice('All cloak actions imported');
	}
}
