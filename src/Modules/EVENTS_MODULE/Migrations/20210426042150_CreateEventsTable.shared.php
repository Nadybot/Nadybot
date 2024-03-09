<?php declare(strict_types=1);

namespace Nadybot\Modules\EVENTS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

class CreateEventsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "events";
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, function (Blueprint $table): void {
				$table->id("id")->change();
			});
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->integer("time_submitted");
			$table->string("submitter_name", 25);
			$table->string("event_name", 255);
			$table->integer("event_date")->nullable();
			$table->text("event_desc")->nullable();
			$table->text("event_attendees")->nullable();
		});
	}
}
