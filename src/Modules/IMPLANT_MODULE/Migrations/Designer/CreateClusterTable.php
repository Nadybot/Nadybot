<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\IMPLANT_MODULE\Cluster;
use Psr\Log\LoggerInterface;

/**
 * Create the cluster table, filled from Cluster.csv
 *
 * The table only holds the data of the CSV file, so changes of its
 * structure recreate it with a new order. It is then filled again
 * from the CSV file, because it's empty.
 */
#[NCA\Migration(order: 2026_11_25_12_00_00, shared: true)]
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
			$table->integer('skill_id')->nullable();
			$table->integer('tradeskill_id')->nullable();
			$table->boolean('jobe');
			$table->decimal('req_faded', 5, 3);
			$table->decimal('req_bright', 5, 3);
			$table->decimal('req_shiny', 5, 3);
		});
	}
}
