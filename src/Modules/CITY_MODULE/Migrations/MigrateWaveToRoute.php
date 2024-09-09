<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE\Migrations;

use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\{
	Attributes as NCA,
	DB,
	DBSchema\Setting,
	Routing\Source,
	Types\SchemaMigration,
	Types\SettingMode,
};
use Nadybot\Modules\CITY_MODULE\CityWaveController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_08_19_07_23_25)]
class MigrateWaveToRoute implements SchemaMigration {
	#[NCA\Inject]
	private CityWaveController $cityWaveController;

	public function migrate(LoggerInterface $logger, DB $db): void {
		$channelSetting = $this->getSetting($db, 'city_wave_announce');
		if (!isset($channelSetting)) {
			$channelSetting = new Setting(name: 'city_wave_announce', mode: SettingMode::Edit, value: 'org');
		}
		$map = [
			'priv' => Source::PRIV . '(' . $db->getMyname() .')',
			'org' => Source::ORG,
		];
		foreach (explode(',', $channelSetting->value??'') as $channel) {
			$new = $map[$channel] ?? null;
			if (!isset($new)) {
				continue;
			}
			$route = [
				'source' => $this->cityWaveController->getChannelName(),
				'destination' => $new,
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
