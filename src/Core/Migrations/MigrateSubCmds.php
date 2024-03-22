<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	CommandAlias,
	CommandManager,
	DB,
	SchemaMigration,
};
use Nadybot\Modules\{
	BASIC_CHAT_MODULE\ChatAssistController,
	BASIC_CHAT_MODULE\ChatLeaderController,
	BASIC_CHAT_MODULE\ChatRallyController,
	BASIC_CHAT_MODULE\ChatTopicController,
	EVENTS_MODULE\EventsController,
	LOOT_MODULE\LootController,
	NEWS_MODULE\NewsController,
	RAFFLE_MODULE\RaffleController,
	RAID_MODULE\AuctionController,
	RAID_MODULE\RaidBlockController,
	RAID_MODULE\RaidController,
	RAID_MODULE\RaidMemberController,
	RAID_MODULE\RaidPointsController,
	WORLDBOSS_MODULE\WorldBossController,
};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_220_209_073_229)]
class MigrateSubCmds implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$deletedAliases = [
			'bid start',
			'bid end',
			'bid cancel',
			'bid reimburse',
			'bid payback',
			'bid refund',
			'adminhelp',
			'raid add',
			'raid kick',
			'points add',
			'points rem',
			'raid reward',
			'raid punish',
			'comment categories',
			'comment category',
			'raffle start',
			'raffle end',
			'raffle cancel',
			'raffle timer',
			'raffle announce',
		];

		$renamedCmds = [
			'assist .+' => ChatAssistController::CMD_SET_ADD_CLEAR,
			'leader (.+)' => ChatLeaderController::CMD_LEADER_SET,
			'rally .+' => ChatRallyController::CMD_RALLY_SET,
			'topic .+' => ChatTopicController::CMD_TOPIC_SET,
			'auction' => AuctionController::CMD_BID_AUCTION,
			'auction reimburse .+' => AuctionController::CMD_BID_REIMBURSE,
			'raid .+' => RaidController::CMD_RAID_MANAGE,
			'raid spp .+' => RaidController::CMD_RAID_TICKER,
			'raid (join|leave)' => RaidMemberController::CMD_RAID_JOIN_LEAVE,
			'raidmember' => RaidMemberController::CMD_RAID_KICK_ADD,
			'raidpoints' => RaidPointsController::CMD_RAID_REWARD_PUNISH,
			'reward .+' => RaidPointsController::CMD_REWARD_EDIT,
			'points .+' => RaidPointsController::CMD_POINTS_OTHER,
			'pointsmod' => RaidPointsController::CMD_POINTS_MODIFY,
			'raidblock .+' => RaidBlockController::CMD_RAIDBLOCK_EDIT,
			'commentcategories' => 'comment categories',
			'events add .+' => EventsController::CMD_EVENT_MANAGE,
			'loot .+' => LootController::CMD_LOOT_MANAGE,
			'raffleadmin' => RaffleController::CMD_RAFFLE_MANAGE,
			'tara .+' => WorldBossController::CMD_TARA_UPDATE,
			'father .+' => WorldBossController::CMD_FATHER_UPDATE,
			'loren .+' => WorldBossController::CMD_LOREN_UPDATE,
			'gauntlet .+' => WorldBossController::CMD_GAUNTLET_UPDATE,
			'reaper .+' => WorldBossController::CMD_REAPER_UPDATE,
			'news .+' => NewsController::CMD_NEWS_MANAGE,
		];

		foreach ($deletedAliases as $alias) {
			$this->deleteAlias($db, $logger, $alias);
		}

		foreach ($renamedCmds as $oldName => $newName) {
			$this->migrateSubCmdRights($db, $logger, $oldName, $newName);
		}
	}

	protected function deleteAlias(DB $db, LoggerInterface $logger, string $alias): void {
		$db->table(CommandAlias::DB_TABLE)
			->where('alias', $alias)
			->delete();
	}

	protected function migrateSubCmdRights(DB $db, LoggerInterface $logger, string $old, string $new): void {
		$db->table(CommandManager::DB_TABLE)
			->where('cmd', $old)
			->update([
				'cmd' => $new,
				'cmdevent' => 'subcmd',
				'dependson' => strtolower(explode(' ', $new)[0]),
			]);
		$db->table(CommandManager::DB_TABLE_PERMS)
			->where('cmd', $old)
			->update(['cmd' => $new]);
	}
}
