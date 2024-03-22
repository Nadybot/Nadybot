<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{CommandManager, DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_220_115_125_309)]
class CreatePermissionSet implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = 'cmd_permission_set_<myname>';
		$db->schema()->create($table, static function (Blueprint $table) {
			$table->string('name', 50)->unique();
			$table->string('letter', 1)->unique();
		});

		$table = CommandManager::DB_TABLE_PERMS;
		$db->schema()->create($table, static function (Blueprint $table) {
			$table->id();
			$table->string('permission_set', 50)->index();
			$table->string('cmd', 50)->index();
			$table->boolean('enabled')->default(false);
			$table->string('access_level', 30);
			$table->unique(['cmd', 'permission_set']);
		});
	}
}
