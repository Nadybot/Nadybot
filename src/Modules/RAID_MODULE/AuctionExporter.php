<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

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
	NCA\Exporter('auctions'),
	NCA\Importer('auctions', ExportAuction::class),
]
class AuctionExporter extends ModuleInstance implements ExporterInterface, ImporterInterface {
	#[NCA\Inject]
	private BotConfig $config;

	/** @return list<ExportAuction> */
	public function export(DB $db, LoggerInterface $logger): array {
		$auctions = $db->table(DBAuction::getTable())
			->orderBy('id')
			->asObj(DBAuction::class);

		/** @var list<ExportAuction> $result */
		$result = [];
		foreach ($auctions as $auction) {
			$auctionObj = new ExportAuction(
				item: $auction->item,
				startedBy: new ExportCharacter(name: $auction->auctioneer),
				timeEnd: $auction->end,
				reimbursed: $auction->reimbursed,
			);
			if (isset($auction->winner)) {
				$auctionObj->winner = new ExportCharacter(name: $auction->winner);
			}
			if (isset($auction->cost)) {
				$auctionObj->cost = (float)$auction->cost;
			}
			$result []= $auctionObj;
		}

		return $result;
	}

	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$logger->notice('Importing {num_auctions} auction(s)', [
			'num_auctions' => count($data),
		]);
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all auctions');
			$db->table(DBAuction::getTable())->truncate();
			foreach ($data as $auction) {
				if (!($auction instanceof ExportAuction)) {
					throw new InvalidArgumentException('AuctionExporter::import() was called with wrong format for data');
				}
				$db->insert(new DBAuction(
					item: $auction->item,
					auctioneer: $auction->startedBy?->tryGetName() ?? $this->config->main->character,
					cost: (0 !== ($auction->cost ?? 0)) ? (int)round($auction->cost??0, 0) : null,
					winner: $auction->winner?->tryGetName(),
					end: $auction->timeEnd ?? time(),
					reimbursed: $auction->reimbursed ?? false,
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
		$logger->notice('All auctions imported');
	}
}
