<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\IMPLANT_MODULE\ImplantDesign;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_12_08_53_00, shared: true)]
class AddIndexesToImplantDesignTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = ImplantDesign::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->unique(['name', 'owner']);
		});
	}
}
