<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use stdClass;

class AOMsg {
	public string $message;
	public stdClass $popups;

	public function __construct(string $message, stdClass $popups=null) {
		$this->message = $message;
		$this->popups = $popups ?? new stdClass();
	}
}
