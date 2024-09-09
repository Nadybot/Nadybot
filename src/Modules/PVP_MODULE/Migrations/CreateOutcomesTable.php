<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\PVP_MODULE\DBOutcome;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2023_03_03_20_58_12)]
class CreateOutcomesTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = DBOutcome::getTable();
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->unsignedSmallInteger('playfield_id');
			$table->unsignedSmallInteger('site_id');
			$table->unsignedInteger('timestamp');
			$table->string('attacker_faction', 7)->nullable(true);
			$table->string('attacker_org', 40)->nullable(true);
			$table->string('losing_faction', 7);
			$table->string('losing_org', 40)->nullable(true);

			$table->index('playfield_id');
			$table->index('site_id');
			$table->index('timestamp');
			$table->index('attacker_org');
			$table->index('losing_org');
		});
	}
}
