<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Core\SettingManager;
use Nadybot\Modules\TRACKER_MODULE\TrackerController;

class MigrateToTrackerFormat implements SchemaMigration {
	#[NCA\Inject]
	public TrackerController $trackerController;

	protected function getSetting(DB $db, string $name): ?string {
		/** @var ?Setting */
		$setting = $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
		return $setting->value ?? null;
	}

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$trackerLayout = (int)($this->getSetting($db, "tracker_layout") ?? 0);
		if ($trackerLayout === 0) {
			$trackerLayoutOn = 'TRACK: %s logged <green>on<end>.';
			$trackerLayoutOff = 'TRACK: %s logged <red>off<end>.';
		} else {
			$trackerLayoutOn = '<green>+<end> %s';
			$trackerLayoutOff = '<red>-<end> %s';
		}

		$info = "";
		if (($this->getSetting($db, "tracker_use_faction_color") ?? "0") === "1") {
			$info .= "<{faction}>{name}<end>";
		} else {
			$info .= "<highlight>{name}<end>";
		}
		$bracketed = [];
		$showLevel = (bool)($this->getSetting($db, 'tracker_show_level') ?? '0');
		$showProf = (bool)($this->getSetting($db, 'tracker_show_prof') ?? '0');
		$showOrg = (bool)($this->getSetting($db, 'tracker_show_org') ?? '0');
		if ($showLevel) {
			$bracketed []= "{level}";
		}
		if ($showProf) {
			$bracketed []= "{profession}";
		}
		if (count($bracketed)) {
			$info .= " (" . join(", ", $bracketed) . ")";
		} elseif ($showOrg) {
			$info .= ", ";
		}
		if ($showOrg) {
			$info .= " <{faction}>{org}<end>";
		}
		$formatOn = sprintf($trackerLayoutOn, $info);
		$formatOff = sprintf($trackerLayoutOff, $info);
		$this->trackerController->trackerLogon = $formatOn;
		$this->trackerController->trackerLogoff = $formatOff;
	}
}
