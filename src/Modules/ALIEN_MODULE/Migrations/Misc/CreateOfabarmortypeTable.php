<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE\Migrations\Misc;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210425132426, shared: true)]
class CreateOfabarmortypeTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "ofabarmortype";
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->smallInteger("type");
			$table->string("profession", 30);
		});
	}
}
