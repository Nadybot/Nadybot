<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\IMPLANT_MODULE\ImplantDesign;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_26_08_41_24, shared: true)]
class CreateImplantDesignTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = ImplantDesign::getTable();
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->string('name', 50);
			$table->string('owner', 20);
			$table->integer('dt')->nullable();
			$table->text('design')->nullable();
		});
	}
}
