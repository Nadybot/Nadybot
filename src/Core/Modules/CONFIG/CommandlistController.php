<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\Attributes as NCA;
use Exception;
use Nadybot\Core\{
	AccessManager,
	CmdContext,
	CommandManager,
	DB,
	Instance,
	Text,
};
use Nadybot\Core\DBSchema\CommandListEntry;

/**
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "cmdlist",
		accessLevel: "guild",
		description: "Shows a list of all commands on the bot",
		help: "cmdlist.txt",
		defaultStatus: 1
	)
]
class CommandlistController extends Instance {

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public DB $db;

	#[NCA\HandlesCommand("cmdlist")]
	public function cmdlistCommand(CmdContext $context, ?string $al): void {
		$query = $this->db->table(CommandManager::DB_TABLE, "c")
			->whereIn("c.cmdevent", ["cmd", "subcmd"])
			->groupBy("c.cmd", "c.cmdevent", "c.description", "c.module", "file", "dependson")
			->orderBy("cmd");
		if (isset($al)) {
			try {
				$query->whereIlike("c.admin", $this->accessManager->getAccessLevel($al));
			} catch (Exception $e) {
				$context->reply($e->getMessage());
				return;
			}
		}
		$subs = [];
		$i = 1;
		foreach (["guild", "priv", "msg"] as $type) {
			$subs []= $this->db->table(CommandManager::DB_TABLE, "t{$i}")
				->whereColumn("t{$i}.cmd", "=", "c.cmd")
				->where("t{$i}.type", $type)
				->select($query->rawFunc("COUNT", "*"));
			$i++;
			$subs []= $this->db->table(CommandManager::DB_TABLE, "t{$i}")
				->whereColumn("t{$i}.cmd", "=", "c.cmd")
				->where("t{$i}.type", $type)
				->where("t{$i}.status", 1)
				->select($query->rawFunc("COUNT", "*"));
		}
		$query
			->select([
				"cmd",
				"cmdevent",
				"description",
				"module",
				"file",
				"dependson",
			])
			->selectSub($subs[0], "guild_avail")
			->selectSub($subs[1], "guild_status")
			->selectSub($subs[2], "priv_avail")
			->selectSub($subs[3], "priv_status")
			->selectSub($subs[4], "msg_avail")
			->selectSub($subs[5], "msg_status");
		/** @var CommandListEntry[] $data */
		$data = $query->asObj(CommandListEntry::class)->toArray();
		$count = count($data);

		if ($count === 0) {
			$msg = "No commands were found.";
			$context->reply($msg);
			return;
		}
		$blob = '';
		foreach ($data as $row) {
			if ($row->cmdevent == 'subcmd') {
				$cmd = $row->dependson;
			} else {
				$cmd = $row->cmd;
			}

			$links = "";
			if ($this->accessManager->checkAccess($context->char->name, 'moderator')) {
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
		$context->reply($msg);
	}
}
