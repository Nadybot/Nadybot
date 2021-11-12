<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\AOChatEvent;
use Nadybot\Core\CmdContext;
use Nadybot\Core\DB;
use Nadybot\Core\ParamClass\PCharacter;
use Nadybot\Core\Text;
use Nadybot\Core\Util;
use Nadybot\Modules\WEBSERVER_MODULE\ApiResponse;
use Nadybot\Modules\WEBSERVER_MODULE\HttpProtocolWrapper;
use Nadybot\Modules\WEBSERVER_MODULE\Request;
use Nadybot\Modules\WEBSERVER_MODULE\Response;

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

	public const DB_TABLE = "org_history";

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
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations/History");
	}

	/**
	 * @HandlesCommand("orghistory")
	 */
	public function orgHistoryCommand(CmdContext $context, ?int $page): void {
		$page ??= 1;
		$pageSize = 40;

		$startingRecord = max(0, ($page - 1) * $pageSize);

		$blob = '';

		/** @var Collection<OrgHistory> */
		$data = $this->db->table(self::DB_TABLE)
			->orderByDesc("time")
			->limit($pageSize)
			->offset($startingRecord)
			->asObj(OrgHistory::class);
		if ($data->count() === 0) {
			$msg = "No org history has been recorded.";
			$context->reply($msg);
			return;
		}
		foreach ($data as $row) {
			$blob .= $this->formatOrgAction($row);
		}

		$msg = $this->text->makeBlob('Org History', $blob);

		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("orghistory")
	 */
	public function orgHistoryPlayerCommand(CmdContext $context, PCharacter $char): void {
		$player = $char();

		$blob = '';

		/** @var Collection<OrgHistory> */
		$data = $this->db->table(self::DB_TABLE)
			->whereIlike("actee", $player)
			->orderByDesc("time")
			->asObj(OrgHistory::class);
		$count = $data->count();
		$blob .= "\n<header2>Actions on $player ($count)<end>\n";
		foreach ($data as $row) {
			$blob .= $this->formatOrgAction($row);
		}

		/** @var Collection<OrgHistory> */
		$data = $this->db->table(self::DB_TABLE)
			->whereIlike("action", $player)
			->orderByDesc("time")
			->asObj(OrgHistory::class);
		$count = $data->count();
		$blob .= "\n<header2>Actions by $player ($count)<end>\n";
		foreach ($data as $row) {
			$blob .= $this->formatOrgAction($row);
		}

		$msg = $this->text->makeBlob("Org History for $player", $blob);

		$context->reply($msg);
	}

	public function formatOrgAction(OrgHistory $row): string {
		$time = "Unknown time";
		if (isset($row->time)) {
			$time = $this->util->date($row->time);
		}
		if ($row->action === "left") {
			return "<highlight>$row->actor<end> $row->action. [$row->organization] {$time}\n";
		}
		return"<highlight>$row->actor<end> $row->action <highlight>$row->actee<end>. [$row->organization] {$time}\n";
	}

	/**
	 * @Event("orgmsg")
	 * @Description("Capture Org Invite/Kick/Leave messages for orghistory")
	 */
	public function captureOrgMessagesEvent(AOChatEvent $eventObj): void {
		$message = $eventObj->message;
		if (
			preg_match("/^(?<actor>.+) just (?<action>left) your organization.$/", $message, $arr)
			|| preg_match("/^(?<actor>.+) (?<action>kicked) (?<actee>.+) from your organization.$/", $message, $arr)
			|| preg_match("/^(?<actor>.+) (?<action>invited) (?<actee>.+) to your organization.$/", $message, $arr)
			|| preg_match("/^(?<actor>.+) (?<action>removed) inactive character (?<actee>.+) from your organization.$/", $message, $arr)
		) {
			$this->db->table(self::DB_TABLE)
				->insert([
					"actor" => $arr["actor"] ?? "",
					"actee" => $arr["actee"] ?? "",
					"action" => $arr["action"] ?? "",
					"organization" => $this->db->getMyguild(),
					"time" => time(),
				]);
		}
	}

	/**
	 * Query entries from the org history log
	 * @Api("/org/history")
	 * @GET
	 * @QueryParam(name='limit', type='integer', desc='No more than this amount of entries will be returned. Default is 50', required=false)
	 * @QueryParam(name='offset', type='integer', desc='How many entries to skip before beginning to return entries, required=false)
	 * @QueryParam(name='actor', type='string', desc='Show only entries of this actor', required=false)
	 * @QueryParam(name='actee', type='string', desc='Show only entries with this actee', required=false)
	 * @QueryParam(name='action', type='string', desc='Show only entries with this action', required=false)
	 * @QueryParam(name='before', type='integer', desc='Show only entries from before the given timestamp', required=false)
	 * @QueryParam(name='after', type='integer', desc='Show only entries from after the given timestamp', required=false)
	 * @AccessLevel("mod")
	 * @ApiTag("audit")
	 * @ApiResult(code=200, class='OrgHistory[]', desc='The org history log entries')
	 */
	public function historyGetListEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		$query = $this->db->table(static::DB_TABLE)
			->orderByDesc("time")
			->orderByDesc("id");

		$limit = $request->query["limit"]??"50";
		if (isset($limit)) {
			if (!preg_match("/^\d+$/", $limit)) {
				return new Response(Response::UNPROCESSABLE_ENTITY, [], "limit is not an integer value");
			}
			$query->limit((int)$limit);
		}

		$offset = $request->query["offset"]??null;
		if (isset($offset)) {
			if (!preg_match("/^\d+$/", $offset)) {
				return new Response(Response::UNPROCESSABLE_ENTITY, [], "offset is not an integer value");
			}
			$query->offset((int)$offset);
		}

		$before = $request->query["before"]??null;
		if (isset($before)) {
			if (!preg_match("/^\d+$/", $before)) {
				return new Response(Response::UNPROCESSABLE_ENTITY, [], "before is not an integer value");
			}
			$query->where("time", "<=", $before);
		}

		$after = $request->query["after"]??null;
		if (isset($after)) {
			if (!preg_match("/^\d+$/", $after)) {
				return new Response(Response::UNPROCESSABLE_ENTITY, [], "after is not an integer value");
			}
			$query->where("time", ">=", $after);
		}

		$actor = $request->query["actor"]??null;
		if (isset($actor)) {
			$query->where("actor", ucfirst(strtolower($actor)));
		}

		$actee = $request->query["actee"]??null;
		if (isset($actee)) {
			$query->where("actee", ucfirst(strtolower($actee)));
		}

		$action = $request->query["action"]??null;
		if (isset($action)) {
			$query->where("action", strtolower($action));
		}

		return new ApiResponse(
			$query->asObj(OrgHistory::class)->toArray()
		);
	}
}
