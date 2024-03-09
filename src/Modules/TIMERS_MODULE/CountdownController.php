<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use Closure;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	EventManager,
	ModuleInstance,
	Nadybot,
};
use Revolt\EventLoop;

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "countdown",
		accessLevel: "rl",
		description: "Start a 5-second countdown",
		alias: "cd"
	),
	NCA\ProvidesEvent(
		event: "sync(cd)",
		desc: "Triggered when someone starts a countdown",
	)
]
class CountdownController extends ModuleInstance {
	public const LOC_PRIV = 1;
	public const LOC_ORG = 2;

	/** Where to display countdowns received via tells */
	#[NCA\Setting\Options(options: [
		'Private channel' => self::LOC_PRIV,
		'Org channel' => self::LOC_ORG,
		'Private and Org channel' => self::LOC_PRIV|self::LOC_ORG,
	])]
	public int $cdTellLocation = self::LOC_PRIV;

	/** Default text to say at the end of a countdown */
	#[NCA\Setting\Text]
	public string $cdDefaultText = "GO";

	/** How long is the cooldown between starting 2 countdowns */
	#[NCA\Setting\Time(options: ["6s", "15s", "30s", "1m", "5m"])]
	public int $cdCooldown = 30;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private EventManager $eventManager;

	private int $lastCountdown = 0;

	/** Start a 5s countdown timer with an optional custom message */
	#[NCA\HandlesCommand("countdown")]
	#[NCA\Help\Epilogue(
		"By default, this command can only be run once every 30s"
	)]
	public function countdownCommand(CmdContext $context, ?string $message): void {
		$message ??= $this->cdDefaultText;
		$cooldown = $this->cdCooldown;

		if ($this->lastCountdown >= (time() - $cooldown)) {
			$msg = "You can only start a countdown once every {$cooldown} seconds.";
			$context->reply($msg);
			return;
		}

		$callback = [$context, "reply"];
		if ($context->isDM()) {
			$callback = $this->getDmCallback();
		}
		$this->startCountdown($callback, $message);
		$sEvent = new SyncCdEvent();
		$sEvent->owner = $context->char->name;
		$sEvent->message = $message;
		$sEvent->forceSync = $context->forceSync;
		$this->eventManager->fireEvent($sEvent);
	}

	/** @psalm-param callable(string) $callback */
	public function startCountdown(callable $callback, string $message): void {
		$this->lastCountdown = time();

		for ($i = 5; $i > 0; $i--) {
			if ($i > 3) {
				$color = "<red>";
			} elseif ($i > 1) {
				$color = "<orange>";
			} else {
				$color = "<yellow>";
			}
			$msg = "[{$color}-------&gt; {$i} &lt;-------<end>]";
			EventLoop::delay((6-$i), function (string $token) use ($callback, $msg): void {
				$callback($msg);
			});
		}

		$msg = "[<green>------&gt; {$message} &lt;-------<end>]";
		EventLoop::delay(6, function (string $token) use ($callback, $msg): void {
			$callback($msg);
		});
	}

	#[NCA\Event(
		name: "sync(cd)",
		description: "Process externally started countdowns"
	)]
	public function syncCountdown(SyncCdEvent $event): void {
		if (time() - $this->lastCountdown < 7) {
			return;
		}
		if ($event->isLocal()) {
			return;
		}
		$callback = $this->getDmCallback();
		$this->startCountdown($callback, $event->message);
	}

	protected function getDmCallback(): Closure {
		return function (string $text): void {
			if ($this->cdTellLocation & self::LOC_PRIV) {
				$this->chatBot->sendPrivate($text, true);
			}
			if ($this->cdTellLocation & self::LOC_ORG) {
				$this->chatBot->sendGuild($text, true);
			}
		};
	}
}
