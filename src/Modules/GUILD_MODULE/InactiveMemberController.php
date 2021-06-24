<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 * @author Mindrila (RK1)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = "inactivemem",
 *		accessLevel = "guild",
 *		description = "Check for inactive members",
 *		help        = "inactivemem.txt"
 *	)
 */
class InactiveMemberController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/**
	 * @HandlesCommand("inactivemem")
	 * @Matches("/^inactivemem ([a-z0-9]+)/i")
	 */
	public function inactivememCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$time = $this->util->parseTime($args[1]);
		if ($time < 1) {
			$msg = "You must enter a valid time parameter.";
			$sendto->reply($msg);
			return;
		}

		$timeString = $this->util->unixtimeToReadable($time, false);
		$time = time() - $time;

		$data = $this->db->table(GuildController::DB_TABLE, "o")
			->leftJoin("alts AS a", "o.name", "a.alt")
			->where("mode", "!=", "del")
			->where("logged_off", "<", $time)
			->orderBy("o.name")
			->asObj()->toArray();

		if (count($data) === 0) {
			$sendto->reply("There are no members in the org roster.");
			return;
		}

		$numInactive = 0;
		$highlight = false;

		$blob = "Org members who have not logged off since <highlight>{$timeString}<end> ago.\n\n";

		foreach ($data as $row) {
			$logged = 0;
			$main = $row->main;
			if ($row->main !== null) {
				$data1 = $this->db->table("alts AS a")
					->join(GuildController::DB_TABLE . " AS o", "a.alt", "o.name")
					->where("a.main", $row->main)
					->asObj();
				foreach ($data1 as $row1) {
					if ($row1->logged_off > $time) {
						continue 2;
					}

					if ($row1->logged_off > $logged) {
						$logged = $row1->logged_off;
						$lasttoon = $row1->name;
					}
				}
			}

			$numInactive++;
			$alts = $this->text->makeChatcmd("Alts", "/tell <myname> alts {$row->name}");
			$logged = $row->logged_off;
			$lasttoon = $row->name;
			$lastseen = ($row->logged_off == 0) ? "never" : $this->util->date($logged);

			$player = "<pagebreak>" . $row->name;
			if (isset($main)) {
				$player .= "; Main: $main";
			}
			$player .= " [{$alts}]\n";
			if ($lastseen !== "never") {
				$player .= "Last seen on [$lasttoon] on {$lastseen}\n";
			} else {
				$player .= "Never seen\n";
			}
			$player .= "\n";
			if ($highlight === true) {
				$blob .= "<highlight>$player<end>";
				$highlight = false;
			} else {
				$blob .= $player;
				$highlight = true;
			}
		}
		$msg = $this->text->makeBlob("$numInactive Inactive Org Members", $blob);
		$sendto->reply($msg);
	}
}
