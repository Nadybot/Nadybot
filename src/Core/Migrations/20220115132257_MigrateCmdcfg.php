<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\CommandManager;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class MigrateCmdcfg implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = CommandManager::DB_TABLE;
		$db->table("cmd_permission_set_<myname>")->insert([
			["name" => "msg",   "letter" => "T"],
			["name" => "priv",  "letter" => "P"],
			["name" => "guild", "letter" => "G"],
		]);
		$entries = $db->table($table)->get();
		$db->table($table)->truncate();

		$db->schema()->dropColumns($table, ["admin", "status", "type"]);
		$db->schema()->table($table, function(Blueprint $table): void {
			$table->unique("cmd");
		});
		/** @var array<string,bool> */
		$cmds = [];
		foreach ($entries as $entry) {
			$db->table("cmd_permission_<myname>")->insert([
				"name" => (string)$entry->type,
				"cmd" => (string)$entry->cmd,
				"enabled" => (bool)$entry->status,
				"access_level" => (string)$entry->admin,
			]);
			if (isset($cmds[(string)$entry->cmd])) {
				continue;
			}
			$db->table(CommandManager::DB_TABLE)->insert([
				"module" => (string)$entry->module,
				"cmd" => (string)$entry->cmd,
				"cmdevent" => (string)$entry->cmdevent,
				"file" => (string)$entry->file,
				"description" => (string)$entry->description,
				"verify" => (int)$entry->verify,
				"dependson" => (string)$entry->dependson,
				"help" => empty($entry->help) ? null : (string)$entry->help,
			]);
			$cmds[(string)$entry->cmd] = true;
		}
	}
}
