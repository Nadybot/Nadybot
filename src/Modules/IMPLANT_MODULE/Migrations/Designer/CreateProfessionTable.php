<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\IMPLANT_MODULE\Profession;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_13_11_54_01, shared: true)]
class CreateProfessionTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Profession::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->dropIfExists('Profession');
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('id')->primary();
			$table->string('name', 20);
		});
	}
}
