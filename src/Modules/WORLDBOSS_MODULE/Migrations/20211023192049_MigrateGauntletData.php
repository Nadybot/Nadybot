<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE\Migrations\Gauntlet;

use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Routing\Character;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\WORLDBOSS_MODULE\GauntletInventoryController;
use Nadybot\Modules\WORLDBOSS_MODULE\WorldBossController;

class MigrateGauntletData implements SchemaMigration {
	/** @Inject */
	public WorldBossController $worldBossController;

	/** @Inject */
	public GauntletInventoryController $gauntletInventoryController;

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "timers_<myname>";
		$timer = $db->table($table)
			->where("name", "Gauntlet")
			->limit(1)
			->asObj()
			->first();
		if (isset($timer)) {
			while ($timer->endtime < time()) {
				$timer->endtime += 61640;
			}
			$this->worldBossController->worldBossUpdateCommand(
				new Character($timer->owner),
				$timer->endtime - time() + 420,
				WorldBossController::VIZARESH,
				61200,
				420
			);
		}
		$db->table($table)
			->where("name", "Gauntlet")
			->delete();
		$db->table($table)
			->where("callback", "GauntletController.gaubuffcallback")
			->update(["callback" => "GauntletBuffController.gaubuffcallback"])

		$table = "gauntlet";
		if (!$db->schema()->hasTable($table)) {
			return;
		}
		$gauInv = $db->table($table)
			->asObj();
		$gauInv->each(function (object $inv) use ($db): void {
			$items = unserialize($inv->items);
			if (is_array($items)) {
				$this->gauntletInventoryController->saveData($inv->player, $items);
			}
		});
		$db->schema()->dropIfExists($table);
	}
}
