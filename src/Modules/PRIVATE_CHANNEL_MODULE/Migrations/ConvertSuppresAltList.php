<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Core\DBSchema\Setting;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2022_12_07_15_23_30)]
class ConvertSuppresAltList implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Setting::getTable();
		if (!$db->schema()->hasTable($table)) {
			return;
		}
		$oldValue = $this->getSetting($db, 'priv_suppress_alt_list');
		if (!isset($oldValue)) {
			return;
		}
		$db->table($table)->updateOrInsert(
			['name' => 'priv_join_message'],
			[
				'name' => 'priv_join_message',
				'module' => $oldValue->module,
				'type' => 'text',
				'mode' => $oldValue->mode->value,
				'value' => ($oldValue->value === '1')
					? '{whois} has joined {channel-name}. {alt-of}'
					: '{whois} has joined {channel-name}. {alt-list}',
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
