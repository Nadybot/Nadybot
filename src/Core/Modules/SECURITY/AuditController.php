<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SECURITY;

use Nadybot\Core\Attributes as NCA;
use DateTime;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	CmdContext,
	DB,
	DBSchema\Audit,
	QueryBuilder,
	SettingManager,
	Text,
};
use Nadybot\Modules\WEBSERVER_MODULE\{
	ApiResponse,
	HttpProtocolWrapper,
	Request,
	Response,
};

/**
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "audit",
		accessLevel: "admin",
		description: "View security audit logs",
		help: "audit.txt"
	)
]
class AuditController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Text $text;

	/**
	 * This handler is called on bot startup.
	 */
	#[NCA\Setup]
	public function setup(): void {
		$this->settingManager->add(
			module: $this->moduleName,
			name: "audit_enabled",
			description: "Log all security-relevant data",
			mode: "edit",
			type: "options",
			value: "0",
			options: "true;false",
			intoptions: "1;0",
			accessLevel: "superadmin"
		);
	}

	protected function parseParams(QueryBuilder $query, string $args, array &$params): ?string {
		$keys = [
			"limit", "offset", "before", "after", "actor", "actee", "action"
		];
		$args = preg_replace("/,?\s+(" . join("|", $keys) . ")\s*=?\s*/s", "&$1=", $args);
		parse_str($args, $params);
		$params["limit"] ??= "50";
		$limit = $params["limit"];
		if (!preg_match("/^\d+$/", $limit)) {
			return "<highlight>limit<end> must be a number.";
		}
		$query->limit((int)$limit + 1);

		$params["offset"] ??= "0";
		$offset = $params["offset"];
		if (!preg_match("/^\d+$/", $offset)) {
			return "<highlight>offset<end> must be a number.";
		}
		$query->offset((int)$offset);

		$before = $params["before"]??null;
		if (isset($before)) {
			$before = strtotime($before);
			if ($before === false || abs($before) > 0x7FFFFFFF) {
				return "<highlight>before<end> must be a date and/or time.";
			}
			$params["before"] = (new DateTime())->setTimestamp($before)->format("Y-m-d\TH:i:s e");
			$query->where("time", "<=", $before);
		}

		$after = $params["after"]??null;
		if (isset($after)) {
			$after = strtotime($after);
			if ($after === false || abs($after) > 0x7FFFFFFF) {
				return "<highlight>after<end> must be a date and/or time.";
			}
			$params["after"] = (new DateTime())->setTimestamp($after)->format("Y-m-d\TH:i:s e");
			$query->where("time", ">=", $after);
		}

		$actor = $params["actor"]??null;
		if (isset($actor)) {
			$query->where("actor", ucfirst(strtolower($actor)));
		}

		$actee = $params["actee"]??null;
		if (isset($actee)) {
			$query->where("actee", ucfirst(strtolower($actee)));
		}

		$action = $params["action"]??null;
		if (isset($action)) {
			$query->whereIn("action", preg_split("/\s*,\s*/", strtolower($action)));
		}

		return null;
	}

	protected function getPrevNextLinks(Collection $data, array $params): array {
		$prevLink = $nextLink = null;
		if ($params["offset"] > 0) {
			$prevParams = $params;
			$prevParams["offset"] = max(0, $prevParams["offset"] - $prevParams["limit"]);
			$cmdArgs = join(" ", array_map(
				fn ($k, $v) => "{$k}={$v}",
				array_keys($prevParams),
				array_values($prevParams)
			));
			$prevLink = $this->text->makeChatcmd("&lt; prev", "/tell <myname> audit {$cmdArgs}");
		}
		if ($data->count() > $params["limit"]) {
			$nextParams = $params;
			$nextParams["offset"] += $nextParams["limit"];
			$cmdArgs = join(" ", array_map(
				fn ($k, $v) => "{$k}={$v}",
				array_keys($nextParams),
				array_values($nextParams)
			));
			$nextLink = $this->text->makeChatcmd("next &gt;", "/tell <myname> audit {$cmdArgs}");
		}
		return [$prevLink, $nextLink];
	}

	#[NCA\HandlesCommand("audit")]
	public function auditListCommand(CmdContext $context, ?string $filter): void {
		$query = $this->db->table(AccessManager::DB_TABLE)
			->orderByDesc("time")
			->orderByDesc("id");
		$params = [];
		$error = $this->parseParams($query, $filter??"", $params);
		if (isset($error)) {
			$context->reply($error);
			return;
		}
		$data = $query->asObj(Audit::class);
		if ($data->isEmpty()) {
			$context->reply("No audit data found.");
			return;
		}

		[$prevLink, $nextLink] = $this->getPrevNextLinks($data, $params);
		if ($data->count() > $params["limit"]) {
			$data->pop();
		}
		$lines = $data->map(function (Audit $audit): string {
			$audit->actee = $audit->actee ? " -&gt; {$audit->actee}" : "";
			return "<tab>" . $audit->time->format("Y-m-d H:i:s e").
				" <highlight>{$audit->actor}<end>{$audit->actee} ".
				"<highlight>{$audit->action}<end> {$audit->value}";
		});
		$blob = "<header2>Matching entries<end>\n" . $lines->join("\n");
		if (isset($prevLink) || isset($nextLink)) {
			$blob .= "\n\n".
				(isset($prevLink) ? "[{$prevLink}] " : "").
				(isset($nextLink) ? "[{$nextLink}]" : "");
		}
		$msg = "Audit entries (" . $lines->count() . ")";
		$msg = $this->text->makeBlob($msg, $blob);
		$context->reply($msg);
	}

	/**
	 * Query entries from the audit log
	 */
	#[
		NCA\Api("/audit"),
		NCA\GET,
		NCA\QueryParam(name: "limit", desc: "No more than this amount of entries will be returned. Default is 50", type: "integer"),
		NCA\QueryParam(name: "offset", desc: "How many entries to skip before beginning to return entries", type: "integer"),
		NCA\QueryParam(name: "actor", desc: "Show only entries of this actor"),
		NCA\QueryParam(name: "actee", desc: "Show only entries with this actee"),
		NCA\QueryParam(name: "action", desc: "Show only entries with this action"),
		NCA\QueryParam(name: "before", desc: "Show only entries from before the given timestamp", type: "integer"),
		NCA\QueryParam(name: "after", desc: "Show only entries from after the given timestamp", type: "integer"),
		NCA\AccessLevel("mod"),
		NCA\ApiTag("audit"),
		NCA\ApiResult(code: 200, class: "Audit[]", desc: "The audit log entries")
	]
	public function auditGetListEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		$query = $this->db->table(AccessManager::DB_TABLE)
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
			$query->asObj(Audit::class)->toArray()
		);
	}

	protected function addRangeLimits(Request $request, QueryBuilder $query): ?Response {
		$max = $request->query["max"]??null;
		if (!isset($max)) {
			return null;
		}
		if (!preg_match("/^\d+$/", $max)) {
			return new Response(Response::UNPROCESSABLE_ENTITY, [], "max is not an integer value");
		}
		$query->where("id", "<=", $max);
		return null;
	}
}
