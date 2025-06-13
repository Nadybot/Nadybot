<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE\Migrations\Base;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Core\DBSchema\Setting;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2022_12_01_16_52_15)]
class ConvertFirstAndLastAltOnly implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Setting::getTable();
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
				'mode' => $oldValue->mode->value,
				'value' => ($oldValue->value === '1') ? '900' : '0',
				'options' => '',
				'intoptions' => '',
				'description' => 'Dummy',
				'source' => $oldValue->source,
				'admin' => $oldValue->access_level,
				'verify' => '0',
			],
		);
	}

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(Setting::getTable())
		->where('name', $name)
		->firstObj(Setting::class);
	}
}
