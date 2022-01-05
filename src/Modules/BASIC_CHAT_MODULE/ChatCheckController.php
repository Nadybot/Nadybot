<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\Attributes as NCA;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	CmdContext,
	DB,
	ModuleInstance,
	Text,
};

/**
 * Commands this class contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "check",
		accessLevel: "all",
		description: "Checks who of the raidgroup is in the area",
		help: "check.txt"
	)
]
class ChatCheckController extends ModuleInstance {

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	public const CHANNEL_TYPE = "priv";

	/**
	 * This command handler checks who of the raidgroup is in the area.
	 */
	#[NCA\HandlesCommand("check")]
	public function checkAllCommand(CmdContext $context): void {
		/** @var Collection<string> */
		$data = $this->db->table("online")
			->where("added_by", $this->db->getBotname())
			->where("channel_type", self::CHANNEL_TYPE)
			->select("name")
			->pluckAs("name", "string");
		$content = "";
		if ($data->count() === 0) {
			$msg = "There's no one to check online.";
			$context->reply($msg);
			return;
		}
		foreach ($data as $name) {
			$content .= " \\n /assist {$name}";
		}

		$list = $this->text->makeChatcmd("Check Players", "/text Assisting All: {$content}");
		$msg = $this->text->makeBlob("Check Players In Vicinity", $list);
		$context->reply($msg);
	}
}
