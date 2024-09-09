<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\{
	DB,
	DBSchema\Setting,
	Routing\Source,
	Types\SchemaMigration,
};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2022_04_03_07_27_12)]
class MigrateVoiceStateToRoutes implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$setting = $this->getSetting($db, 'discord_notify_voice_changes');
		if (!isset($setting) || !isset($setting->value)) {
			return;
		}
		if ((int)$setting->value & 1) {
			$route = [
				'source' => Source::DISCORD_PRIV . '(< *)',
				'destination' => Source::PRIV . '(' . $db->getBotname() . ')',
				'two_way' => false,
			];
			$db->table(Route::getTable())->insert($route);
		}
		if ((int)$setting->value & 2) {
			$route = [
				'source' => Source::DISCORD_PRIV . '(< *)',
				'destination' => Source::ORG,
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
