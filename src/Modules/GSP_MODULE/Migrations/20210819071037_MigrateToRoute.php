<?php declare(strict_types=1);

namespace Nadybot\Modules\GSP_MODULE\Migrations;

use Nadybot\Core\{
	Attributes as NCA,
	DB,
	DBSchema\Route,
	DBSchema\Setting,
	LoggerWrapper,
	MessageHub,
	Routing\Source,
	SchemaMigration,
	SettingManager,
};
use Nadybot\Modules\GSP_MODULE\GSPController;

class MigrateToRoute implements SchemaMigration {
	#[NCA\Inject]
	public GSPController $gspController;

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$channel = $this->getSetting($db, "gsp_channels");
		if (!isset($channel)) {
			$channel = new Setting();
			$channel->value = "3";
		}
		$map = [
			1 => Source::PRIV . "(" . $db->getMyname() .")",
			2 => Source::ORG,
		];
		foreach ($map as $old => $new) {
			if (((int)$channel->value & $old) === 0) {
				continue;
			}
			$route = new Route();
			$route->source = $this->gspController->getChannelName();
			$route->destination = $new;
			$db->insert(MessageHub::DB_TABLE_ROUTES, $route);
		}
	}
}
