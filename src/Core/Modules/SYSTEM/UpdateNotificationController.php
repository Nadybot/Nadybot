<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use function Amp\call;

use Amp\Promise;
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
	NCA\EmitsMessages("system", "maintainer-notification")
]
class UpdateNotificationController extends ModuleInstance implements EventFeedHandler {
	#[NCA\Inject]
	public MessageHub $msgHub;

	/**
	 * @param array<string,mixed> $data
	 *
	 * @return Promise<void>
	 */
	public function handleEventFeedMessage(string $room, array $data): Promise {
		return call(function () use ($data): void {
			$mapper = new ObjectMapperUsingReflection();
			$package = $mapper->hydrateObject(UpdateNotification::class, $data);
			$minVersion = $package->minVersion ? new SemanticVersion($package->minVersion) : null;
			$maxVersion = $package->maxVersion ? new SemanticVersion($package->maxVersion) : null;
			$myVersion = new SemanticVersion(BotRunner::getVersion(false));
			if (isset($minVersion) && $minVersion->cmp($myVersion) > 0) {
				return;
			}
			if (isset($maxVersion) && $maxVersion->cmp($myVersion) < 0) {
				return;
			}
			$rMessage = new RoutableMessage(
				"\n".
				"<yellow>" . str_repeat("-", 20) . "[<end> Maintainer Notice <yellow>]" . str_repeat("-", 20) . "\n".
				"<tab>{$package->message}\n".
				"<yellow>" . str_repeat("-", 61) . "<end>"
			);
			$rMessage->prependPath(new Source(Source::SYSTEM, 'maintainer-notification'));
			$this->msgHub->handle($rMessage);
		});
	}
}
