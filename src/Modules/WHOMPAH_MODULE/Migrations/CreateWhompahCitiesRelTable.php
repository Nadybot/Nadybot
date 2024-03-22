<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOMPAH_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_211_207_104_416, shared: true)]
class CreateWhompahCitiesRelTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = 'whompah_cities_rel';
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('city1_id')->index();
			$table->integer('city2_id')->index();
		});
	}
}
