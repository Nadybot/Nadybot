<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE\Migrations\Playfields;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210426051540, shared: true)]
class CreatePlayfieldsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "playfields";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("id")->primary();
			$table->string("long_name", 26)->unique();
			$table->string("short_name", 15)->unique();
		});
	}
}
