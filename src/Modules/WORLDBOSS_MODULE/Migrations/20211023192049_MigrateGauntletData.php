<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE\Migrations\Gauntlet;

use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Routing\Character;
use Nadybot\Core\SchemaMigration;
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
			$this->worldBossController->worldBossUpdateCommand(
				new Character($timer->owner),
				$timer->endtime - time() + 420,
				WorldBossController::VIZARESH,
				61200,
				420
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
			if (!$db->schema()->hasTable("bigboss_timers")) {
				$route = new Route();
				$route->source = "spawn(*)";
				$route->destination = "aoorg";
				$db->insert(MessageHub::DB_TABLE_ROUTES, $route);

				$route = new Route();
				$route->source = "spawn(*)";
				$route->destination = "aopriv(" . $db->getMyname() . ")";
				$db->insert(MessageHub::DB_TABLE_ROUTES, $route);
			}
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
		$db->schema()->dropIfExists("bigboss_timers");
	}
}