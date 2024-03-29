<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE\Migrations;

use Nadybot\Core\{CommandManager, DB, LoggerWrapper, SchemaMigration};

class MergeMembersAndMemberCommands implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$db->table(CommandManager::DB_TABLE)
			->where('cmd', 'member')
			->update([
				'cmd' => 'members add/remove',
				'cmdevent' => 'subcmd',
				'dependson' => 'members',
			]);
		$db->table(CommandManager::DB_TABLE_PERMS)
			->where('cmd', 'member')
			->update(['cmd' => 'members add/remove']);
	}
}
