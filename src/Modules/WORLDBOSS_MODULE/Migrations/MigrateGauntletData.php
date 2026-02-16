<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE\Migrations;

use function Safe\unserialize;
use Nadybot\Core\{
	Attributes as NCA,
	Collection,
	DB,
	Routing\Character,
	Safe,
	Types\SchemaMigration,
};
use Nadybot\Core\DBSchema\Route;
use Nadybot\Modules\{
	TIMERS_MODULE\TimerController,
	WORLDBOSS_MODULE\GauntletInventoryController,
	WORLDBOSS_MODULE\WorldBossController,
};
use Nadybot\Modules\TIMERS_MODULE\Timer;
use Psr\Log\LoggerInterface;
use stdClass;

#[NCA\Migration(order: 2021_10_23_19_20_50)]
class MigrateGauntletData implements SchemaMigration {
	private const GAUNTLET_TABLE = 'gauntlet';
	private const BIGBOSS_TABLE = 'bigboss_timers';

	#[NCA\Inject]
	private WorldBossController $worldBossController;

	#[NCA\Inject]
	private GauntletInventoryController $gauntletInventoryController;

	#[NCA\Inject]
	private TimerController $timerController;

	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Timer::getTable();
		if (!$db->schema()->hasTable($table)) {
			return;
		}

		/** @var ?\stdClass */
		$timer = $db->table($table)
			->where('name', 'Gauntlet')
			->limit(1)->get()->first();
		if (isset($timer)) {
			$endtime = (int)$timer->endtime;
			while ($endtime < time()) {
				$endtime += 61_640;
			}
			$this->worldBossController->worldBossUpdate(
				new Character((string)$timer->owner),
				WorldBossController::VIZARESH,
				$endtime - time(),
			);
			$this->timerController->remove('Gauntlet');
		}
		$timers = $this->timerController->getAllTimers();
		foreach ($timers as $timer2) {
			if ($timer2->callback ===  'GauntletController.gaubuffcallback') {
				$timer2->callback = 'GauntletBuffController.gaubuffcallback';
			}
		}
		$db->table($table)
			->where('callback', 'GauntletController.gaubuffcallback')
			->update(['callback' => 'GauntletBuffController.gaubuffcallback']);

		if (!$db->schema()->hasTable(self::GAUNTLET_TABLE)) {
			$channels = ['aoorg', 'aopriv(' . $db->getMyname() . ')'];
			if (!$db->schema()->hasTable(self::BIGBOSS_TABLE)) {
				foreach ($channels as $channel) {
					$route = [
						'source' => 'spawn(*)',
						'destination' => $channel,
						'two_way' => false,
					];
					$db->table(Route::getTable())->insert($route);
				}
			}
			foreach ($channels as $channel) {
				$route = [
					'source' => 'system(gauntlet-buff)',
					'destination' => $channel,
					'two_way' => false,
				];
				$db->table(Route::getTable())->insert($route);
			}
			return;
		}

		/** @var Collection<int,\stdClass> */
		$data = $db->table(self::GAUNTLET_TABLE)->get();
		$data->each(function (stdClass $inv): void {
			try {
				$items = Safe::exceptionWrapper(unserialize(...), (string)$inv->items, ['allowed_classes' => false]);
				if (is_array($items) && array_is_list($items) && count($items) === 17) {
					/** @var non-empty-list<int> $items */
					$this->gauntletInventoryController->saveData((string)$inv->player, $items);
				}
			} catch (\ErrorException) {
			}
		});
		$db->schema()->dropIfExists(self::GAUNTLET_TABLE);
		$db->schema()->dropIfExists(self::BIGBOSS_TABLE);
	}
}
