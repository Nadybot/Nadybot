<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, EventManager, Safe, Types\SchemaMigration, Util};
use Nadybot\Core\DBSchema\EventCfg;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_02_17_10_57_31)]
class NormalizeTimerTimes implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = EventCfg::getTable();
		$db->table($table)
			->whereIlike('type', 'timer(%')
			->get()
			->each(function (\stdClass $event) use ($db, $table): void {
				$this->updateType($event, $db, $table);
			});
	}

	private function updateType(\stdClass $event, DB $db, string $table): void {
		if (!isset($event->type) || !is_string($event->type)) {
			return;
		}
		if (!isset($event->module) || !isset($event->file)) {
			return;
		}
		if (!count($arr = Safe::pregMatch(EventManager::TIMER_EVENT_REGEX, $event->type))) {
			return;
		}
		$time = Util::parseTime($arr[1]);
		if ($time === 0) {
			return;
		}
		$cleanInterval = Util::unixtimeToReadable($time, true);
		$cleanInterval = str_replace(' ', '', $cleanInterval);
		$newType = "timer({$cleanInterval})";
		$db->table($table)
			->where('module', $event->module)
			->where('type', $event->type)
			->where('file', $event->file)
			->update(['type' => $newType]);
	}
}
