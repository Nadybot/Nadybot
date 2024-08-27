<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\PVP_MODULE\DBOutcome;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_04_10_15_15_15)]
class AddOutcomesUniqueConstraint implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = DBOutcome::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->unique(['playfield_id', 'site_id', 'timestamp']);
		});
	}
}
