<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	Text,
};

/**
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'check',
 *		accessLevel = 'all',
 *		description = 'Checks who of the raidgroup is in the area',
 *      help        = 'check.txt'
 *	)
 */
class ChatCheckController {

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	public const CHANNEL_TYPE = "priv";

	/**
	 * This command handler checks who of the raidgroup is in the area.
	 * @HandlesCommand("check")
	 * @Matches("/^check$/i")
	 */
	public function checkAllCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args) {
		/** @var DBRow[] */
		$data = $this->db->query(
			"SELECT name FROM online ".
			"WHERE added_by = '<myname>' AND channel_type = ?",
			self::CHANNEL_TYPE
		);
		$content = "";
		if (count($data) === 0) {
			$msg = "There's no one to check online.";
			$sendto->reply($msg);
			return;
		}
		foreach ($data as $row) {
			$content .= " \\n /assist $row->name";
		}

		$list = $this->text->makeChatcmd("Check Players", "/text Assisting All: $content");
		$msg = $this->text->makeBlob("Check Players In Vicinity", $list);
		$sendto->reply($msg);
	}
}
