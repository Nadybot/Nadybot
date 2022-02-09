<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Exception;
use Nadybot\Core\CommandAlias;
use Nadybot\Core\CommandManager;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\BASIC_CHAT_MODULE\ChatAssistController;
use Nadybot\Modules\BASIC_CHAT_MODULE\ChatLeaderController;
use Nadybot\Modules\BASIC_CHAT_MODULE\ChatRallyController;
use Nadybot\Modules\BASIC_CHAT_MODULE\ChatTopicController;
use Nadybot\Modules\EVENTS_MODULE\EventsController;
use Nadybot\Modules\LOOT_MODULE\LootController;
use Nadybot\Modules\RAFFLE_MODULE\RaffleController;
use Nadybot\Modules\RAID_MODULE\AuctionController;
use Nadybot\Modules\RAID_MODULE\RaidBlockController;
use Nadybot\Modules\RAID_MODULE\RaidController;
use Nadybot\Modules\RAID_MODULE\RaidMemberController;
use Nadybot\Modules\RAID_MODULE\RaidPointsController;
use Nadybot\Modules\WORLDBOSS_MODULE\WorldBossController;

class MigrateSubCmds implements SchemaMigration {
	protected function deleteAlias(DB $db, LoggerWrapper $logger, string $alias): void {
		$db->table(CommandAlias::DB_TABLE)
			->where("alias", $alias)
			->delete();
	}

	protected function migrateSubCmdRights(DB $db, LoggerWrapper $logger, string $old, string $new): void {
		$updated = $db->table(CommandManager::DB_TABLE)
			->where('cmd', $old)
			->update([
				'cmd' => $new,
				'cmdevent' => "subcmd",
				'dependson' => strtolower(explode(" ", $new)[0]),
			]);
		if (!$updated) {
			$logger->warning("Command {$old} not found");
		}
		$db->table(CommandManager::DB_TABLE_PERMS)
			->where('cmd', $old)
			->update(['cmd' => $new]);
	}

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$deletedAliases = [
			"bid start",
			"bid end",
			"bid cancel",
			"bid reimburse",
			"bid payback",
			"bid refund",
			"adminhelp",
			"raid add",
			"raid kick",
			"points add",
			"points rem",
			"raid reward",
			"raid punish",
			"comment categories",
			"comment category",
			"raffle start",
			"raffle end",
			"raffle cancel",
			"raffle timer",
			"raffle announce",
		];

		$renamedCmds = [
			"assist .+" => ChatAssistController::CMD_SET_ADD_CLEAR,
			"leader (.+)" => ChatLeaderController::CMD_LEADER_SET,
			"rally .+" => ChatRallyController::CMD_RALLY_SET,
			"topic .+" => ChatTopicController::CMD_TOPIC_SET,
			"auction" => AuctionController::CMD_BID_AUCTION,
			"auction reimburse .+" => AuctionController::CMD_BID_REIMBURSE,
			"raid .+" => RaidController::CMD_RAID_MANAGE,
			"raid spp .+" => RaidController::CMD_RAID_TICKER,
			"raid (join|leave)" => RaidMemberController::CMD_RAID_JOIN_LEAVE,
			"raidmember" => RaidMemberController::CMD_RAID_KICK_ADD,
			"raidpoints" => RaidPointsController::CMD_RAID_REWARD_PUNISH,
			"reward .+" => RaidPointsController::CMD_REWARD_EDIT,
			"points .+" => RaidPointsController::CMD_POINTS_OTHER,
			"pointsmod" => RaidPointsController::CMD_POINTS_MODIFY,
			"raidblock .+" => RaidBlockController::CMD_RAIDBLOCK_EDIT,
			"commentcategories" => "comment categories",
			"events add .+" => EventsController::CMD_EVENT_MANAGE,
			"loot .+" => LootController::CMD_LOOT_MANAGE,
			"raffleadmin" => RaffleController::CMD_RAFFLE_MANAGE,
			"tara .+" => WorldBossController::CMD_TARA_UPDATE,
			"father .+" => WorldBossController::CMD_FATHER_UPDATE,
			"loren .+" => WorldBossController::CMD_LOREN_UPDATE,
			"gauntlet .+" => WorldBossController::CMD_GAUNTLET_UPDATE,
			"reaper .+" => WorldBossController::CMD_REAPER_UPDATE,
		];

		foreach ($deletedAliases as $alias) {
			$this->deleteAlias($db, $logger, $alias);
		}

		foreach ($renamedCmds as $oldName => $newName) {
			$this->migrateSubCmdRights($db, $logger, $oldName, $newName);
		}
	}
}
