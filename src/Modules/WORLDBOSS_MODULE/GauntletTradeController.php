<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ModuleInstance,
	Text,
};

/**
 * @author Equi
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "gautrade",
		accessLevel: "all",
		description: "Gauntlet tradeskills",
		help: "gauntlet.txt"
	)
]
class GauntletTradeController extends ModuleInstance {
	#[NCA\Inject]
	public Text $text;

	#[NCA\HandlesCommand("gautrade")]
	public function gautradeCommand(CmdContext $context): void {
		$info = \Safe\file_get_contents(__DIR__ . '/gautrade.html');
		$msg = $this->text->makeBlob("Gauntlet Tradeskills", $info);
		$context->reply($msg);
	}
}
