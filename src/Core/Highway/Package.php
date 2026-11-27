<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

use Nadybot\Core\StringableTrait;
use Stringable;

/** The base class of all highway packages */
abstract class Package implements Stringable {
	use StringableTrait;

	public const string HELLO = 'hello';
	public const string JOIN = 'join';
	public const string LEAVE = 'leave';
	public const string MESSAGE = 'message';
	public const string ROOM_INFO = 'room_info';
	public const string ERROR = 'error';
	public const string SUCCESS = 'success';

	/** @param string $type The type of the package */
	public function __construct(
		public string $type,
	) {
	}
}
