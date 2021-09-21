<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

class ApiCache {
	public int $created;
	public int $validUntil;
	public ApiResult $result;

	public function __construct() {
		$this->created = time();
	}
}
