<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

use Spatie\DataTransferObject\FlexibleDataTransferObject;

class BasePacket extends FlexibleDataTransferObject {
	public const MESSAGE = "message";
	public const ONLINE_LIST = "online_list";
	public const ONLINE_LIST_REQUEST = "online_list_request";
	public const USER_STATUS = "user_status";
	public string $type;
}
