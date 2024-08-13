<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\IMPLANT_MODULE\ImplantType;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_13_11_04_01, shared: true)]
class CreateImplantTypeTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = ImplantType::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->dropIfExists('ImplantType');
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('implant_type_id')->primary();
			$table->string('name', 20);
			$table->string('short_name', 10);
		});
	}
}
