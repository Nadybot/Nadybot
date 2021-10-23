<?php

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Core\Text;

/**
 * @author Equi
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'gautrade',
 *		accessLevel = 'all',
 *		description = 'Gauntlet tradeskills',
 *		help        = 'gautimer.txt'
 *	)
 */
class GauntletController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public GauntletBuffController $gauntletBuffController;

	/** @Inject */
	public WorldBossController $worldBossController;

	/**
	 * @HandlesCommand("gautrade")
	 * @Matches("/^gautrade$/i")
	 */
	public function gautradeCommand($message, $channel, $sender, $sendto, $args) {
		$info = file_get_contents(__DIR__ . '/gautrade');
		$msg = $this->text->makeBlob("Gauntlet Tradeskills", $info);
		$sendto->reply($msg);
	}

	/**
	 * @NewsTile("gauntlet")
	 * @Description("Show spawn status of Vizaresh spawns and the
	 * status of the currently popped Gauntlet buff")
	 */
	public function gauntletNewsTile(string $sender, callable $callback): void {
		$timer = $this->worldBossController->getWorldBossTimer(WorldBossController::VIZARESH);
		if (!isset($timer)) {
			$timerLine = null;
		} else {
			$timerLine = "<tab>" . $this->worldBossController->formatWorldBossMessage($timer, true);
		}
		$buffLine = $this->gauntletBuffController->getGauntletBuffLine();
		if (!isset($timerLine) && !isset($buffLine)) {
			$callback(null);
			return;
		}
		$blob = "<header2>Gauntlet<end>\n".
			($buffLine??"").
			($timerLine??"");
		$callback($blob);
	}
}
