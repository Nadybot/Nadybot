<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

use Spatie\DataTransferObject\DataTransferObject;

class BasePacket extends DataTransferObject {
	public const MESSAGE = "message";
	public const ONLINE_LIST = "online_list";
	public const ONLINE_LIST_REQUEST = "online_list_request";
	public const LOGON = "logon";
	public const LOGOFF = "logoff";
	public string $type;
}
