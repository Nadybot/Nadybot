<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\RouteModifier;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_07_27_07_30_09)]
class CreateRouteModifierTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RouteModifier::getTable();
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->id();
			$table->unsignedBigInteger('route_id')->index();
			$table->string('modifier', 100);
		});
	}
}
