<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\{
	Text,
	DB,
	CommandReply,
};
use Nadybot\Core\DBSchema\EventCfg;

/**
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command       = 'eventlist',
 *		accessLevel   = 'guild',
 *		description   = 'Shows a list of all events on the bot',
 *		help          = 'eventlist.txt',
 *		defaultStatus = '1'
 *	)
 */
class EventlistController {

	/** @Inject */
	public Text $text;

	/** @Inject */
	public DB $db;

	/**
	 * This command handler shows a list of all events on the bot.
	 * Additionally, event type can be provided to show only events of that type.
	 *
	 * @HandlesCommand("eventlist")
	 * @Matches("/^eventlist$/i")
	 * @Matches("/^eventlist (.+)$/i")
	 */
	public function eventlistCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$params = [];
		if (count($args) > 1) {
			$params []= "%" . $args[1] . "%";
			$cmdSearchSql = "WHERE type LIKE ?";
		}
	
		$sql = "SELECT ".
				"type, ".
				"description, ".
				"module, ".
				"file, ".
				"status ".
			"FROM ".
				"eventcfg_<myname> ".
			"$cmdSearchSql ".
			"ORDER BY ".
				"type ASC, ".
				"module ASC";
		/** @var EventCfg[] $data */
		$data = $this->db->fetchAll(EventCfg::class, $sql, ...$params);
		$count = count($data);
	
		if ($count === 0) {
			$msg = "No events of type <highlight>{$args[1]}<end> found.";
			$sendto->reply($msg);
			return;
		}
		$blob = '';
		$lastType = '';
		foreach ($data as $row) {
			$on = $this->text->makeChatcmd('ON', "/tell <myname> config event $row->type $row->file enable all");
			$off = $this->text->makeChatcmd('OFF', "/tell <myname> config event $row->type $row->file disable all");

			if ($row->status === 1) {
				$status = "<green>Enabled<end>";
			} else {
				$status = "<red>Disabled<end>";
			}

			if ($lastType !== $row->type) {
				$blob .= "<pagebreak>\n<header2>{$row->type}<end>\n";
				$lastType = $row->type;
			}
			$blob .= "<tab>$on  $off  $row->module ($status)";
			if ($row->description !== null && $row->description !== '') {
				$blob .= " - $row->description\n";
			}
		}
	
		$msg = $this->text->makeBlob("Event List ($count)", $blob);
		$sendto->reply($msg);
	}
}
