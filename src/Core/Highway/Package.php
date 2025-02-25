<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

use Nadybot\Core\StringableTrait;
use Stringable;

/** The base class of all highway packages */
abstract class Package implements Stringable {
	use StringableTrait;

	public const HELLO = 'hello';
	public const JOIN = 'join';
	public const LEAVE = 'leave';
	public const MESSAGE = 'message';
	public const ROOM_INFO = 'room_info';
	public const ERROR = 'error';
	public const SUCCESS = 'success';

	/** @param string $type The type of the package */
	public function __construct(
		public string $type,
	) {
	}
}
