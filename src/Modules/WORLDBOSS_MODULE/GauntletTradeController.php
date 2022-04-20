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
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "gautrade",
		accessLevel: "guest",
		description: "Gauntlet tradeskills",
	)
]
class GauntletTradeController extends ModuleInstance {
	#[NCA\Inject]
	public Text $text;

	/** Show the Bastion tradeskill process for a single piece */
	#[NCA\HandlesCommand("gautrade")]
	public function gautradeCommand(CmdContext $context): void {
		$info = \Safe\file_get_contents(__DIR__ . '/gautrade.html');
		$msg = $this->text->makeBlob("Gauntlet Tradeskills", $info);
		$context->reply($msg);
	}
}
