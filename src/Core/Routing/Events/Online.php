<?php declare(strict_types=1);

namespace Nadybot\Core\Routing\Events;

use Nadybot\Core\Routing\Character;
use Nadybot\Core\StringableTrait;

/**
 * This is a routable online event for a character coming online/offline,
 * or joining/leaving a private channel.
 */
class Online extends Base {
	use StringableTrait;

	public const TYPE = 'online';

	/**
	 * @param null|Character $char       The character
	 * @param null|string    $main       The main of the character, or `null` if unknown
	 * @param bool           $online     Is the character coming online/joining (`true`),
	 *                                   or going offline/leaving (`false`)
	 * @param bool           $renderPath Shell we render the path of this event?
	 * @param null|string    $message    The optional message that comes with the event
	 *                                   ("XXX has left the private channel"), or  `null` if none
	 */
	public function __construct(
		public ?Character $char=null,
		public ?string $main=null,
		public bool $online=true,
		bool $renderPath=false,
		?string $message=null,
	) {
		parent::__construct(
			type: self::TYPE,
			renderPath: $renderPath,
			message: $message,
		);
	}
}
