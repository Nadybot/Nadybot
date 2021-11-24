<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE\Migrations\Gauntlet;

use Nadybot\Core\{
	DB,
	DBSchema\Route,
	LoggerWrapper,
	MessageHub,
	Routing\Character,
	SchemaMigration,
};
use Nadybot\Modules\TIMERS_MODULE\TimerController;
use Nadybot\Modules\WORLDBOSS_MODULE\GauntletInventoryController;
use Nadybot\Modules\WORLDBOSS_MODULE\WorldBossController;

class MigrateGauntletData implements SchemaMigration {
	/** @Inject */
	public WorldBossController $worldBossController;

	/** @Inject */
	public GauntletInventoryController $gauntletInventoryController;

	/** @Inject */
	public TimerController $timerController;

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
			$this->worldBossController->worldBossUpdate(
				new Character($timer->owner),
				WorldBossController::VIZARESH,
				$timer->endtime - time(),
			);
			$this->timerController->remove("Gauntlet");
		}
		$timers = $this->timerController->getAllTimers();
		foreach ($timers as $timer) {
			if ($timer->callback ===  "GauntletController.gaubuffcallback") {
				$timer->callback = "GauntletBuffController.gaubuffcallback";
			}
		}
		$db->table($table)
			->where("callback", "GauntletController.gaubuffcallback")
			->update(["callback" => "GauntletBuffController.gaubuffcallback"]);

		$table = "gauntlet";
		if (!$db->schema()->hasTable($table)) {
			$channels = ["aoorg", "aopriv(" . $db->getMyname() . ")"];
			if (!$db->schema()->hasTable("bigboss_timers")) {
				foreach ($channels as $channel) {
					$route = new Route();
					$route->source = "spawn(*)";
					$route->destination = $channel;
					$db->insert(MessageHub::DB_TABLE_ROUTES, $route);
				}
			}
			foreach ($channels as $channel) {
				$route = new Route();
				$route->source = "system(gauntlet-buff)";
				$route->destination = $channel;
				$db->insert(MessageHub::DB_TABLE_ROUTES, $route);
			}
			return;
		}
		$gauInv = $db->table($table)
			->asObj();
		$gauInv->each(function (object $inv): void {
			$items = @unserialize($inv->items);
			if (is_array($items)) {
				$this->gauntletInventoryController->saveData($inv->player, $items);
			}
		});
		$db->schema()->dropIfExists($table);
		$db->schema()->dropIfExists("bigboss_timers");
	}
}
