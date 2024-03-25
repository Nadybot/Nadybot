<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Auctions;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	DB,
	MessageHub,
	Routing\Source,
	SchemaMigration,
};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2022_03_29_16_01_23)]
class MigrateToRoutes implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$route = [
			'source' => 'auction(*)',
			'destination' => Source::PRIV . '(' . $db->getMyname() . ')',
			'two_way' => false,
		];
		$db->table(MessageHub::DB_TABLE_ROUTES)->insert($route);

		$format = [
			'render' => false,
			'hop' => 'auction',
			'format' => '%s',
		];
		$db->table(Source::DB_TABLE)->insert($format);
	}
}
