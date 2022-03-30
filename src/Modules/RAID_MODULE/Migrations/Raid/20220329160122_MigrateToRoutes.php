<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Raid;

use Nadybot\Core\{
	DB,
	DBSchema\Route,
	DBSchema\Setting,
	LoggerWrapper,
	MessageHub,
	Routing\Source,
	SchemaMigration,
	SettingManager,
};

class MigrateToRoutes implements SchemaMigration {
	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$route = new Route();
		$route->source = "raid(*)";
		$route->destination = Source::PRIV . "(" . $db->getMyname() . ")";
		$db->insert(MessageHub::DB_TABLE_ROUTES, $route);

		$raidAnnounceRaidmemberLoc = $this->getSetting($db, 'raid_announce_raidmember_loc');
		if (!isset($raidAnnounceRaidmemberLoc)) {
			return;
		}
		$raidInformMemberOfLocChange = ((int)($raidAnnounceRaidmemberLoc->value??3) & 2) === 2;
		$db->table(SettingManager::DB_TABLE)
			->where("name", $raidAnnounceRaidmemberLoc->name)
			->update([
				"name" => "raid_inform_member_of_loc_change",
				"value" => $raidInformMemberOfLocChange ? "1" : "0",
				"type" => 'bool',
			]);
	}
}
