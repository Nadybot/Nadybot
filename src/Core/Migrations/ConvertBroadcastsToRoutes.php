<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\{
	DB,
	DBSchema\Setting,
	Routing\Source,
	SchemaMigration,
};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_08_28_05_11_50)]
class ConvertBroadcastsToRoutes implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = 'broadcast_<myname>';
		if (!$db->schema()->hasTable($table)) {
			return;
		}

		$broadcasts = $db->table($table)->pluckStrings('name');
		$orgSetting = $this->getSetting($db, 'broadcast_to_guild');
		$toOrg = isset($orgSetting) ? ($orgSetting->value === '1') : true;
		$privSetting = $this->getSetting($db, 'broadcast_to_privchan');
		$toPriv = isset($privSetting) ? ($privSetting->value === '1') : true;
		foreach ($broadcasts as $broadcast) {
			$this->convertBroadcastToRoute($db, $broadcast, $toOrg, $toPriv);
		}
		$db->schema()->dropIfExists($table);
	}

	public function convertBroadcastToRoute(DB $db, string $broadcast, bool $org, bool $priv): void {
		$name = ucfirst(strtolower($broadcast));
		$botName = $db->getMyname();
		if ($org) {
			$route = [
				'source' => Source::TELL . "({$name})",
				'destination' => Source::ORG,
				'two_way' => false,
			];
			$db->table(Route::getTable())->insert($route);
		}
		if ($priv) {
			$route = [
				'source' => Source::TELL . "({$name})",
				'destination' => Source::PRIV . "({$botName})",
				'two_way' => false,
			];
			$db->table(Route::getTable())->insert($route);
		}
	}

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(Setting::getTable())
			->where('name', $name)
			->firstObj(Setting::class);
	}
}
