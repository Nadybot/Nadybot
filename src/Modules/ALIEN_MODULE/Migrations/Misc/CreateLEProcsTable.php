<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE\Migrations\Misc;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210425130804, shared: true)]
class CreateLEProcsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "leprocs";
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("profession", 20);
			$table->string("name", 50);
			$table->string("research_name", 50)->nullable();
			$table->integer("research_lvl");
			$table->char("proc_type", 6)->nullable();
			$table->string("chance", 20)->nullable();
			$table->string("modifiers", 255);
			$table->string("duration", 20);
			$table->string("proc_trigger", 20);
			$table->string("description", 255);
		});
	}
}
