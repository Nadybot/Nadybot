<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE\Migrations\Gauntlet;

use Nadybot\Core\{
	Attributes as NCA,
	DB,
	MessageHub,
	Routing\Character,
	SchemaMigration,
};
use Nadybot\Modules\{
	TIMERS_MODULE\TimerController,
	WORLDBOSS_MODULE\GauntletInventoryController,
	WORLDBOSS_MODULE\WorldBossController,
};
use Psr\Log\LoggerInterface;
use stdClass;

class MigrateGauntletData implements SchemaMigration {
	#[NCA\Inject]
	private WorldBossController $worldBossController;

	#[NCA\Inject]
	private GauntletInventoryController $gauntletInventoryController;

	#[NCA\Inject]
	private TimerController $timerController;

	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "timers_<myname>";
		if (!$db->schema()->hasTable($table)) {
			return;
		}
		$timer = $db->table($table)
			->where("name", "Gauntlet")
			->limit(1)->get()->first();
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
					$route = [
						"source" => "spawn(*)",
						"destination" => $channel,
						"two_way" => false,
					];
					$db->table(MessageHub::DB_TABLE_ROUTES)->insert($route);
				}
			}
			foreach ($channels as $channel) {
				$route = [
					"source" => "system(gauntlet-buff)",
					"destination" => $channel,
					"two_way" => false,
				];
				$db->table(MessageHub::DB_TABLE_ROUTES)->insert($route);
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
