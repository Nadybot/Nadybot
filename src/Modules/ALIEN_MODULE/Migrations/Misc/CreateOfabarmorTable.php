<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE\Migrations\Misc;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210425131351, shared: true)]
class CreateOfabarmorTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "ofabarmor";
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("profession", 30);
			$table->string("name", 150);
			$table->string("slot", 30);
			$table->integer("lowid");
			$table->integer("highid");
			$table->integer("upgrade");
		});
	}
}
