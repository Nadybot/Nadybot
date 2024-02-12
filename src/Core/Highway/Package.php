<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

use function Safe\json_encode;
use EventSauce\ObjectHydrator\DoNotSerialize;

use Nadybot\Core\Loggable;

class Package implements Loggable {
	public const HELLO = "hello";
	public const JOIN = "join";
	public const LEAVE = "leave";
	public const MESSAGE = "message";
	public const ROOM_INFO = "room_info";
	public const ERROR = "error";
	public const SUCCESS = "success";

	public string $type;

	/** Get a human-readable dump of the object and its values */
	#[DoNotSerialize]
	public function toLog(): string {
		$values = [];
		foreach (get_object_vars($this) as $key => $value) {
			$values []= "{$key}=" . json_encode(
				$value,
				JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE
			);
		}
		return "<" . substr(get_class($this), strlen(__NAMESPACE__) + 1) . ">{" . join(",", $values) . "}";
	}
}
