<?php declare(strict_types=1);

namespace Nadybot\Modules\LEVEL_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210426165032)]
class CreateLevelsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "levels";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->smallInteger("level")->primary();
			$table->smallInteger("teamMin");
			$table->smallInteger("teamMax");
			$table->smallInteger("pvpMin");
			$table->smallInteger("pvpMax");
			$table->integer("xpsk");
			$table->smallInteger("tokens");
			$table->smallInteger("daily_mission_xp");
			$table->text("missions");
			$table->smallInteger("max_ai_level");
			$table->smallInteger("max_le_level");
			$table->smallInteger("mob_min");
			$table->smallInteger("mob_max");
		});
	}
}
