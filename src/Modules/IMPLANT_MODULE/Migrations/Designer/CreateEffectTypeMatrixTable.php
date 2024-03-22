<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_210_426_145_354, shared: true)]
class CreateEffectTypeMatrixTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = 'EffectTypeMatrix';
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('ID')->primary();
			$table->string('Name', 20);
			$table->integer('MinValLow');
			$table->integer('MaxValLow');
			$table->integer('MinValHigh');
			$table->integer('MaxValHigh');
		});
	}
}
