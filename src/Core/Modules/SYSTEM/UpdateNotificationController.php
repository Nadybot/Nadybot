<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use EventSauce\ObjectHydrator\ObjectMapperUsingReflection;
use Nadybot\Core\{
	Attributes as NCA,
	BotRunner,
	EventFeedHandler,
	MessageHub,
	ModuleInstance,
	Routing\RoutableMessage,
	Routing\Source,
	SemanticVersion,
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

	/** @param array<string,mixed> $data */
	public function handleEventFeedMessage(string $room, array $data): void {
		$mapper = new ObjectMapperUsingReflection();
		$package = $mapper->hydrateObject(UpdateNotification::class, $data);
		$myVersion = new SemanticVersion(BotRunner::getVersion(false));
		if (($package->minVersion?->cmp($myVersion) > 0)
			|| ($package->maxVersion?->cmp($myVersion) < 0)) {
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
