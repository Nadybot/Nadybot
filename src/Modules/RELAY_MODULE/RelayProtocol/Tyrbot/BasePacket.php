<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

class BasePacket {
	public const string MESSAGE = 'message';
	public const string ONLINE_LIST = 'online_list';
	public const string ONLINE_LIST_REQUEST = 'online_list_request';
	public const string LOGON = 'logon';
	public const string LOGOFF = 'logoff';

	public function __construct(
		public string $type,
	) {
	}
}
