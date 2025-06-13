<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM\Migrations;

use Nadybot\Core\{
	DB,
	Routing\Source,
	Types\SchemaMigration,
};
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\Route;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2023_01_15_11_26_47)]
class AddMaintainerNotificationRoute implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Route::getTable();

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
