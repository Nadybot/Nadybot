<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE\Migrations\Gauntlet;

use Nadybot\Core\Attributes as NCA;
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
use stdClass;

class MigrateGauntletData implements SchemaMigration {
	#[NCA\Inject]
	public WorldBossController $worldBossController;

	#[NCA\Inject]
	public GauntletInventoryController $gauntletInventoryController;

	#[NCA\Inject]
	public TimerController $timerController;

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "timers_<myname>";
		if (!$db->schema()->hasTable($table)) {
			return;
		}
		$timer = $db->table($table)
			->where("name", "Gauntlet")
			->limit(1)->get() ->first();
		if (isset($timer)) {
			$endtime = (int)$timer->endtime;
			while ($endtime < time()) {
				$endtime += 61640;
			}
			$this->worldBossController->worldBossUpdate(
				new Character((string)$timer->owner),
				WorldBossController::VIZARESH,
				$endtime - time(),
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
		$db->table($table)
			->get()
			->each(function (stdClass $inv): void {
				$items = @unserialize((string)$inv->items);
				if (is_array($items)) {
					$this->gauntletInventoryController->saveData((string)$inv->player, $items);
				}
			});
		$db->schema()->dropIfExists($table);
		$db->schema()->dropIfExists("bigboss_timers");
	}
}
