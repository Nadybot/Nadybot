<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use function Amp\call;

use Amp\Promise;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Attributes\HandlesEventFeed;

use Nadybot\Core\{EventFeedHandler, ModuleInstance};

#[
	NCA\Instance,
	HandlesEventFeed('update_notifications')
]
class UpdateNotificationController extends ModuleInstance implements EventFeedHandler {
	/**
	 * @param array<string,mixed> $data
	 *
	 * @return Promise<void>
	 */
	public function handleEventFeedMessage(string $room, array $data): Promise {
		return call(function () {
		});
	}
}
