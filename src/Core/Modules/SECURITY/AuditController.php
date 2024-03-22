<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SECURITY;

use function Safe\{preg_split, strtotime};

use Amp\Http\HttpStatus;
use Amp\Http\Server\{Request, Response};
use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	DB,
	DBSchema\Audit,
	ModuleInstance,
	QueryBuilder,
	Safe,
	Text,
};

use Nadybot\Modules\WEBSERVER_MODULE\{
	ApiResponse,
};
use Safe\DateTime as SafeDateTime;
use Safe\Exceptions\DatetimeException;

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'audit',
		accessLevel: 'admin',
		description: 'View security audit logs',
	),
]
class AuditController extends ModuleInstance {
	/** Log all security-relevant data */
	#[NCA\Setting\Boolean(accessLevel: 'superadmin')]
	public bool $auditEnabled = false;
	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Text $text;

	/**
	 * See the most recent audit entries in the database, optionally filtered
	 *
	 * The filter key can be any of 'action', 'actor', 'actee', 'before' or 'after'
	 */
	#[NCA\HandlesCommand('audit')]
	#[NCA\Help\Prologue('If you have enabled audit logging, you can query the data with this command.')]
	#[NCA\Help\Epilogue(
		"To navigate around the results, use <highlight>limit<end> and <highlight>offset<end>\n".
		"<tab><highlight><symbol>audit limit=200<end>\n".
		'<tab><highlight><symbol>audit limit=200 offset=1000<end>'
	)]
	#[NCA\Help\Example('<symbol>audit actor=Nady after=after=2020-08-20 before=before=2020-08-27')]
	#[NCA\Help\Example('<symbol>audit actor=Nady action=set-rank')]
	#[NCA\Help\Example('<symbol>audit actor=Nady action=set-rank after=last week')]
	#[NCA\Help\Example('<symbol>audit action=action=invite,join,leave after=2021-08-01 20:17:55 CEST')]
	public function auditListCommand(CmdContext $context, ?string $filter): void {
		$query = $this->db->table(AccessManager::DB_TABLE)
			->orderByDesc('time')
			->orderByDesc('id');
		$params = [];
		$error = $this->parseParams($query, $filter??'', $params);
		if (isset($error)) {
			$context->reply($error);
			return;
		}
		$data = $query->asObj(Audit::class);
		if ($data->isEmpty()) {
			$context->reply('No audit data found.');
			return;
		}

		[$prevLink, $nextLink] = $this->getPrevNextLinks($data, $params);
		if ($data->count() > $params['limit']) {
			$data->pop();
		}
		$lines = $data->map(static function (Audit $audit): string {
			$audit->actee = isset($audit->actee) ? " -&gt; {$audit->actee}" : '';
			return '<tab>' . $audit->time->format('Y-m-d H:i:s e').
				" <highlight>{$audit->actor}<end>{$audit->actee} ".
				"<highlight>{$audit->action}<end> {$audit->value}";
		});
		$blob = "<header2>Matching entries<end>\n" . $lines->join("\n");
		if (isset($prevLink) || isset($nextLink)) {
			$blob .= "\n\n".
				(isset($prevLink) ? "[{$prevLink}] " : '').
				(isset($nextLink) ? "[{$nextLink}]" : '');
		}
		$msg = 'Audit entries (' . $lines->count() . ')';
		$msg = $this->text->makeBlob($msg, $blob);
		$context->reply($msg);
	}

	/** Query entries from the audit log */
	#[
		NCA\Api('/audit'),
		NCA\GET,
		NCA\QueryParam(name: 'limit', desc: 'No more than this amount of entries will be returned. Default is 50', type: 'integer'),
		NCA\QueryParam(name: 'offset', desc: 'How many entries to skip before beginning to return entries', type: 'integer'),
		NCA\QueryParam(name: 'actor', desc: 'Show only entries of this actor'),
		NCA\QueryParam(name: 'actee', desc: 'Show only entries with this actee'),
		NCA\QueryParam(name: 'action', desc: 'Show only entries with this action'),
		NCA\QueryParam(name: 'before', desc: 'Show only entries from before the given timestamp', type: 'integer'),
		NCA\QueryParam(name: 'after', desc: 'Show only entries from after the given timestamp', type: 'integer'),
		NCA\AccessLevel('mod'),
		NCA\ApiTag('audit'),
		NCA\ApiResult(code: 200, class: 'Audit[]', desc: 'The audit log entries')
	]
	public function auditGetListEndpoint(Request $request): Response {
		$query = $this->db->table(AccessManager::DB_TABLE)
			->orderByDesc('time')
			->orderByDesc('id');

		$limit = $request->getQueryParameter('limit')??'50';
		if (!ctype_digit($limit)) {
			return new Response(
				status: HttpStatus::UNPROCESSABLE_ENTITY,
				body: 'limit is not an integer value'
			);
		}
		$query->limit((int)$limit);

		$offset = $request->getQueryParameter('offset');
		if (isset($offset)) {
			if (!ctype_digit($offset)) {
				return new Response(
					status: HttpStatus::UNPROCESSABLE_ENTITY,
					body: 'offset is not an integer value'
				);
			}
			$query->offset((int)$offset);
		}

		$before = $request->getQueryParameter('before');
		if (isset($before)) {
			if (!ctype_digit($before)) {
				return new Response(
					status: HttpStatus::UNPROCESSABLE_ENTITY,
					body: 'before is not an integer value'
				);
			}
			$query->where('time', '<=', $before);
		}

		$after = $request->getQueryParameter('after');
		if (isset($after)) {
			if (!ctype_digit($after)) {
				return new Response(
					status: HttpStatus::UNPROCESSABLE_ENTITY,
					body: 'after is not an integer value'
				);
			}
			$query->where('time', '>=', $after);
		}

		$actor = $request->getQueryParameter('actor');
		if (isset($actor)) {
			$query->where('actor', ucfirst(strtolower($actor)));
		}

		$actee = $request->getQueryParameter('actee');
		if (isset($actee)) {
			$query->where('actee', ucfirst(strtolower($actee)));
		}

		$action = $request->getQueryParameter('action');
		if (isset($action)) {
			$query->where('action', strtolower($action));
		}

		return ApiResponse::create($query->asObj(Audit::class)->toArray());
	}

	/** @param array<mixed> $params */
	protected function parseParams(QueryBuilder $query, string $args, array &$params): ?string {
		$keys = [
			'limit', 'offset', 'before', 'after', 'actor', 'actee', 'action',
		];
		$args = Safe::pregReplace("/,?\s+(" . implode('|', $keys) . ")\s*=?\s*/s", '&$1=', $args);
		parse_str($args, $params);
		$params['limit'] ??= '50';
		$limit = $params['limit'];
		if (!is_string($limit) || !ctype_digit($limit)) {
			return '<highlight>limit<end> must be a number.';
		}
		$query->limit((int)$limit + 1);

		$params['offset'] ??= '0';
		$offset = $params['offset'];
		if (!is_string($offset) || !ctype_digit($offset)) {
			return '<highlight>offset<end> must be a number.';
		}
		$query->offset((int)$offset);

		$before = $params['before']??null;
		if (isset($before) && is_string($before)) {
			try {
				$before = strtotime($before);
			} catch (DatetimeException) {
				return '<highlight>before<end> must be a date and/or time.';
			}
			$params['before'] = (new SafeDateTime())->setTimestamp($before)->format("Y-m-d\TH:i:s e");
			$query->where('time', '<=', $before);
		}

		$after = $params['after']??null;
		if (isset($after) && is_string($after)) {
			try {
				$after = strtotime($after);
			} catch (DatetimeException) {
				return '<highlight>after<end> must be a date and/or time.';
			}
			$params['after'] = (new SafeDateTime())->setTimestamp($after)->format("Y-m-d\TH:i:s e");
			$query->where('time', '>=', $after);
		}

		$actor = $params['actor']??null;
		if (isset($actor) && is_string($actor)) {
			$query->where('actor', ucfirst(strtolower($actor)));
		}

		$actee = $params['actee']??null;
		if (isset($actee) && is_string($actee)) {
			$query->where('actee', ucfirst(strtolower($actee)));
		}

		$action = $params['action']??null;
		if (isset($action) && is_string($action)) {
			$query->whereIn('action', preg_split("/\s*,\s*/", strtolower($action)));
		}

		return null;
	}

	/**
	 * @param Collection<Audit>   $data
	 * @param array<string,mixed> $params
	 *
	 * @return string[]
	 *
	 * @psalm-return array{0: ?string, 1: ?string}
	 *
	 * @phpstan-return array{0: ?string, 1: ?string}
	 */
	protected function getPrevNextLinks(Collection $data, array $params): array {
		$prevLink = $nextLink = null;
		if ($params['offset'] > 0) {
			$prevParams = $params;
			$prevParams['offset'] = max(0, $prevParams['offset'] - $prevParams['limit']);
			$cmdArgs = implode(' ', array_map(
				static fn ($k, $v) => "{$k}={$v}",
				array_keys($prevParams),
				array_values($prevParams)
			));
			$prevLink = $this->text->makeChatcmd('&lt; prev', "/tell <myname> audit {$cmdArgs}");
		}
		if ($data->count() > $params['limit']) {
			$nextParams = $params;
			$nextParams['offset'] += $nextParams['limit'];
			$cmdArgs = implode(' ', array_map(
				static fn ($k, $v) => "{$k}={$v}",
				array_keys($nextParams),
				array_values($nextParams)
			));
			$nextLink = $this->text->makeChatcmd('next &gt;', "/tell <myname> audit {$cmdArgs}");
		}
		return [$prevLink, $nextLink];
	}

	protected function addRangeLimits(Request $request, QueryBuilder $query): ?Response {
		$max = $request->getQueryParameter('max');
		if (!isset($max)) {
			return null;
		}
		if (!ctype_digit($max)) {
			return new Response(
				status: HttpStatus::UNPROCESSABLE_ENTITY,
				body: 'max is not an integer value'
			);
		}
		$query->where('id', '<=', $max);
		return null;
	}
}
