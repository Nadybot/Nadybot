<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Text,
};

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'check',
		accessLevel: 'guest',
		description: 'Checks who of the raidgroup is in the area',
	)
]
class ChatCheckController extends ModuleInstance {
	public const CHANNEL_TYPE = 'priv';
	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Text $text;

	/** Checks who in the private channel is in the area */
	#[NCA\HandlesCommand('check')]
	public function checkAllCommand(CmdContext $context): void {
		/** @var Collection<string> */
		$data = $this->db->table('online')
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

		$list = $this->text->makeChatcmd('Check Players', "/text Assisting All: {$content}");
		$msg = $this->text->makeBlob('Check Players In Vicinity', $list);
		$context->reply($msg);
	}
}
