<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, MessageHub, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_210_727_073_017)]
class CreateRouteModifierArgumentTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = MessageHub::DB_TABLE_ROUTE_MODIFIER_ARGUMENT;
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->id();
			$table->unsignedBigInteger('route_modifier_id')->index();
			$table->string('name', 100);
			$table->string('value', 200);
		});
	}
}
