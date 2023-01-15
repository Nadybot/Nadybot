<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM\Migrations;

use Nadybot\Core\{
	DB,
	LoggerWrapper,
	MessageHub,
	Routing\Source,
	SchemaMigration,
};

class AddMaintainerNotificationRoute implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = MessageHub::DB_TABLE_ROUTES;

		$route = [
			"source" => Source::SYSTEM . "(maintainer-notification)",
			"destination" => Source::PRIV . "(" . $db->getMyname() . ")",
			"two_way" => false,
		];
		$db->table($table)->insert($route);

		$route = [
			"source" => Source::SYSTEM . "(maintainer-notification)",
			"destination" => Source::ORG,
			"two_way" => false,
		];
		$db->table($table)->insert($route);
	}
}
