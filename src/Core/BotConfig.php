<?php declare(strict_types=1);

namespace Nadybot\Core;

use Spatie\DataTransferObject\DataTransferObject;

class BotConfig extends DataTransferObject {
	public string $login;
	public string $password;
	public string $name;
	public string $my_guild = "";
	public ?int $my_guild_id = null;
	public int $dimension = 5;
	public string $superadmin;

	public string $db_type = DB::SQLITE;
	public string $db_name = "nadybot.db";
	public string $db_host = "127.0.0.1";
	public ?string $db_username = null;
	public ?string $db_password = null;

	public int $show_aoml_markup = 0;

	public string $cachefolder = "./cache/";
	public string $logsfolder = "./logs/";

	public int $default_module_status = 1;
	public int $enable_console_client = 0;
	public int $enable_package_module = 0;

	public int $use_proxy = 0;
	public string $proxy_server = "127.0.0.1";
	public int $proxy_port = 9993;

	/** @var string[] */
	public array $module_load_paths = [
		'./src/Modules',
		'./extras',
	];
	public array $settings = [];
	public string $timezone = "UTC";

	public int $startup=0;

	public function __construct(array $data) {
		$this->startup = time();
		$cleaned = [];
		foreach ($data as $key => $value) {
			$key2 = strtolower(str_replace(" ", "_", $key));
			$cleaned[$key2] = $value;
		}
		parent::__construct($cleaned);
	}
}
