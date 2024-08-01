<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Closure;
use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	DB,
	DBSchema\CmdPermSetMapping,
	DBSchema\CmdPermissionSet,
	DBSchema\Setting,
	Exceptions\SQLException,
	ModuleInstance,
	ParamClass\PRemove,
	ParamClass\PWord,
	SettingManager,
	Text,
};

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'cmdmap',
		accessLevel: 'superadmin',
		description: 'Manages command to permission mappings',
		defaultStatus: 1
	)
]
class PermissionSetMappingController extends ModuleInstance {
	#[NCA\Inject]
	private CommandManager $cmdManager;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private SettingManager $settingManager;

	/** Show a list of the current permission set mapping */
	#[NCA\HandlesCommand('cmdmap')]
	#[NCA\Help\Prologue(
		"<header2>Permission set mappings<end>\n\n".
		"Permission set mappings tell the bot which permission set to use when\n".
		"receiving commands from a command source.\n".
		"They also describe which symbol a message has to start with, in order\n".
		'to be treated as a command, along with some other flags.'
	)]
	public function cmdmapListCommand(CmdContext $context): void {
		$srcs = collect($this->cmdManager->getSources());
		$maps = $this->cmdManager->getPermSetMappings();
		$unusedSrcs = $srcs->filter(static function (string $source) use ($maps): bool {
			foreach ($maps as $map) {
				if (fnmatch($source, $map->source, \FNM_CASEFOLD)) {
					return false;
				}
			}
			return true;
		});
		$blocks = $maps->map(Closure::fromCallable($this->renderPermSetMapping(...)));
		$blob = $blocks->join("\n\n<pagebreak>");
		if ($unusedSrcs->isNotEmpty()) {
			if (strlen($blob)) {
				$blob .= "\n\n";
			}
			$blob .= "<header2>Unmapped command sources<end>\n".
				'<tab>' . $unusedSrcs->join("\n<tab>") . "\n\n".
				'<i>To start accepting commands from an unmapped '.
				"source, use</i>\n".
				'<i><tab><highlight><symbol>cmdmap create '.
				"&lt;source&gt; &lt;permission map&gt;<end></i>\n".
				"<i>For example:</i>\n".
				'<i><tab><highlight><symbol>cmdmap create ' . $unusedSrcs->firstOrFail().
				' ' . ($this->cmdManager->getPermissionSets()->firstOrFail()->name).
				'<end></i>';
		}
		$context->reply(
			$this->text->makeBlob('Permission set mappings (' . $blocks->count() . ')', $blob)
		);
	}

	/** Map commands from &lt;source&gt; to use the &lt;permission set&gt;*/
	#[NCA\HandlesCommand('cmdmap')]
	public function cmdmapNewCommand(
		CmdContext $context,
		#[NCA\Str('new', 'create')] string $action,
		string $source,
		PWord $permissionSet
	): void {
		$source = strtolower($source);
		if ($this->cmdManager->getPermSetMappings()->where('source', $source)->isNotEmpty()) {
			$context->reply("There is already a permission set map for <highlight>{$source}<end>.");
			return;
		}
		$permissionSet = strtolower($permissionSet());
		if (!$this->cmdManager->hasPermissionSet($permissionSet)) {
			$context->reply("There is no permission set <highlight>{$permissionSet}<end>.");
			return;
		}
		$srcValid = collect($this->cmdManager->getSources())
			->filter(static function (string $mask) use ($source): bool {
				return fnmatch($mask, $source, \FNM_CASEFOLD);
			})->isNotEmpty();
		if (!$srcValid) {
			$context->reply(
				"There is no source providing <highlight>{$source}<end>. ".
				'See <highlight><symbol>cmdmap list src<end> for a list of sources.'
			);
			return;
		}
		$map = new CmdPermSetMapping(
			source: $source,
			permission_set: $permissionSet,
			symbol: $this->settingManager->getString('symbol') ?? '!',
		);
		try {
			$this->db->insert($map);
		} catch (SQLException) {
			$context->reply('There was an error saving your mapping into the database.');
			return;
		}
		$this->cmdManager->loadPermsetMappings();
		$context->reply(
			Text::blobWrap(
				"Mapping from {$source} to {$permissionSet} created. ",
				$this->text->makeBlob(
					'Configure it',
					$this->renderPermSetMapping($map),
					'Configure your mapping'
				)
			)
		);
	}

	/** Show a list of all command sources */
	#[NCA\HandlesCommand('cmdmap')]
	public function cmdmapListSourcesCommand(
		CmdContext $context,
		#[NCA\Str('list')] string $action,
		#[NCA\Str('src', 'source', 'sources')] string $subaction,
	): void {
		$sources = collect($this->cmdManager->getSources())->sort();
		$blob = "<header2>Registered sources<end>\n".
			'<tab>' . $sources->join("\n<tab>");
		$context->reply(
			$this->text->makeBlob(
				'Registered cmd sources (' . $sources->count() . ')',
				$blob
			)
		);
	}

	/** Delete a permission set mapping. This will stop executing commands from &lt;source&gt; */
	#[NCA\HandlesCommand('cmdmap')]
	public function cmdmapDeleteCommand(
		CmdContext $context,
		PRemove $action,
		string $source,
	): void {
		$source = strtolower($source);
		try {
			if (!$this->cmdManager->deletePermissionSetMapping($source)) {
				$context->reply("There is no permission set map for <highlight>{$source}<end>.");
				return;
			}
		} catch (Exception $e) {
			$context->reply($e->getMessage());
			return;
		}
		$map = $this->cmdManager->getPermsetMapForSource($source);
		if (isset($map)) {
			$context->reply(
				"Commands from <highlight>{$source}<end> will now be handled ".
				"by the mapping for <highlight>{$map->source}<end>, run with ".
				"permission set <highlight>{$map->permission_set}<end> with symbol ".
				"<highlight>{$map->symbol}<end>."
			);
			return;
		}
		$context->reply(
			"Commands from <highlight>{$source}<end> will no longer be accepted."
		);
	}

	/** Change the permission set to use for a command source */
	#[NCA\HandlesCommand('cmdmap')]
	public function cmdmapPickPermsetCommand(
		CmdContext $context,
		#[NCA\Str('permset')] string $action,
		#[NCA\Str('pick')] string $subAction,
		string $source
	): void {
		$source = strtolower($source);

		/** @var null|CmdPermSetMapping */
		$set = $this->cmdManager->getPermSetMappings()->where('source', $source)->first();
		if (!isset($set)) {
			$context->reply("There is currently no permission set map for <highlight>{$source}<end>.");
			return;
		}
		$sets = $this->cmdManager->getPermissionSets();
		$choices = $sets->map(static function (CmdPermissionSet $set) use ($source): string {
			return '<tab>' . Text::makeChatcmd($set->name, "/tell <myname> cmdmap permset set {$source} {$set->name}");
		})->join("\n");
		$context->reply(
			$this->text->makeBlob(
				"Choose a permission set for {$source}",
				"<header2>Available permission sets<end>\n" . $choices
			)
		);
	}

	/** Change the prefix symbol for a command source */
	#[NCA\HandlesCommand('cmdmap')]
	public function cmdmapPickSymbolCommand(
		CmdContext $context,
		#[NCA\Str('prefix', 'symbol')] string $action,
		#[NCA\Str('pick')] string $subAction,
		string $source
	): void {
		$source = strtolower($source);

		/** @var null|CmdPermSetMapping */
		$set = $this->cmdManager->getPermSetMappings()->where('source', $source)->first();
		if (!isset($set)) {
			$context->reply("There is currently no permission set map for <highlight>{$source}<end>.");
			return;
		}

		/** @var ?Setting $row */
		$row = $this->db->table(Setting::getTable())
			->where('name', 'symbol')
			->asObj(Setting::class)
			->first();
		if ($row === null || !isset($row->options) || !strlen($row->options)) {
			$msg = 'Could not find setting <highlight>symbol<end>.';
			$context->reply($msg);
			return;
		}
		$choices = collect(explode(';', $row->options))->map(static function (string $option) use ($source): string {
			return "<tab><highlight>{$option}<end> [" . Text::makeChatcmd('use this', "/tell <myname> cmdmap symbol set {$source} {$option}") . ']';
		})->join("\n");
		$context->reply(
			$this->text->makeBlob(
				"Choose a symbol for {$source}",
				"<header2>Available symbols<end>\n" . $choices
			)
		);
	}

	/** Set the permission set for a command source */
	#[NCA\HandlesCommand('cmdmap')]
	public function cmdmapSetPermsetCommand(
		CmdContext $context,
		#[NCA\Str('permset')] string $action,
		#[NCA\Str('set')] string $subAction,
		string $source,
		PWord $permissionSet
	): void {
		$permissionSet = strtolower($permissionSet());
		if ($this->cmdManager->getPermissionSets()->where('name', $permissionSet)->isEmpty()) {
			$context->reply("The permission set <highlight>{$permissionSet}<end> doesn't exist.");
			return;
		}
		$this->changeCmdMap($context, $source, static function (CmdPermSetMapping $set) use ($permissionSet): void {
			$set->permission_set = $permissionSet;
		});
	}

	/** Set the prefix symbol for a command source */
	#[NCA\HandlesCommand('cmdmap')]
	public function cmdmapSetSymbolCommand(
		CmdContext $context,
		#[NCA\Str('prefix', 'symbol')] string $action,
		#[NCA\Str('set')] string $subAction,
		string $source,
		string $symbol
	): void {
		$this->changeCmdMap($context, $source, static function (CmdPermSetMapping $set) use ($symbol): void {
			$set->symbol = $symbol;
		});
	}

	/** Set if the prefix symbol is optional for a command source */
	#[NCA\HandlesCommand('cmdmap')]
	public function cmdmapChangeSymbolOptionalCommand(
		CmdContext $context,
		#[NCA\Str('prefixopt', 'symbolopt')] string $action,
		#[NCA\Str('set')] string $subAction,
		string $source,
		bool $optional
	): void {
		$this->changeCmdMap($context, $source, static function (CmdPermSetMapping $set) use ($optional): void {
			$set->symbol_optional = $optional;
		});
	}

	/** Set if unknown commands from &lt;source&gt; trigger error messages */
	#[NCA\HandlesCommand('cmdmap')]
	public function cmdmapChangeFeedbackCommand(
		CmdContext $context,
		#[NCA\Str('feedback')] string $action,
		#[NCA\Str('set')] string $subAction,
		string $source,
		bool $feedback
	): void {
		$this->changeCmdMap($context, $source, static function (CmdPermSetMapping $set) use ($feedback): void {
			$set->feedback = $feedback;
		});
	}

	protected function renderPermSetMapping(CmdPermSetMapping $map): string {
		$deleteLink = Text::makeChatcmd('delete', "/tell <myname> cmdmap rem {$map->source}");
		$permSetLink = Text::makeChatcmd('change', "/tell <myname> cmdmap permset pick {$map->source}");
		$symbolLink = Text::makeChatcmd('change', "/tell <myname> cmdmap symbol pick {$map->source}");
		$symOptText = $map->symbol_optional ? 'no' : 'yes';
		$symbolOptionalLink = Text::makeChatcmd($symOptText, "/tell <myname> cmdmap symbolopt set {$map->source} {$symOptText}");
		$feedbackText = $map->feedback ? 'no' : 'yes';
		$feedbackLink = Text::makeChatcmd($feedbackText, "/tell <myname> cmdmap feedback set {$map->source} {$feedbackText}");
		$block = "<header2>{$map->source}<end> [{$deleteLink}]\n".
			"<tab>Permission set: <highlight>{$map->permission_set}<end> [{$permSetLink}]\n".
			'<tab>Symbol: <highlight>'.
				(strlen($map->symbol) ? $map->symbol : '&lt;none&gt;').
			"<end> [{$symbolLink}]\n".
			'<tab>Symbol optional: <highlight>' . ($map->symbol_optional ? 'yes' : 'no') . '<end>'.
				" [{$symbolOptionalLink}]\n".
			"<tab>Feedback if cmd doesn't exist: <highlight>".
				($map->feedback ? 'yes' : 'no') . "<end> [{$feedbackLink}]";
		return $block;
	}

	/**
	 * Change one or more attributes of a command mapping
	 *
	 * @phpstan-param Closure(CmdPermSetMapping):void $callback
	 */
	protected function changeCmdMap(CmdContext $context, string $source, Closure $callback): void {
		$source = strtolower($source);

		$set = $this->cmdManager->getPermSetMappings()->where('source', $source)->first();
		if (!isset($set)) {
			$context->reply("There is currently no permission set map for <highlight>{$source}<end>.");
			return;
		}
		$callback($set);
		$this->db->update($set);
		$this->cmdManager->loadPermsetMappings();
		$context->reply("Setting changed for <highlight>{$source}<end>.");
	}
}
