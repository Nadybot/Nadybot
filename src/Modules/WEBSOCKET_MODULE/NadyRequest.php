<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

use Nadybot\Core\JSONDataModel;

class NadyRequest extends JSONDataModel {
	public const READ = 1;
	public const WRITE = 2;
	public const CREATE = 3;

	public string $resource = "/";
	public int $mode = self::READ;
	public ?array $data=null;
}
