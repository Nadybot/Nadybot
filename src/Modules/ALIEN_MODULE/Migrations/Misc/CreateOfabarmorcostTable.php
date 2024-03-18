<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE\Migrations\Misc;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210425132856, shared: true)]
class CreateOfabarmorcostTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "ofabarmorcost";
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("slot", 30);
			$table->integer("ql");
			$table->integer("vp");
		});
	}
}
