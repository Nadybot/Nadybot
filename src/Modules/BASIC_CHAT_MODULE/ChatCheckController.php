<?php

namespace Budabot\Modules\BASIC_CHAT_MODULE;

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

	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;

	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;

	const CHANNEL_TYPE = "priv";

	/**
	 * This command handler checks who of the raidgroup is in the area.
	 * @HandlesCommand("check")
	 * @Matches("/^check$/i")
	 */
	public function checkAllCommand($message, $channel, $sender, $sendto, $args) {
		$data = $this->db->query(
			"SELECT name FROM online WHERE added_by = '<myname>' AND channel_type = ?",
			self::CHANNEL_TYPE
		);
		$content = "";
		foreach ($data as $row) {
			$content .= " \\n /assist $row->name";
		}

		$list = $this->text->makeChatcmd("Check Players", "/text AssistAll: $content");
		$msg = $this->text->makeBlob("Check Players In Vicinity", $list);
		$sendto->reply($msg);
	}
}
