<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Text,
	Types\AccessLevel,
};
use Nadybot\Modules\ONLINE_MODULE\Online;

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'check',
		accessLevel: AccessLevel::Guest,
		description: 'Checks who of the raidgroup is in the area',
	)
]
class ChatCheckController extends ModuleInstance {
	public const CHANNEL_TYPE = 'priv';
	#[NCA\Inject]
	private DB $db;

	/** Checks who in the private channel is in the area */
	#[NCA\HandlesCommand('check')]
	public function checkAllCommand(CmdContext $context): void {
		$data = $this->db->table(Online::getTable())
			->where('added_by', $this->db->getBotname())
			->where('channel_type', self::CHANNEL_TYPE)
			->select('name')
			->pluckStrings('name');
		$content = '';
		if ($data->count() === 0) {
			$msg = "There's no one to check online.";
			$context->reply($msg);
			return;
		}
		foreach ($data as $name) {
			$content .= " \\n /assist {$name}";
		}

		$list = Text::makeChatcmd('Check Players', "/text Assisting All: {$content}");
		$msg = Text::makeBlob('Check Players In Vicinity', $list);
		$context->reply($msg);
	}
}
