<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\IMPLANT_MODULE\SymbiantClusterMatrix;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_13_12_21_00, shared: true)]
class CreateSymbiantClusterMatrixTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = SymbiantClusterMatrix::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->dropIfExists('SymbiantClusterMatrix');
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('symbiant_id');
			$table->integer('cluster_id');
			$table->integer('amount');
		});
	}
}
