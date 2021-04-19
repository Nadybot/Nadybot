<?php declare(strict_types=1);

namespace Nadybot;

class CreateMigration {
	public function showSyntax(string $me): string {
		echo "Syntax: $me <path to migrations> <name of migration class>\n";
		exit(0);
	}

	public function createMigration(string $path, string $migration): void {
		if (!file_exists($path)) {
			mkdir($path);
		}
		$className = basename($migration, ".shared");
		$namespace = "Unknown";
		if (preg_match("/Core\/Modules\/(.+)$/", $path, $matches)) {
			$namespace = "Nadybot\\Core\\Modules\\" . rtrim(str_replace("/", "\\", $matches[1]), "/");
		} elseif (preg_match("/Core\/Migrations/", $path)) {
			$namespace = "Nadybot\\Core\\Migrations";
		} elseif (preg_match("/src\/Modules\/(.+)$/", $path, $matches)) {
			$namespace = "Nadybot\\Modules\\" . rtrim(str_replace("/", "\\", $matches[1]), "/");
		} elseif (preg_match("/extras\/(.+)$/", $path, $matches)) {
			$namespace = "Nadybot\\User\\Modules\\" . rtrim(str_replace("/", "\\", $matches[1]), "/");
		}
		$fileName = sprintf(
			"%s/%d_%s.php",
			$path,
			date("YmdHis"),
			$migration
		);
		$data = 
			"<?php declare(strict_types=1);\n".
			"\n".
			"namespace {$namespace};\n".
			"\n".
			"use Illuminate\\Database\\Schema\\Blueprint;\n".
			"use Nadybot\\Core\\DB;\n".
			"use Nadybot\\Core\\LoggerWrapper;\n".
			"use Nadybot\\Core\\SchemaMigration;\n".
			"\n".
			"class {$className} implements SchemaMigration {\n".
			"	public function migrate(LoggerWrapper \$logger, DB \$db): void {\n".
			"		\$table = \"\";\n".
			"		\$db->schema()->table(\$table, function(Blueprint \$table) {\n".
			"		});\n".
			"	}\n".
			"}\n";

		if (file_put_contents($fileName, $data)) {
			echo "Migration {$fileName} created.\n";
			exit(0);
		}
		echo "Error creating {$fileName}\n";
		exit(1);
	}

	public function run(string $me, string ...$argv): void {
		if (count($argv) < 2) {
			$this->showSyntax($me);
		}
		$this->createMigration($argv[0], $argv[1]);
	}
}

$app = new CreateMigration();
$app->run(...$argv);
