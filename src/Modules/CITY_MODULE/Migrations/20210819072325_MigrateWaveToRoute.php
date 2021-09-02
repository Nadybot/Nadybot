<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE\Migrations;

use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SchemaMigration;
use Nadybot\Core\SettingManager;
use Nadybot\Modules\CITY_MODULE\CityWaveController;

class MigrateWaveToRoute implements SchemaMigration {
	/** @Inject */
	public CityWaveController $cityWaveController;

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$channel = $this->getSetting($db, "city_wave_announce");
		if (!isset($channel)) {
			$channel = new Setting();
			$channel->value = "org";
		}
		$map = [
			"priv" => Source::PRIV . "(" . $db->getMyname() .")",
			"org" => Source::ORG,
		];
		foreach (explode(",", $channel->value) as $channel) {
			$new = $map[$channel] ?? null;
			if (!isset($new)) {
				continue;
			}
			$route = new Route();
			$route->source = $this->cityWaveController->getChannelName();
			$route->destination = $new;
			$db->insert(MessageHub::DB_TABLE_ROUTES, $route);
		}
	}
}
