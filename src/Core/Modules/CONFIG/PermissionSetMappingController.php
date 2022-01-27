<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Closure;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	DB,
	DBSchema\CmdPermissionSet,
	DBSchema\CmdPermSetMapping,
	DBSchema\Setting,
	ModuleInstance,
	ParamClass\PRemove,
	ParamClass\PWord,
	SettingManager,
	SQLException,
	Text,
};

/**
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "cmdmap",
		accessLevel: "superadmin",
		description: "Manages command to permission mappings",
		help: "cmdmap.txt",
		defaultStatus: 1
	)
]
class PermissionSetMappingController extends ModuleInstance {
	#[NCA\Inject]
	public CommandManager $cmdManager;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public PermissionSetController $permSetCtrl;

	#[NCA\HandlesCommand("cmdmap")]
	public function cmdmapListCommand(CmdContext $context): void {
		$srcs = new Collection($this->cmdManager->getSources());
		$maps = $this->cmdManager->getPermSetMappings();
		$unusedSrcs = $srcs->filter(function (string $source) use ($maps): bool {
			foreach ($maps as $map) {
				if (fnmatch($source, $map->source, FNM_CASEFOLD)) {
					return false;
				}
			}
			return true;
		});
		$blocks = $maps->map(Closure::fromCallable([$this, "renderPermSetMapping"]));
		$blob = $blocks->join("\n\n<pagebreak>");
		if ($unusedSrcs->isNotEmpty()) {
			if (strlen($blob)) {
				$blob .= "\n\n";
			}
			$blob .= "<header2>Unmapped command sources<end>\n".
				"<tab>" . $unusedSrcs->join("\n<tab>") . "\n\n".
				"<i>To start accepting commands from an unmapped ".
				"source, use</i>\n".
				"<i><tab><highlight><symbol>cmdmap create ".
				"&lt;source&gt; &lt;permission map&gt;<end></i>\n".
				"<i>For example:</i>\n".
				"<i><tab><highlight><symbol>cmdmap create " . $unusedSrcs->firstOrFail().
				" " . ($this->cmdManager->getPermissionSets()->firstOrFail()->name).
				"<end></i>";
		}
		$context->reply(
			$this->text->makeBlob("Permission set mappings (" . $blocks->count() . ")", $blob)
		);
	}

	protected function renderPermSetMapping(CmdPermSetMapping $map): string {
		$deleteLink = $this->text->makeChatcmd("delete", "/tell <myname> cmdmap rem {$map->source}");
		$permSetLink = $this->text->makeChatcmd("change", "/tell <myname> cmdmap permset pick {$map->source}");
		$symbolLink = $this->text->makeChatcmd("change", "/tell <myname> cmdmap symbol pick {$map->source}");
		$symOptText = $map->symbol_optional ? "no" : "yes";
		$symbolOptionalLink = $this->text->makeChatcmd($symOptText, "/tell <myname> cmdmap symbolopt set {$map->source} {$symOptText}");
		$feedbackText = $map->feedback ? "no" : "yes";
		$feedbackLink = $this->text->makeChatcmd($feedbackText, "/tell <myname> cmdmap feedback set {$map->source} {$feedbackText}");
		$block = "<header2>{$map->source}<end> [{$deleteLink}]\n".
			"<tab>Permission set: <highlight>{$map->permission_set}<end> [{$permSetLink}]\n".
			"<tab>Symbol: <highlight>".
				(strlen($map->symbol) ? $map->symbol : "&lt;none&gt;").
			"<end> [{$symbolLink}]\n".
			"<tab>Symbol optional: <highlight>" . ($map->symbol_optional ? "yes" : "no") . "<end>".
				" [{$symbolOptionalLink}]\n".
			"<tab>Feedback if cmd doesn't exist: <highlight>".
				($map->feedback ? "yes" : "no") . "<end> [{$feedbackLink}]";
		return $block;
	}

	#[NCA\HandlesCommand("cmdmap")]
	public function cmdmapNewCommand(
		CmdContext $context,
		#[NCA\Str("new", "create")] string $action,
		string $source,
		PWord $permset
	): void {
		$source = strtolower($source);
		if ($this->cmdManager->getPermSetMappings()->where("source", $source)->isNotEmpty()) {
			$context->reply("There is already a permission set map for <highlight>{$source}<end>.");
			return;
		}
		$permset = strtolower($permset());
		if (!$this->cmdManager->hasChannel($permset)) {
			$context->reply("There is no permission set <highlight>{$permset}<end>.");
			return;
		}
		$srcValid = (new Collection($this->cmdManager->getSources()))
			->filter(function (string $mask) use ($source): bool {
				return fnmatch($mask, $source, FNM_CASEFOLD);
			})->isNotEmpty();
		if (!$srcValid) {
			$context->reply(
				"There is no source providing <highlight>{$source}<end>. ".
				"See <highlight><symbol>cmdmap list src<end> for a list of sources."
			);
			return;
		}
		$map = new CmdPermSetMapping();
		$map->source = $source;
		$map->permission_set = $permset;
		$map->symbol = $this->settingManager->getString("symbol") ?? "!";
		try {
			$map->id = $this->db->insert(CommandManager::DB_TABLE_MAPPING, $map);
		} catch (SQLException) {
			$context->reply("There was an error saving your mapping into the database.");
			return;
		}
		$this->cmdManager->loadPermsetMappings();
		$context->reply(
			$this->text->blobWrap(
				"Mapping from {$source} to {$permset} created. ",
				$this->text->makeBlob(
					"Configure it",
					$this->renderPermSetMapping($map),
					"Configure your mapping"
				)
			)
		);
	}

	#[NCA\HandlesCommand("cmdmap")]
	public function cmdmapListSourcesCommand(
		CmdContext $context,
		#[NCA\Str("list")] string $action,
		#[NCA\Str("src", "source", "sources")] string $subaction,
	): void {
		$sources = (new Collection($this->cmdManager->getSources()))->sort();
		$blob = "<header2>Registered sources<end>\n".
			"<tab>" . $sources->join("\n<tab>");
		$context->reply(
			$this->text->makeBlob(
				"Registered cmd sources (" . $sources->count() . ")",
				$blob
			)
		);
	}

	#[NCA\HandlesCommand("cmdmap")]
	public function cmdmapDeleteCommand(
		CmdContext $context,
		PRemove $action,
		string $source,
	): void {
		$source = strtolower($source);
		if (!$this->cmdManager->deletePermissionSetMapping($source)) {
			$context->reply("There is no permission set map for <highlight>{$source}<end>.");
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

	#[NCA\HandlesCommand("cmdmap")]
	public function cmdmapPickPermsetCommand(
		CmdContext $context,
		#[NCA\Str("permset")] string $action,
		#[NCA\Str("pick")] string $subAction,
		string $source
	): void {
		$source = strtolower($source);
		/** @var null|CmdPermSetMapping */
		$set = $this->cmdManager->getPermSetMappings()->where("source", $source)->first();
		if (!isset($set)) {
			$context->reply("There is currently no permission set map for <highlight>{$source}<end>.");
			return;
		}
		$sets = $this->cmdManager->getPermissionSets();
		$choices = $sets->map(function(CmdPermissionSet $set) use ($source): string {
			return "<tab>" . $this->text->makeChatcmd($set->name, "/tell <myname> permset set {$source} {$set->name}");
		})->join("\n");
		$context->reply(
			$this->text->makeBlob(
				"Choose a permission set for {$source}",
				"<header2>Available permission sets<end>\n" . $choices
			)
		);
	}

	#[NCA\HandlesCommand("cmdmap")]
	public function cmdmapPickSymbolCommand(
		CmdContext $context,
		#[NCA\Str("symbol")] string $action,
		#[NCA\Str("pick")] string $subAction,
		string $source
	): void {
		$source = strtolower($source);
		/** @var null|CmdPermSetMapping */
		$set = $this->cmdManager->getPermSetMappings()->where("source", $source)->first();
		if (!isset($set)) {
			$context->reply("There is currently no permission set map for <highlight>{$source}<end>.");
			return;
		}
		/** @var ?Setting $row */
		$row = $this->db->table(SettingManager::DB_TABLE)
			->where("name", "symbol")
			->asObj(Setting::class)
			->first();
		if ($row === null || !isset($row->options) || !strlen($row->options)) {
			$msg = "Could not find setting <highlight>symbol<end>.";
			$context->reply($msg);
			return;
		}
		$choices = (new Collection(explode(";", $row->options)))->map(function (string $option) use ($source): string {
			return "<tab><highlight>{$option}<end> [" . $this->text->makeChatcmd("use this", "/tell <myname> symbol set {$source} {$option}") . "]";
		})->join("\n");
		$context->reply(
			$this->text->makeBlob(
				"Choose a symbol for {$source}",
				"<header2>Available symbols<end>\n" . $choices
			)
		);
	}

	#[NCA\HandlesCommand("cmdmap")]
	public function cmdmapSetPermsetCommand(
		CmdContext $context,
		#[NCA\Str("permset")] string $action,
		#[NCA\Str("set")] string $subAction,
		string $source,
		PWord $permset
	): void {
		$permset = strtolower($permset());
		if ($this->cmdManager->getPermissionSets()->where("name", $permset)->isEmpty()) {
			$context->reply("The permission set <highlight>{$permset}<end> doesn't exist.");
			return;
		}
		$this->changeCmdMap($context, $source, function(CmdPermSetMapping $set) use ($permset): void {
			$set->permission_set = $permset;
		});
	}

	#[NCA\HandlesCommand("cmdmap")]
	public function cmdmapSetSymbolCommand(
		CmdContext $context,
		#[NCA\Str("symbol")] string $action,
		#[NCA\Str("set")] string $subAction,
		string $source,
		string $symbol
	): void {
		$this->changeCmdMap($context, $source, function(CmdPermSetMapping $set) use ($symbol): void {
			$set->symbol = $symbol;
		});
	}

	#[NCA\HandlesCommand("cmdmap")]
	public function cmdmapChangeSymbolOptionalCommand(
		CmdContext $context,
		#[NCA\Str("symbolopt")] string $action,
		#[NCA\Str("set")] string $subAction,
		string $source,
		bool $optional
	): void {
		$this->changeCmdMap($context, $source, function(CmdPermSetMapping $set) use ($optional): void {
			$set->symbol_optional = $optional;
		});
	}

	#[NCA\HandlesCommand("cmdmap")]
	public function cmdmapChangeFeedbackCommand(
		CmdContext $context,
		#[NCA\Str("feedback")] string $action,
		#[NCA\Str("set")] string $subAction,
		string $source,
		bool $feedback
	): void {
		$this->changeCmdMap($context, $source, function(CmdPermSetMapping $set) use ($feedback): void {
			$set->feedback = $feedback;
		});
	}

	/**
	 * Change one or more attributes of a command mapping
	 *
	 * @phpstan-param Closure(CmdPermSetMapping):void $callback
	 */
	protected function changeCmdMap(CmdContext $context, string $source, Closure $callback): void {
		$source = strtolower($source);
		/** @var null|CmdPermSetMapping */
		$set = $this->cmdManager->getPermSetMappings()->where("source", $source)->first();
		if (!isset($set)) {
			$context->reply("There is currently no permission set map for <highlight>{$source}<end>.");
			return;
		}
		$callback($set);
		$this->db->update(CommandManager::DB_TABLE_MAPPING, "id", $set);
		$this->cmdManager->loadPermsetMappings();
		$context->reply("Setting changed for <highlight>{$source}<end>.");
	}
}
