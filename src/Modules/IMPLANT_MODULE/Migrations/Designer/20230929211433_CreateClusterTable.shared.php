<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class CreateClusterTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "Cluster";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("ClusterID")->primary();
			$table->integer("EffectTypeID");
			$table->string("LongName", 50);
			$table->string("OfficialName", 100);
			$table->integer("NPReq");
			$table->integer("SkillID")->nullable();
		});
	}
}
