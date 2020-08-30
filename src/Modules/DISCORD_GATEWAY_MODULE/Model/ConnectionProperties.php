<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Nadybot\Core\JSONDataModel;

class ConnectionProperties extends JSONDataModel {
	public string $os;
	public string $browser = "Nadybot";
	public string $device = "Nadybot";

	public function __construct() {
		$this->os = php_uname("s");
	}
}
