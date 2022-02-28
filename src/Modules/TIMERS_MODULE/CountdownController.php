<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use Closure;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	EventManager,
	ModuleInstance,
	Nadybot,
	SettingManager,
	Timer,
};

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
	public const CONF_CD_TELL_LOCATION = 'cd_tell_location';
	public const CONF_CD_DEFAULT_TEXT = 'cd_default_text';
	public const CONF_CD_COOLDOWN = 'cd_cooldown';

	public const LOC_PRIV = 1;
	public const LOC_ORG = 2;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public Timer $timer;

	private int $lastCountdown = 0;

	#[NCA\Setup]
	public function setup(): void {
		$this->settingManager->add(
			module: $this->moduleName,
			name: self::CONF_CD_TELL_LOCATION,
			description: 'Where to display countdowns received via tells',
			mode: 'edit',
			type: "options",
			value: "1",
			options: [
				'Priv' => 1,
				'Guild' => 2,
				'Priv+Guild' => 3,
			]
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: self::CONF_CD_DEFAULT_TEXT,
			description: 'Default text to say at the end of a countdown',
			mode: 'edit',
			type: "text",
			value: "GO",
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: self::CONF_CD_COOLDOWN,
			description: "How long is the cooldown between starting 2 countdowns",
			mode: "edit",
			type: "time",
			value: "30s",
			options: ["6s", "15s", "30s", "1m", "5m"],
		);
	}

	/** Start a 5s countdown timer with an optional custom message */
	#[NCA\HandlesCommand("countdown")]
	#[NCA\Help\Epilogue(
		"By default, this command can only be run once every 30s"
	)]
	public function countdownCommand(CmdContext $context, ?string $message): void {
		$message ??= $this->settingManager->getString(self::CONF_CD_DEFAULT_TEXT)??"GO";
		$cooldown = $this->settingManager->getInt(self::CONF_CD_COOLDOWN)??30;

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

	protected function getDmCallback(): Closure {
		return function(string $text): void {
			if (($this->settingManager->getInt(self::CONF_CD_TELL_LOCATION)??1) & self::LOC_PRIV) {
				$this->chatBot->sendPrivate($text, true);
			}
			if (($this->settingManager->getInt(self::CONF_CD_TELL_LOCATION)??1) & self::LOC_ORG) {
				$this->chatBot->sendGuild($text, true);
			}
		};
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
			$this->timer->callLater(6-$i, $callback, $msg);
		}

		$msg = "[<green>------&gt; {$message} &lt;-------<end>]";
		$this->timer->callLater(6, $callback, $msg);
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
}
