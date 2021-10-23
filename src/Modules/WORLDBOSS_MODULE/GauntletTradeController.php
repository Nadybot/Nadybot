<?php declare(strict_types=1);

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
class GauntletTradeController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Text $text;

	/**
	 * @HandlesCommand("gautrade")
	 * @Matches("/^gautrade$/i")
	 */
	public function gautradeCommand($message, $channel, $sender, $sendto, $args) {
		$info = file_get_contents(__DIR__ . '/gautrade');
		$msg = $this->text->makeBlob("Gauntlet Tradeskills", $info);
		$sendto->reply($msg);
	}
}
