<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Raid;

use Nadybot\Core\{
	DB,
	DBSchema\Setting,
	Routing\Source,
	Types\SchemaMigration,
};
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\{Route, RouteHopFormat};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2022_03_29_16_01_22)]
class MigrateToRoutes implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$route = [
			'source' => 'raid(*)',
			'destination' => Source::PRIV . '(' . $db->getMyname() . ')',
			'two_way' => false,
		];
		$db->table(Route::getTable())->insert($route);

		$format = [
			'render' => false,
			'hop' => 'raid',
			'format' => '%s',
		];
		$db->table(RouteHopFormat::getTable())->insert($format);

		$raidAnnounceRaidmemberLoc = $this->getSetting($db, 'raid_announce_raidmember_loc');
		if (!isset($raidAnnounceRaidmemberLoc)) {
			return;
		}
		$raidInformMemberBeingAdded = ((int)($raidAnnounceRaidmemberLoc->value??3) & 2) === 2;
		$db->table(Setting::getTable())
			->where('name', $raidAnnounceRaidmemberLoc->name)
			->update([
				'name' => 'raid_inform_member_being_added',
				'value' => $raidInformMemberBeingAdded ? '1' : '0',
				'type' => 'bool',
			]);
	}

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(Setting::getTable())
			->where('name', $name)
			->firstObj(Setting::class);
	}
}
