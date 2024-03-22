<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	DB,
	MessageHub,
	Routing\Source,
	SchemaMigration,
};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_230_115_112_647)]
class AddMaintainerNotificationRoute implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = MessageHub::DB_TABLE_ROUTES;

		$route = [
			'source' => Source::SYSTEM . '(maintainer-notification)',
			'destination' => Source::PRIV . '(' . $db->getMyname() . ')',
			'two_way' => false,
		];
		$db->table($table)->insert($route);

		$route = [
			'source' => Source::SYSTEM . '(maintainer-notification)',
			'destination' => Source::ORG,
			'two_way' => false,
		];
		$db->table($table)->insert($route);
	}
}
