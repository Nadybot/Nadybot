<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\{CmdCfg, CmdPermission};
use Nadybot\Core\{DB, Types\SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2022_05_04_02_14_36)]
class MergeMembersAndMemberCommands implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->table(CmdCfg::getTable())
			->where('cmd', 'member')
			->update([
				'cmd' => 'members add/remove',
				'cmdevent' => 'subcmd',
				'dependson' => 'members',
			]);
		$db->table(CmdPermission::getTable())
			->where('cmd', 'member')
			->update(['cmd' => 'members add/remove']);
	}
}
