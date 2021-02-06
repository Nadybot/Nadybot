<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\DB;
use Nadybot\Core\Event;
use Nadybot\Core\Text;
use Nadybot\Core\Util;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = "orghistory",
 *		accessLevel = "guild",
 *		description = "Shows the org history (invites and kicks and leaves) for a character",
 *		help        = "orghistory.txt"
 *	)
 */
class OrgHistoryController {

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
	 * @Setup
	 */
	public function setup() {
		$this->db->loadSQLFile($this->moduleName, "org_history");
	}

	/**
	 * @HandlesCommand("orghistory")
	 * @Matches("/^orghistory$/i")
	 * @Matches("/^orghistory (\d+)$/i")
	 */
	public function orgHistoryCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$pageSize = 40;
		$page = 1;
		if (count($args) == 2) {
			$page = (int)$args[1];
		}

		$startingRecord = ($page - 1) * $pageSize;

		$blob = '';

		/** @var OrgHistory[] */
		$data = $this->db->fetchAll(
			OrgHistory::class,
			"SELECT * FROM `org_history` ORDER BY time DESC LIMIT ?, ?",
			$startingRecord,
			$pageSize
		);
		if (count($data) === 0) {
			$msg = "No org history has been recorded.";
			$sendto->reply($msg);
			return;
		}
		foreach ($data as $row) {
			$blob .= $this->formatOrgAction($row);
		}

		$msg = $this->text->makeBlob('Org History', $blob);

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("orghistory")
	 * @Matches("/^orghistory (.+)$/i")
	 */
	public function orgHistoryPlayerCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$player = ucfirst(strtolower($args[1]));

		$blob = '';

		/** @var OrgHistory[] */
		$data = $this->db->fetchAll(
			OrgHistory::class,
			"SELECT * FROM `org_history` WHERE actee LIKE ? ORDER BY time DESC",
			$player
		);
		$count = count($data);
		$blob .= "\n<header2>Actions on $player ($count)<end>\n";
		foreach ($data as $row) {
			$blob .= $this->formatOrgAction($row);
		}

		/** @var OrgHistory[] */
		$data = $this->db->fetchAll(
			OrgHistory::class,
			"SELECT * FROM `org_history` WHERE actor LIKE ? ORDER BY time DESC",
			$player
		);
		$count = count($data);
		$blob .= "\n<header2>Actions by $player ($count)<end>\n";
		foreach ($data as $row) {
			$blob .= $this->formatOrgAction($row);
		}

		$msg = $this->text->makeBlob("Org History for $player", $blob);

		$sendto->reply($msg);
	}

	public function formatOrgAction(OrgHistory $row): string {
		if ($row->action === "left") {
			return "<highlight>$row->actor<end> $row->action. [$row->organization] " . $this->util->date($row->time) . "\n";
		}
		return"<highlight>$row->actor<end> $row->action <highlight>$row->actee<end>. [$row->organization] " . $this->util->date($row->time) . "\n";
	}

	/**
	 * @Event("orgmsg")
	 * @Description("Capture Org Invite/Kick/Leave messages for orghistory")
	 */
	public function captureOrgMessagesEvent(Event $eventObj): void {
		$message = $eventObj->message;
		if (preg_match("/^(.+) just left your organization.$/", $message, $arr)) {
			$actor = $arr[1];
			$actee = "";
			$action = "left";
			$time = time();

			$sql = "INSERT INTO `org_history` (actor, actee, action, organization, time) VALUES (?, ?, ?, '<myguild>', ?) ";
			$this->db->exec($sql, $actor, $actee, $action, $time);
		} elseif (preg_match("/^(.+) kicked (.+) from your organization.$/", $message, $arr)) {
			$actor = $arr[1];
			$actee = $arr[2];
			$action = "kicked";
			$time = time();

			$sql = "INSERT INTO `org_history` (actor, actee, action, organization, time) VALUES (?, ?, ?, '<myguild>', ?) ";
			$this->db->exec($sql, $actor, $actee, $action, $time);
		} elseif (preg_match("/^(.+) invited (.+) to your organization.$/", $message, $arr)) {
			$actor = $arr[1];
			$actee = $arr[2];
			$action = "invited";
			$time = time();

			$sql = "INSERT INTO `org_history` (actor, actee, action, organization, time) VALUES (?, ?, ?, '<myguild>', ?) ";
			$this->db->exec($sql, $actor, $actee, $action, $time);
		} elseif (preg_match("/^(.+) removed inactive character (.+) from your organization.$/", $message, $arr)) {
			$actor = $arr[1];
			$actee = $arr[2];
			$action = "removed";
			$time = time();

			$sql = "INSERT INTO `org_history` (actor, actee, action, organization, time) VALUES (?, ?, ?, '<myguild>', ?) ";
			$this->db->exec($sql, $actor, $actee, $action, $time);
		}
	}
}
