<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{CommandAlias, DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210423090608)]
class CreateCmdAliasTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = CommandAlias::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("cmd", 255);
			$table->string("module", 50)->nullable();
			$table->string("alias", 25)->index();
			$table->integer("status")->nullable()->default(0);
		});
	}
}
