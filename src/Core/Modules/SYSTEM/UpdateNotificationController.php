<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use Nadybot\Core\{
	Attributes as NCA,
	BotRunner,
	Hydrator,
	MessageHub,
	ModuleInstance,
	Routing\RoutableMessage,
	Routing\Source,
	SemanticVersion,
	Types\EventFeedHandler,
};

#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\HandlesEventFeed('update_notifications'),
	NCA\EmitsMessages('system', 'maintainer-notification')
]
class UpdateNotificationController extends ModuleInstance implements EventFeedHandler {
	#[NCA\Inject]
	private MessageHub $msgHub;

	/** @inheritDoc */
	public function handleEventFeedMessage(string $room, array $data): void {
		$package = Hydrator::hydrate(UpdateNotification::class, $data);
		$myVersion = new SemanticVersion(BotRunner::getVersion(false));
		if ((isset($package->minVersion) && $package->minVersion->cmp($myVersion) > 0)
			|| (isset($package->maxVersion) && $package->maxVersion->cmp($myVersion) < 0)) {
			return;
		}
		$rMessage = new RoutableMessage(
			"\n".
			'<yellow>' . str_repeat('-', 20) . '[<end> Maintainer Notice <yellow>]' . str_repeat('-', 20) . "\n".
			"<tab>{$package->message}\n".
			'<yellow>' . str_repeat('-', 61) . '<end>'
		);
		$rMessage->prependPath(new Source(Source::SYSTEM, 'maintainer-notification'));
		$this->msgHub->handle($rMessage);
	}
}
