<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use Closure;
use Nadybot\Core\{
	CmdContext,
	EventManager,
	Nadybot,
	SettingManager,
	Timer,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'countdown',
 *		accessLevel = 'rl',
 *		description = 'Start a 5-second countdown',
 *		help        = 'countdown.txt',
 *		alias		= 'cd'
 *	)
 *	@ProvidesEvent(value="sync(cd)", desc="Triggered when someone starts a countdown")
 */
class CountdownController {

	public const CONF_CD_TELL_LOCATION = 'cd_tell_location';
	public const CONF_CD_DEFAULT_TEXT = 'cd_default_text';
	public const CONF_CD_COOLDOWN = 'cd_cooldown';

	public const LOC_PRIV = 1;
	public const LOC_ORG = 2;

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public Timer $timer;

	private int $lastCountdown = 0;

	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			self::CONF_CD_TELL_LOCATION,
			'Where to display countdowns received via tells',
			'edit',
			"options",
			"1",
			"Priv;Guild;Priv+Guild",
			"1;2;3"
		);
		$this->settingManager->add(
			$this->moduleName,
			self::CONF_CD_DEFAULT_TEXT,
			'Default text to say at the end of a countdown',
			'edit',
			"text",
			"GO",
		);
		$this->settingManager->add(
			$this->moduleName,
			self::CONF_CD_COOLDOWN,
			"How long is the cooldown between starting 2 countdowns",
			"edit",
			"time",
			"30s",
			"6s;15s;30s;1m;5m",
			'',
			"mod"
		);
	}

	/**
	 * @HandlesCommand("countdown")
	 */
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

	/**
	 * @Event("sync(cd)")
	 * @Description("Process externally started countdowns")
	 */
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
