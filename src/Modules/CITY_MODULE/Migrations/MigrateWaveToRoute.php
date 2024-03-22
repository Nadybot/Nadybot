<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE\Migrations;

use Nadybot\Core\{
	Attributes as NCA,
	DB,
	DBSchema\Setting,
	MessageHub,
	Routing\Source,
	SchemaMigration,
	SettingManager,
};
use Nadybot\Modules\CITY_MODULE\CityWaveController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_210_819_072_325)]
class MigrateWaveToRoute implements SchemaMigration {
	#[NCA\Inject]
	private CityWaveController $cityWaveController;

	public function migrate(LoggerInterface $logger, DB $db): void {
		$channel = $this->getSetting($db, 'city_wave_announce');
		if (!isset($channel)) {
			$channel = new Setting();
			$channel->value = 'org';
		}
		$map = [
			'priv' => Source::PRIV . '(' . $db->getMyname() .')',
			'org' => Source::ORG,
		];
		foreach (explode(',', $channel->value??'') as $channel) {
			$new = $map[$channel] ?? null;
			if (!isset($new)) {
				continue;
			}
			$route = [
				'source' => $this->cityWaveController->getChannelName(),
				'destination' => $new,
				'two_way' => false,
			];
			$db->table(MessageHub::DB_TABLE_ROUTES)->insert($route);
		}
	}

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where('name', $name)
			->asObj(Setting::class)
			->first();
	}
}
