<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\IMPLANT_MODULE\ClusterType;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_13_10_40_01, shared: true)]
class CreateClusterTypeTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = ClusterType::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->dropIfExists('ClusterType');
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('cluster_type_id')->primary();
			$table->string('name', 10);
		});
	}
}
