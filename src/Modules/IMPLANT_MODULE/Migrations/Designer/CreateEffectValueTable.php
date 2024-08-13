<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\IMPLANT_MODULE\EffectValue;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_13_11_11_01, shared: true)]
class CreateEffectValueTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = EffectValue::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->dropIfExists('EffectValue');
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('effect_id')->primary();
			$table->string('name', 50);
			$table->integer('q200_value');
		});
	}
}
