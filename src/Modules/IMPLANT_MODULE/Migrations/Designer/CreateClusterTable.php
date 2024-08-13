<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\IMPLANT_MODULE\Cluster;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_13_10_20_01, shared: true)]
class CreateClusterTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Cluster::getTable();
		$db->schema()->dropIfExists('Cluster');
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('cluster_id')->primary();
			$table->integer('effect_type_id');
			$table->string('long_name', 50);
			$table->string('official_name', 100);
			$table->integer('np_req');
			$table->integer('skill_id')->nullable();
		});
	}
}
