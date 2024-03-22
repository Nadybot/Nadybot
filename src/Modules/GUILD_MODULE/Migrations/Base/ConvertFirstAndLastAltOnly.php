<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE\Migrations\Base;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\{DB, SchemaMigration, SettingManager};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_221_201_165_215)]
class ConvertFirstAndLastAltOnly implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = SettingManager::DB_TABLE;
		if (!$db->schema()->hasTable($table)) {
			return;
		}
		$oldValue = $this->getSetting($db, 'first_and_last_alt_only');
		if (!isset($oldValue)) {
			return;
		}
		$db->table($table)->updateOrInsert(
			['name' => 'suppress_logon_logoff'],
			[
				'name' => 'suppress_logon_logoff',
				'module' => $oldValue->module,
				'type' => 'time_or_off',
				'mode' => $oldValue->mode,
				'value' => ($oldValue->value === '1') ? '900' : '0',
				'options' => '',
				'intoptions' => '',
				'description' => 'Dummy',
				'source' => $oldValue->source,
				'admin' => $oldValue->admin,
				'verify' => '0',
			],
		);
	}

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
		->where('name', $name)
		->asObj(Setting::class)
		->first();
	}
}
