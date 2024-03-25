<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Premade;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_26_08_31_38, shared: true)]
class CreatePremadeImplantTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = 'premade_implant';
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('ImplantTypeID');
			$table->integer('ProfessionID');
			$table->integer('AbilityID');
			$table->integer('ShinyClusterID');
			$table->integer('BrightClusterID');
			$table->integer('FadedClusterID');
		});
	}
}
