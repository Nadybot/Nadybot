<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE\Migrations\Misc;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\ALIEN_MODULE\LEProc;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_15_20_21_00, shared: true)]
class CreateLEProcsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = LEProc::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->uuid('id')->primary();
			$table->string('profession', 20)->index();
			$table->string('name', 30);
			$table->string('research_name', 30);
			$table->unsignedTinyInteger('research_lvl')->index();
			$table->unsignedTinyInteger('proc_type')->index();
			$table->unsignedTinyInteger('chance');
			$table->string('modifiers', 255);
			$table->string('duration', 5);
			$table->string('proc_trigger', 10);
			$table->string('description', 255);
		});
	}
}
