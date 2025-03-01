<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\In;

/** Abstract class for success or error */
class Result extends InPackage {
	/** The actual message */
	public string $message;

	/**
	 * @param string          $type    Type of the package
	 * @param null|string     $room    Room in which or for which the message is
	 * @param null|string|int $id      ID of the message we're referring to
	 * @param null|string     $message The actual message (highway 0.1)
	 * @param null|string     $body    The actual message (highway 0.2)
	 */
	public function __construct(
		string $type,
		public ?string $room,
		public null|string|int $id,
		?string $message,
		?string $body,
	) {
		parent::__construct($type);
		$this->message = $message ?? $body ?? '';
	}
}
