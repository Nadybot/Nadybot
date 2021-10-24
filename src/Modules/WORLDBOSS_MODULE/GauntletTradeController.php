<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Core\CmdContext;
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
 *		help        = 'gauntlet.txt'
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
	 */
	public function gautradeCommand(CmdContext $context): void {
		$info = file_get_contents(__DIR__ . '/gautrade.html');
		$msg = $this->text->makeBlob("Gauntlet Tradeskills", $info);
		$context->reply($msg);
	}
}
