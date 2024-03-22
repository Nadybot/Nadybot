<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Nadybot\Core\{
	Attributes as NCA,
	DB,
	DBSchema\Setting,
	MessageHub,
	Routing\Source,
	SchemaMigration,
	SettingManager,
};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_210_828_081_307)]
class ConvertCmdMgrErrorToMsg implements SchemaMigration {
	#[NCA\Inject]
	private MessageHub $messageHub;

	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = $this->messageHub::DB_TABLE_ROUTES;
		$errToOrg = $this->getSetting($db, 'access_denied_notify_guild');
		$errToPriv = $this->getSetting($db, 'access_denied_notify_priv');
		$toOrg = isset($errToOrg) ? ($errToOrg->value === '1') : true;
		$toPriv = isset($errToPriv) ? ($errToPriv->value === '1') : true;

		$botName = $db->getMyname();
		if ($toOrg) {
			$route = [
				'source' => Source::SYSTEM . '(access-denied)',
				'destination' => Source::ORG,
				'two_way' => false,
			];
			$db->table($table)->insert($route);
		}
		if ($toPriv) {
			$route = [
				'source' => Source::SYSTEM . '(access-denied)',
				'destination' => Source::PRIV . "({$botName})",
				'two_way' => false,
			];
			$db->table($table)->insert($route);
		}
	}

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where('name', $name)
			->asObj(Setting::class)
			->first();
	}
}
