<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Core\DBSchema\{CmdCfg, CmdPermission, CmdPermissionSet};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2022_01_15_13_22_57)]
class MigrateCmdcfg implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = CmdCfg::getTable();
		$db->table(CmdPermissionSet::getTable())->insert([
			['name' => 'msg',   'letter' => 'T'],
			['name' => 'priv',  'letter' => 'P'],
			['name' => 'guild', 'letter' => 'G'],
		]);

		/** @var Collection<int,object{"module":?string,"cmdevent":?string,"type":?string,"file":?string,"cmd":?string,"admin":?string,"description":?string,"verify":?int,"status":?int,"dependson":?string,"help":?string}> */
		$entries = $db->table($table)->get();
		$db->table($table)->truncate();

		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->dropUnique(['cmd', 'type']);
		});
		$db->schema()->dropColumns($table, ['admin', 'status', 'type']);
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->unique('cmd');
		});

		/** @var array<string,bool> */
		$cmds = [];
		foreach ($entries as $entry) {
			$db->table(CmdPermission::getTable())->insert([
				'permission_set' => $entry->type,
				'cmd' => $entry->cmd,
				'enabled' => (bool)$entry->status,
				'access_level' => $entry->admin ?? 'all',
			]);
			if (isset($cmds[(string)$entry->cmd])) {
				continue;
			}
			$db->table(CmdCfg::getTable())->insert([
				'module' => $entry->module,
				'cmd' => $entry->cmd,
				'cmdevent' => $entry->cmdevent,
				'file' => $entry->file,
				'description' => $entry->description,
				'verify' => $entry->verify,
				'dependson' => $entry->dependson,
				'help' => !strlen($entry->help??'') ? null : $entry->help,
			]);
			$cmds[(string)$entry->cmd] = true;
		}
	}
}
