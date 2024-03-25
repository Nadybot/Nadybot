<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\WEBSERVER_MODULE\ApiController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_09_04_16_49_24)]
class CreateApiKeyTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = ApiController::DB_TABLE;
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->id();
			$table->string('character', 12)->index();
			$table->string('token', 8)->unique();
			$table->unsignedBigInteger('last_sequence_nr')->default(0);
			$table->text('pubkey');
			$table->unsignedInteger('created');
		});
	}
}
