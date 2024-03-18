<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\PVP_MODULE\NotumWarsController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20230312210948)]
class AddCtQlToAttacks implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = NotumWarsController::DB_ATTACKS;
		$db->schema()->table($table, function (Blueprint $table) {
			$table->unsignedSmallInteger("ql")->nullable(true);
		});
	}
}
