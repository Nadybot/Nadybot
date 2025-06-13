<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Core\DBSchema\RouteModifierArgument;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_07_27_07_30_17)]
class CreateRouteModifierArgumentTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RouteModifierArgument::getTable();
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->id();
			$table->unsignedBigInteger('route_modifier_id')->index();
			$table->string('name', 100);
			$table->string('value', 200);
		});
	}
}
