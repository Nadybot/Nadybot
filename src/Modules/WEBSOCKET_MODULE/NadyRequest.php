<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

class NadyRequest {
	public const int READ = 1;
	public const int WRITE = 2;
	public const int CREATE = 3;

	/** @param ?mixed[] $data */
	public function __construct(
		public string $resource='/',
		public int $mode=self::READ,
		public ?array $data=null,
	) {
	}
}
