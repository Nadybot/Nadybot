<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

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
	NCA\Exporter('raffleBonus'),
	NCA\Importer('raffleBonus', ExportRaffleBonus::class),
]
class RaffleBonusExporter extends ModuleInstance implements ExporterInterface, ImporterInterface {
	/** @return list<ExportRaffleBonus> */
	public function export(DB $db, LoggerInterface $logger): array {
		return $db->table(RaffleBonus::getTable())
			->orderBy('name')
			->asObj(RaffleBonus::class)
			->map(static function (RaffleBonus $bonus): ExportRaffleBonus {
				return new ExportRaffleBonus(
					character: new ExportCharacter(name: $bonus->name),
					raffleBonus: $bonus->bonus,
				);
			})->toList();
	}

	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$logger->notice('Importing {num_bonuses} raffle bonuses', [
			'num_bonuses' => count($data),
		]);
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all raffle bonuses');
			$db->table(RaffleBonus::getTable())->truncate();
			foreach ($data as $bonus) {
				if (!($bonus instanceof ExportRaffleBonus)) {
					throw new InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . '() called with wrong data');
				}
				$name = $bonus->character->tryGetName();
				if (!isset($name)) {
					continue;
				}
				$db->insert(new RaffleBonus(
					name: $name,
					bonus: (int)floor($bonus->raffleBonus),
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
		$logger->notice('All raffle bonuses imported');
	}
}
