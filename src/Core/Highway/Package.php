<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

class Package {
	public const HELLO = "hello";
	public const JOIN = "join";
	public const LEAVE = "leave";
	public const MESSAGE = "message";
	public const ROOM_INFO = "room-info";
	public const ERROR = "error";
	public const SUCCESS = "success";

	public function __construct(
		public string $type,
		public ?int $id=null,
	) {
	}
}
