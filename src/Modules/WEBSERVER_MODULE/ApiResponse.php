<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

class ApiResponse extends Response {
	public function __construct($args=null) {
		if (isset($args)) {
			$this->body = JsonExporter::encode($args);
		}
		$this->setCode(static::OK);
	}
}
