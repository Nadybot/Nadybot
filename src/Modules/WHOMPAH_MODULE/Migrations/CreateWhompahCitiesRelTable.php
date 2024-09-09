<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOMPAH_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\WHOMPAH_MODULE\WhompahCityRel;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_12_07_10_44_16, shared: true)]
class CreateWhompahCitiesRelTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = WhompahCityRel::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('city1_id')->index();
			$table->integer('city2_id')->index();
		});
	}
}
