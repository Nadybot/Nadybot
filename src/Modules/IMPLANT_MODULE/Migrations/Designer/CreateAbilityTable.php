<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\IMPLANT_MODULE\Ability;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_13_11_17_01, shared: true)]
class CreateAbilityTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Ability::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->dropIfExists('Ability');
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('ability_id')->primary();
			$table->string('name', 20);
		});
	}
}
