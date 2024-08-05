<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE\Migrations\Perks;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\SKILLS_MODULE\Perk;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_05_12_55_00, shared: true)]
class CreatePerkTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Perk::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->uuid('id')->primary();
			$table->string('name', 30)->index();
			$table->string('expansion', 2);
			$table->text('description')->nullable();
		});
	}
}
