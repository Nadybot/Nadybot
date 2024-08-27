<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\PVP_MODULE\DBTowerAttack;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2023_03_12_21_09_48)]
class AddCtQlToAttacks implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = DBTowerAttack::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->unsignedSmallInteger('ql')->nullable(true);
		});
	}
}
