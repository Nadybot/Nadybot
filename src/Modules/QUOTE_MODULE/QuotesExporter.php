<?php declare(strict_types=1);

namespace Nadybot\Modules\QUOTE_MODULE;

use InvalidArgumentException;
use Nadybot\Core\Attributes\Inject;
use Nadybot\Core\Config\BotConfig;
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
	NCA\Exporter('quotes'),
	NCA\Importer('quotes', ExportQuote::class),
]
class QuotesExporter extends ModuleInstance implements ExporterInterface, ImporterInterface {
	#[Inject]
	private BotConfig $config;

	/** @return list<ExportQuote> */
	public function export(DB $db, LoggerInterface $logger): array {
		return $db->table(Quote::getTable())
			->orderBy('id')
			->asObj(Quote::class)
			->map(static function (Quote $quote): ExportQuote {
				return new ExportQuote(
					quote: $quote->msg,
					time: $quote->dt,
					contributor: new ExportCharacter(name: $quote->poster),
				);
			})->toList();
	}

	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$logger->notice('Importing {num_quotes} quotes', [
			'num_quotes' => count($data),
		]);
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all quotes');
			$db->table(Quote::getTable())->truncate();
			foreach ($data as $quote) {
				if (!($quote instanceof ExportQuote)) {
					throw new InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . '() called with wrong data');
				}
				$db->insert(new Quote(
					poster: $quote->contributor?->tryGetName() ?? $this->config->main->character,
					dt: $quote->time??time(),
					msg: $quote->quote,
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
		$logger->notice('All quotes imported');
	}
}
