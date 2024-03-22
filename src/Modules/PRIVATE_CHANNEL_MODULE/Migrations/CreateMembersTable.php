<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\PRIVATE_CHANNEL_MODULE\PrivateChannelController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_210_427_062_304)]
class CreateMembersTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = PrivateChannelController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->string('name', 25)->primary();
			$table->integer('autoinv')->nullable()->default(0);
		});
	}
}
