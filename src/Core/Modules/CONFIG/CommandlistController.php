<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\{
	AccessManager,
	DB,
	CommandReply,
	Text,
};
use Nadybot\Core\DBSchema\CommandListEntry;

/**
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command       = 'cmdlist',
 *		accessLevel   = 'guild',
 *		description   = 'Shows a list of all commands on the bot',
 *		help          = 'cmdlist.txt',
 *		defaultStatus = '1'
 *	)
 */
class CommandlistController {

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public Text $text;

	/**
	 * @Inject
	 */
	public DB $db;

	/**
	 * @HandlesCommand("cmdlist")
	 * @Matches("/^cmdlist$/i")
	 * @Matches("/^cmdlist (.+)$/i")
	 */
	public function cmdlistCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$params = [];
		if (count($args)> 1) {
			$params []= $this->accessManager->getAccessLevel($args[1]);
			$cmdSearchSql = "AND c.admin LIKE ?";
		}
	
		$sql = "SELECT ".
				"cmd, ".
				"cmdevent, ".
				"description, ".
				"module, ".
				"file, ".
				"admin, ".
				"dependson, ".
				"(SELECT count(*) FROM cmdcfg_<myname> t1 WHERE t1.cmd = c.cmd AND t1.type = 'guild') guild_avail, ".
				"(SELECT count(*) FROM cmdcfg_<myname> t2 WHERE t2.cmd = c.cmd AND t2.type = 'guild' AND t2.status = 1) guild_status, ".
				"(SELECT count(*) FROM cmdcfg_<myname> t3 WHERE t3.cmd = c.cmd AND t3.type ='priv') priv_avail, ".
				"(SELECT count(*) FROM cmdcfg_<myname> t4 WHERE t4.cmd = c.cmd AND t4.type = 'priv' AND t4.status = 1) priv_status, ".
				"(SELECT count(*) FROM cmdcfg_<myname> t5 WHERE t5.cmd = c.cmd AND t5.type ='msg') msg_avail, ".
				"(SELECT count(*) FROM cmdcfg_<myname> t6 WHERE t6.cmd = c.cmd AND t6.type = 'msg' AND t6.status = 1) msg_status ".
			"FROM ".
				"cmdcfg_<myname> c ".
			"WHERE ".
				"(c.cmdevent = 'cmd'	OR c.cmdevent = 'subcmd') ".
				"$cmdSearchSql ".
			"GROUP BY ".
				"c.cmd, c.description, c.module ".
			"ORDER BY ".
				"cmd ASC";
		/** @var CommandListEntry[] $data */
		$data = $this->db->fetchAll(CommandListEntry::class, $sql, ...$params);
		$count = count($data);
	
		if ($count === 0) {
			$msg = "No commands were found.";
			$sendto->reply($msg);
			return;
		}
		$blob = '';
		foreach ($data as $row) {
			if ($row->cmdevent == 'subcmd') {
				$cmd = $row->dependson;
			} else {
				$cmd = $row->cmd;
			}
		
			if ($this->accessManager->checkAccess($sender, 'moderator')) {
				$onLink = "<black>ON<end>";
				if ($row->guild_status === 0 || $row->msg_status === 0 || $row->priv_status === 0) {
					$onLink = $this->text->makeChatcmd('ON', "/tell <myname> config cmd $cmd enable all");
				}
				$offLink = "<black>OFF<end>";
				if ($row->guild_status !== 0 || $row->msg_status !== 0 || $row->priv_status !== 0) {
					$offLink = $this->text->makeChatcmd('OFF', "/tell <myname> config cmd $cmd disable all");
				}
				$rightsLink = $this->text->makeChatcmd('RIGHTS', "/tell <myname> config cmd $cmd");
				$links = "$rightsLink  $onLink  $offLink";
			}
		
			$tell = "<red>T<end>";
			if ($row->msg_avail === 0) {
				$tell = "_";
			} elseif ($row->msg_status === 1) {
				$tell = "<green>T<end>";
			}
		
			$guild = "<red>G<end>";
			if ($row->guild_avail === 0) {
				$guild = "_";
			} elseif ($row->guild_status === 1) {
				$guild = "<green>G<end>";
			}
		
			$priv = "<red>P<end>";
			if ($row->priv_avail === 0) {
				$priv = "_";
			} elseif ($row->priv_status === 1) {
				$priv = "<green>P<end>";
			}
		
			$blob .= "{$links}  [{$tell}|{$guild}|{$priv}] <highlight>{$row->cmd}<end>: {$row->description}\n";
		}
		
		$msg = $this->text->makeBlob("Command List ($count)", $blob);
		$sendto->reply($msg);
	}
}
