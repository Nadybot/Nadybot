<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Core\Filesystem;
use Nadybot\Core\Types\AccessLevel;
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
		command: 'gautrade',
		accessLevel: AccessLevel::Guest,
		description: 'Gauntlet tradeskills',
	)
]
class GauntletTradeController extends ModuleInstance {
	#[NCA\Inject]
	private Filesystem $fs;

	/** Show the Bastion trade-skill process for a single piece */
	#[NCA\HandlesCommand('gautrade')]
	public function gautradeCommand(CmdContext $context): void {
		$info = $this->fs->read(__DIR__ . '/gautrade.html');
		$msg = Text::makeBlob('Gauntlet Tradeskills', $info);
		$context->reply($msg);
	}
}
