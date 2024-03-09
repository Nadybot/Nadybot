<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Modules\ALTS\NickController;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

class CreateNicknameTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = NickController::DB_TABLE;
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("main", 12)->primary();
			$table->string("nick", 25)->nullable(false)->unique();
		});
	}
}
