<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_210_426_142_533, shared: true)]
class CreateClusterTypeTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = 'ClusterType';
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('ClusterTypeID')->primary();
			$table->string('Name', 10);
		});
	}
}
