<?php declare(strict_types=1);

namespace Nadybot\Modules\COMMENT_MODULE\Migrations;

use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\COMMENT_MODULE\ReputationController;
use Throwable;

class MigrateReputationTable implements SchemaMigration {
	/** @Inject */
	public ReputationController $reputationController;

	public function migrate(LoggerWrapper $logger, DB $db): void {
		if (!$db->schema()->hasTable("reputation")) {
			return;
		};
		$oldData = $db->table("reputation")->asObj();
		if ($oldData->count() === 0) {
			$logger->log("INFO", "Reputation table empty, no need to convert anything");
			$db->schema()->dropIfExists("reputation");
			return;
		}
		$logger->log(
			"INFO",
			"Converting " . $oldData->count() . " DB entries from reputation to comments"
		);
		$cat = $this->reputationController->getReputationCategory();
		try {
			foreach ($oldData as $row) {
				$db->table("<table:comments>")
					->insert([
						"category" => $cat->name,
						"character" => $row->name,
						"comment" => "{$row->reputation} {$row->comment}",
						"created_at" => $row->dt,
						"created_by" => $row->by
					]);
			}
		} catch (Throwable $e) {
			$logger->log(
				"WARNING",
				"Error during the conversion of the reputation table: ".
				$e->getMessage()
			);
			return;
		}
		$logger->log(
			"INFO",
			"Conversion of reputation table finished successfully, removing old table"
		);
		$db->schema()->dropIfExists("reputation");
	}
}
