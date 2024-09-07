<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE\Migrations\Base;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2022_12_07_15_23_31)]
class ConvertSuppresAltList implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Setting::getTable();
		if (!$db->schema()->hasTable($table)) {
			return;
		}
		$oldValue = $this->getSetting($db, 'org_suppress_alt_list');
		if (!isset($oldValue)) {
			return;
		}
		$db->table($table)->updateOrInsert(
			['name' => 'org_logon_message'],
			[
				'name' => 'org_logon_message',
				'module' => $oldValue->module,
				'type' => 'text',
				'mode' => $oldValue->mode->value,
				'value' => ($oldValue->value === '1')
					? '{whois} logged on{?main:. {alt-of}}{?logon-msg: - {logon-msg}}'
					: '{whois} logged on{?main:. {alt-list}}{?logon-msg: - {logon-msg}}',
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
		return $db->table(Setting::getTable())
		->where('name', $name)
		->firstObj(Setting::class);
	}
}
