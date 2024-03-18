<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOMPAH_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20211207104416)]
class CreateWhompahCitiesRelTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "whompah_cities_rel";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("city1_id")->index();
			$table->integer("city2_id")->index();
		});
	}
}
