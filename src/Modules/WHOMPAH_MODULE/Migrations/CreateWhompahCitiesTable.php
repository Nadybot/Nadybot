<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOMPAH_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\WHOMPAH_MODULE\WhompahCity;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_28_09_47_08, shared: true)]
class CreateWhompahCitiesTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = WhompahCity::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('id')->primary();
			$table->string('city_name', 50);
			$table->string('zone', 50);
			$table->string('faction', 10);
			$table->string('short_name', 255)->nullable();
		});
	}
}
