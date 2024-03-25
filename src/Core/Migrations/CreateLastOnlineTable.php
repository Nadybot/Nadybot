<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2022_05_02_09_29_08, shared: true)]
class CreateLastOnlineTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = 'last_online';
		$db->schema()->create($table, static function (Blueprint $table) {
			$table->unsignedInteger('uid')->unique();
			$table->string('name', 12)->index();
			$table->unsignedInteger('dt');
		});
	}
}
