<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{AccessManager, DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210906022236)]
class CreateAuditTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = AccessManager::DB_TABLE;
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->string("actor", 12)->index();
			$table->string("actee", 12)->nullable()->index();
			$table->string("action", 20)->index();
			$table->text("value")->nullable();
			$table->integer("time")->index();
		});
	}
}
