<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20230929211433, shared: true)]
class CreateClusterTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
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
