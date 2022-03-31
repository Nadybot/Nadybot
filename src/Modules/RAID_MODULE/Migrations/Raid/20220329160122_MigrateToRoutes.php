<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Raid;

use Nadybot\Core\{
	DB,
	DBSchema\Route,
	DBSchema\RouteHopFormat,
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

		$format = new RouteHopFormat();
		$format->render = false;
		$format->hop = 'raid';
		$db->insert(Source::DB_TABLE, $format);

		$raidAnnounceRaidmemberLoc = $this->getSetting($db, 'raid_announce_raidmember_loc');
		if (!isset($raidAnnounceRaidmemberLoc)) {
			return;
		}
		$raidInformMemberBeingAdded = ((int)($raidAnnounceRaidmemberLoc->value??3) & 2) === 2;
		$db->table(SettingManager::DB_TABLE)
			->where("name", $raidAnnounceRaidmemberLoc->name)
			->update([
				"name" => "raid_inform_member_being_added",
				"value" => $raidInformMemberBeingAdded ? "1" : "0",
				"type" => 'bool',
			]);
	}
}
