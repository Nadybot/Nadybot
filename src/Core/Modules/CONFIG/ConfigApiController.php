<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CommandManager,
	DB,
	DBSchema\CmdPermSetMapping,
	DBSchema\CmdPermissionSet,
	DBSchema\EventCfg,
	DBSchema\Setting,
	EventManager,
	HelpManager,
	InsufficientAccessException,
	ModuleInstance,
	SQLException,
	SettingManager,
};
use Nadybot\Modules\{
	DISCORD_GATEWAY_MODULE\DiscordRelayController,
	WEBSERVER_MODULE\ApiResponse,
	WEBSERVER_MODULE\HttpProtocolWrapper,
	WEBSERVER_MODULE\JsonImporter,
	WEBSERVER_MODULE\Request,
	WEBSERVER_MODULE\Response,
	WEBSERVER_MODULE\WebChatConverter,
};
use Throwable;

/**
 * @package Nadybot\Core\Modules\CONFIG
 */
#[NCA\Instance]
class ConfigApiController extends ModuleInstance {
	#[NCA\Inject]
	private DiscordRelayController $discordRelayController;

	#[NCA\Inject]
	private ConfigController $configController;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private HelpManager $helpManager;

	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Inject]
	private WebChatConverter $webChatConverter;

	#[NCA\Inject]
	private DB $db;

	/** Get a list of available modules to configure */
	#[
		NCA\Api("/module"),
		NCA\GET,
		NCA\AccessLevel("mod"),
		NCA\ApiResult(code: 200, class: "ConfigModule[]", desc: "A list of modules to configure")
	]
	public function moduleGetEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse($this->configController->getModules());
	}

	/** Activate or deactivate an event */
	#[
		NCA\Api("/module/%s/events/%s/%s"),
		NCA\PATCH,
		NCA\PUT,
		NCA\AccessLevel("mod"),
		NCA\RequestBody(class: "Operation", desc: "Either \"enable\" or \"disable\"", required: true),
		NCA\ApiResult(code: 204, desc: "operation applied successfully"),
		NCA\ApiResult(code: 402, desc: "Wrong or no operation given"),
		NCA\ApiResult(code: 404, desc: "Module or Event not found")
	]
	public function toggleEventStatusEndpoint(Request $request, HttpProtocolWrapper $server, string $module, string $event, string $handler): Response {
		if (!is_object($request->decodedBody) || !isset($request->decodedBody->op)) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		$op = $request->decodedBody->op;
		if (!in_array($op, ["enable", "disable"], true)) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}

		try {
			if (!$this->configController->toggleEvent($event, $handler, $op === "enable")) {
				return new Response(
					Response::NOT_FOUND,
					['Content-Type' => 'text/plain'],
					"Event or handler not found"
				);
			}
		} catch (Exception $e) {
			return new Response(
				Response::UNPROCESSABLE_ENTITY,
				['Content-Type' => 'text/plain'],
				$e->getMessage()
			);
		}
		return new Response(Response::NO_CONTENT);
	}

	/** Change a setting's value */
	#[
		NCA\Api("/module/%s/settings/%s"),
		NCA\PATCH,
		NCA\PUT,
		NCA\AccessLevel("mod"),
		NCA\RequestBody(class: "string|bool|int", desc: "New value for the setting", required: true),
		NCA\ApiResult(code: 204, desc: "operation applied successfully"),
		NCA\ApiResult(code: 404, desc: "Wrong module or setting"),
		NCA\ApiResult(code: 422, desc: "Invalid value given")
	]
	public function changeModuleSettingEndpoint(Request $request, HttpProtocolWrapper $server, string $module, string $setting): Response {
		/** @var Setting|null */
		$oldSetting = $this->db->table(SettingManager::DB_TABLE)
			->where("name", $setting)->where("module", $module)
			->asObj(Setting::class)->first();
		if ($oldSetting === null) {
			return new Response(Response::NOT_FOUND);
		}
		$settingHandler = $this->settingManager->getSettingHandler($oldSetting);
		if (!isset($settingHandler)) {
			return new Response(Response::INTERNAL_SERVER_ERROR);
		}
		$modSet = new ModuleSetting($oldSetting);
		$value = $request->decodedBody ?? null;
		if (!is_string($value) && !is_int($value) && !is_bool($value)) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		if ($modSet->type === $modSet::TYPE_BOOL) {
			if (!is_bool($value)) {
				return new Response(
					Response::UNPROCESSABLE_ENTITY,
					["Content-Type" => "text/plain"],
					"Bool value required"
				);
			}
			$value = $value ? "1" : "0";
		} elseif (
			in_array(
				$modSet->type,
				[
					$modSet::TYPE_INT_OPTIONS,
					$modSet::TYPE_NUMBER,
					$modSet::TYPE_TIME,
				]
			)
		) {
			if (!is_int($value)) {
				return new Response(
					Response::UNPROCESSABLE_ENTITY,
					["Content-Type" => "text/plain"],
					"Integer value required"
				);
			}
		} elseif (!is_string($value)) {
			return new Response(
				Response::UNPROCESSABLE_ENTITY,
				["Content-Type" => "text/plain"],
				"String value required"
			);
		}
		if ($modSet->type === $modSet::TYPE_COLOR) {
			if (is_string($value) && preg_match("/(#[0-9a-fA-F]{6})/", $value, $matches)) {
				$value = "<font color='{$matches[1]}'>";
			}
		}
		try {
			$newValueToSave = $settingHandler->save((string)$value);
			if (!$this->settingManager->save($setting, $newValueToSave)) {
				return new Response(Response::NOT_FOUND);
			}
		} catch (Exception $e) {
			return new Response(
				Response::UNPROCESSABLE_ENTITY,
				["Content-Type" => "text/plain"],
				"Invalid value: " . $e->getMessage()
			);
		}
		return new Response(Response::NO_CONTENT);
	}

	/** Activate or deactivate a Command */
	#[
		NCA\Api("/module/%s/commands/%s/%s"),
		NCA\PATCH,
		NCA\PUT,
		NCA\AccessLevel("mod"),
		NCA\RequestBody(class: "ModuleSubcommandChannel", desc: "Parameters to change", required: true),
		NCA\ApiResult(code: 200, class: "ModuleCommand", desc: "operation applied successfully"),
		NCA\ApiResult(code: 422, desc: "Wrong or no operation given")
	]
	public function toggleCommandChannelSettingsEndpoint(Request $request, HttpProtocolWrapper $server, string $module, string $command, string $channel): Response {
		/** @var ModuleSubcommandChannel */
		$body = $request->decodedBody ?? [];
		$subCmd = (bool)preg_match("/\s/", $command);
		$result = 0;
		$parsed = 0;
		$exception = null;
		if (isset($body->access_level)) {
			$parsed++;
			try {
				if ($subCmd) {
					$result += (int)($this->configController->changeSubcommandAL($request->authenticatedAs??"_", $command, $channel, $body->access_level) === 1);
				} else {
					$result += (int)($this->configController->changeCommandAL($request->authenticatedAs??"_", $command, $channel, $body->access_level) === 1);
				}
			} catch (Exception $e) {
				$exception = $e;
			}
		}
		if (isset($body->enabled)) {
			$parsed++;
			$result += (int)$this->configController->toggleCmd($request->authenticatedAs??"_", $subCmd, $command, $channel, $body->enabled);
		}
		if ($parsed === 0) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		if ($parsed === 1) {
			if (isset($exception) && $exception instanceof InsufficientAccessException) {
				return new Response(Response::FORBIDDEN);
			}
			if (isset($exception) && $exception instanceof Exception) {
				return new Response(Response::UNPROCESSABLE_ENTITY);
			}
		}
		if ($result === 0 && !isset($exception)) {
			return new Response(Response::NOT_FOUND);
		}
		$cmd = $this->commandManager->get($command);
		if (!isset($cmd) || $cmd->module !== $module) {
			return new Response(Response::NOT_FOUND);
		}
		$moduleCommand = new ModuleCommand($cmd);
		return new ApiResponse($moduleCommand);
	}

	/** Activate or deactivate a command */
	#[
		NCA\Api("/module/%s/commands/%s"),
		NCA\PATCH,
		NCA\PUT,
		NCA\AccessLevel("mod"),
		NCA\RequestBody(class: "Operation", desc: "Either \"enable\" or \"disable\"", required: true),
		NCA\ApiResult(code: 200, desc: "operation applied successfully"),
		NCA\ApiResult(code: 402, desc: "Wrong or no operation given")
	]
	public function toggleCommandStatusEndpoint(Request $request, HttpProtocolWrapper $server, string $module, string $command): Response {
		if (!is_object($request->decodedBody) || !isset($request->decodedBody->op)) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		$op = $request->decodedBody->op;
		if (!in_array($op, ["enable", "disable"], true)) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		$subCmd = (bool)preg_match("/\s/", $command);
		try {
			if ($this->configController->toggleCmd($request->authenticatedAs??"_", $subCmd, $command, "all", $op === "enable") === true) {
				$cmd = $this->commandManager->get($command);
				if (!isset($cmd) || $cmd->module !== $module) {
					return new Response(Response::NOT_FOUND);
				}
				return new ApiResponse(new ModuleSubcommand($cmd));
			}
		} catch (InsufficientAccessException $e) {
			return new Response(Response::FORBIDDEN);
		} catch (Exception $e) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		return new Response(Response::NOT_FOUND);
	}

	/** Activate or deactivate a module */
	#[
		NCA\Api("/module/%s"),
		NCA\PATCH,
		NCA\PUT,
		NCA\AccessLevel("mod"),
		NCA\RequestBody(class: "Operation", desc: "Either \"enable\" or \"disable\"", required: true),
		NCA\QueryParam(name: "channel", desc: "Either \"msg\", \"priv\", \"guild\" or \"all\""),
		NCA\ApiResult(code: 204, desc: "operation applied successfully"),
		NCA\ApiResult(code: 402, desc: "Wrong or no operation given")
	]
	public function toggleModuleStatusEndpoint(Request $request, HttpProtocolWrapper $server, string $module): Response {
		if (!is_object($request->decodedBody) || !isset($request->decodedBody->op)) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		$op = $request->decodedBody->op;
		if (!in_array($op, ["enable", "disable"], true)) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		$channel = $request->query["channel"] ?? "all";
		$channels = $this->commandManager->getPermissionSets()->pluck("name")->toArray();
		if ($channel !== "all" && !in_array($channel, $channels, true)) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		if ($this->configController->toggleModule($module, $channel, $op === "enable")) {
			return new Response(Response::NO_CONTENT);
		}
		return new Response(Response::NOT_FOUND);
	}

	/** Get the description of a module */
	#[
		NCA\Api("/module/%s/description"),
		NCA\GET,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 200, class: "string", desc: "A description of the module"),
		NCA\ApiResult(code: 204, desc: "No description set")
	]
	public function apiModuleDescriptionGetEndpoint(Request $request, HttpProtocolWrapper $server, string $module): Response {
		$description = $this->configController->getModuleDescription($module);
		if (!isset($description)) {
			return new Response(Response::NO_CONTENT);
		}
		return new ApiResponse($description);
	}

	/** Get a list of available settings for a module */
	#[
		NCA\Api("/module/%s/settings"),
		NCA\GET,
		NCA\AccessLevel("mod"),
		NCA\ApiResult(code: 200, class: "ModuleSetting[]", desc: "A list of all settings for this module")
	]
	public function apiConfigSettingsGetEndpoint(Request $request, HttpProtocolWrapper $server, string $module): Response {
		$settings = $this->configController->getModuleSettings($module);
		$result = [];
		foreach ($settings as $setting) {
			$modSet = new ModuleSetting($setting->getData());
			if (strlen($modSet->description??"") > 0) {
				$modSet->description = $this->webChatConverter->parseAOFormat(trim($modSet->description))->message;
				$modSet->description = str_replace("<br />", " ", $modSet->description);
				$modSet->description = htmlspecialchars_decode($modSet->description);
			}
			if (strlen($setting->getData()->help??"") > 0) {
				$help = $this->helpManager->find($modSet->name, $request->authenticatedAs??"_");
				if ($help !== null) {
					$modSet->help = $this->webChatConverter->toXML(
						$this->webChatConverter->parseAOFormat(
							trim($help)
						)
					);
				}
			}
			if ($modSet->type === $modSet::TYPE_DISCORD_CHANNEL) {
				$modSet->options = $this->discordRelayController->getChannelOptionList();
			}
			$result[] = $modSet;
		}
		return new ApiResponse($result);
	}

	/** Get a list of available events for a module */
	#[
		NCA\Api("/module/%s/events"),
		NCA\GET,
		NCA\AccessLevel("mod"),
		NCA\ApiResult(code: 200, class: "ModuleEventConfig[]", desc: "A list of all events and their status for this module")
	]
	public function apiConfigEventsGetEndpoint(Request $request, HttpProtocolWrapper $server, string $module): Response {
		$events = $this->db->table(EventManager::DB_TABLE)
			->where("type", "!=", "setup")
			->where("module", $module)
			->asObj(EventCfg::class)
			->map(function (EventCfg $event): ModuleEventConfig {
				return new ModuleEventConfig($event);
			});
		return new ApiResponse($events->toArray());
	}

	/** Get a list of available commands for a module */
	#[
		NCA\Api("/module/%s/commands"),
		NCA\GET,
		NCA\AccessLevel("mod"),
		NCA\ApiResult(code: 200, class: "ModuleCommand[]", desc: "A list of all command and possible subcommands this module provides")
	]
	public function apiConfigCommandsGetEndpoint(Request $request, HttpProtocolWrapper $server, string $module): Response {
		$cmds = $this->commandManager->getAllForModule($module, true)->sortBy("cmdevent");

		/** @var array<string,ModuleCommand> */
		$result = [];
		foreach ($cmds as $cmd) {
			if ($cmd->cmdevent === "cmd") {
				$result[$cmd->cmd] = new ModuleCommand($cmd);
			} else {
				$result[$cmd->dependson]->subcommands []= new ModuleSubcommand($cmd);
			}
		}
		return new ApiResponse(array_values($result));
	}

	/** Get a list of configured access levels */
	#[
		NCA\Api("/access_levels"),
		NCA\GET,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 200, class: "ModuleAccessLevel[]", desc: "A list of all access levels")
	]
	public function apiConfigAccessLevelsGetEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse($this->configController->getValidAccessLevels());
	}

	/** Get a list of permission sets */
	#[
		NCA\Api("/permission_set"),
		NCA\GET,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 200, class: "ExtCmdPermissionSet[]", desc: "A list of permission sets")
	]
	public function apiConfigPermissionSetGetEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse($this->commandManager->getExtPermissionSets()->toArray());
	}

	/** Get a permission set by its name */
	#[
		NCA\Api("/permission_set/%s"),
		NCA\GET,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 200, class: "ExtCmdPermissionSet", desc: "A permission set")
	]
	public function apiConfigPermissionSetGetByNameEndpoint(Request $request, HttpProtocolWrapper $server, string $name): Response {
		$set = $this->commandManager->getExtPermissionSet($name);
		if (!isset($set)) {
			return new Response(Response::NOT_FOUND);
		}
		return new ApiResponse($set);
	}

	/** Create a new permission set */
	#[
		NCA\Api("/permission_set"),
		NCA\POST,
		NCA\RequestBody(class: "CmdPermissionSet", desc: "The new permission set", required: true),
		NCA\AccessLevel("superadmin"),
		NCA\ApiResult(code: 204, desc: "Permission Set created successfully")
	]
	public function apiConfigPermissionSetCreateEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		$set = $request->decodedBody;
		try {
			if (!is_object($set)) {
				throw new Exception("Wrong content body");
			}

			/** @var CmdPermissionSet */
			$decoded = JsonImporter::convert(CmdPermissionSet::class, $set);
		} catch (Throwable $e) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		try {
			$this->commandManager->createPermissionSet($decoded->name, $decoded->letter);
		} catch (Exception $e) {
			return new Response(Response::UNPROCESSABLE_ENTITY, [], $e->getMessage());
		}
		return new Response(Response::NO_CONTENT);
	}

	/** Change a permission set */
	#[
		NCA\Api("/permission_set/%s"),
		NCA\PATCH,
		NCA\RequestBody(class: "CmdPermissionSet", desc: "The new permission set data", required: true),
		NCA\AccessLevel("superadmin"),
		NCA\ApiResult(code: 204, class: "ExtCmdPermissionSet", desc: "Permission Set changed successfully")
	]
	public function apiConfigPermissionSetPatchEndpoint(Request $request, HttpProtocolWrapper $server, string $name): Response {
		$set = $request->decodedBody;
		try {
			if (!is_object($set)) {
				throw new Exception("Wrong content body");
			}

			/** @var CmdPermissionSet */
			$decoded = JsonImporter::convert(CmdPermissionSet::class, $set);
		} catch (Throwable $e) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		$old = $this->commandManager->getPermissionSet($name);
		if (!isset($old)) {
			return new Response(Response::NOT_FOUND);
		}
		foreach (get_object_vars($decoded) as $key => $value) {
			$old->{$key} = $value;
		}
		try {
			$this->commandManager->changePermissionSet($name, $old);
		} catch (Exception $e) {
			return new Response(Response::UNPROCESSABLE_ENTITY, [], $e->getMessage());
		}
		return new ApiResponse($this->commandManager->getExtPermissionSet($old->name));
	}

	/** Get a list of command sources */
	#[
		NCA\Api("/cmd_source"),
		NCA\GET,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 200, class: "CmdSource[]", desc: "A list of command sources and their mappings")
	]
	public function apiConfigCmdSrcGetEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		$sources = $this->commandManager->getSources();
		$maps = $this->getCmdSourceMappings()->groupBy("source");
		$result = [];
		foreach ($sources as $source) {
			$cmdSrc = CmdSource::fromMask($source);
			$cmdSrc->mappings = $maps->get($cmdSrc->source, new Collection())->toArray();
			$result []= $cmdSrc;
		}
		return new ApiResponse($result);
	}

	/** Get details for a specific command source */
	#[
		NCA\Api("/cmd_source/%s"),
		NCA\GET,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 200, class: "CmdSource", desc: "The command source and its mappings")
	]
	public function apiConfigCmdSrcDetailGetEndpoint(Request $request, HttpProtocolWrapper $server, string $source): Response {
		$cmdSrc = $this->getCmdSource(strtolower($source));
		if (!isset($cmdSrc)) {
			return new Response(Response::NOT_FOUND);
		}
		$cmdSrc->mappings = $this->getCmdSourceMappings($source)->toArray();
		return new ApiResponse($cmdSrc);
	}

	/** Get mappings for a specific command source */
	#[
		NCA\Api("/cmd_source/%s/mappings"),
		NCA\GET,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 200, class: "CmdSourceMapping[]", desc: "The command source's mappings")
	]
	public function apiConfigCmdSrcMappingsGetEndpoint(Request $request, HttpProtocolWrapper $server, string $source): Response {
		$cmdSrc = $this->getCmdSource(strtolower($source));
		if (!isset($cmdSrc)) {
			return new Response(Response::NOT_FOUND);
		}
		return new ApiResponse($this->getCmdSourceMappings($source)->toArray());
	}

	/** Get mappings for a specific command source */
	#[
		NCA\Api("/cmd_source/%s/mappings/%s"),
		NCA\GET,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 200, class: "CmdSourceMapping", desc: "The command's sub-source mapping")
	]
	public function apiConfigCmdSrcSubMappingGetEndpoint(Request $request, HttpProtocolWrapper $server, string $source, string $subSource): Response {
		$cmdSrc = $this->getCmdSource(strtolower($source));
		if (!isset($cmdSrc)) {
			return new Response(Response::NOT_FOUND);
		}
		$mappings = $this->getCmdSourceMappings($source);
		$mapping = $mappings->where("sub_source", strtolower($subSource))->first();
		if (!isset($mapping)) {
			return new Response(Response::NOT_FOUND);
		}
		return new ApiResponse($mapping);
	}

	/** Delete mapping for a specific command sub-source */
	#[
		NCA\Api("/cmd_source/%s/mappings/%s"),
		NCA\DELETE,
		NCA\AccessLevel("superadmin"),
		NCA\ApiResult(code: 204, desc: "The sub-source mapping was deleted successfully")
	]
	public function apiConfigCmdSrcSubMappingDeleteEndpoint(Request $request, HttpProtocolWrapper $server, string $source, string $subSource): Response {
		$cmdSrc = $this->getCmdSource(strtolower($source));
		if (!isset($cmdSrc)) {
			return new Response(Response::NOT_FOUND);
		}
		$fullSource = strtolower("{$source}({$subSource})");
		try {
			if (!$this->commandManager->deletePermissionSetMapping($fullSource)) {
				return new Response(Response::NOT_FOUND);
			}
		} catch (Exception $e) {
			return new Response(Response::FORBIDDEN);
		}
		return new Response(Response::NO_CONTENT);
	}

	/** Delete mapping for a specific command source */
	#[
		NCA\Api("/cmd_source/%s/mappings"),
		NCA\DELETE,
		NCA\AccessLevel("superadmin"),
		NCA\ApiResult(code: 204, desc: "The source mapping was deleted successfully")
	]
	public function apiConfigCmdSrcMappingDeleteEndpoint(Request $request, HttpProtocolWrapper $server, string $source): Response {
		$cmdSrc = $this->getCmdSource(strtolower($source));
		if (!isset($cmdSrc) || $cmdSrc->has_sub_sources) {
			return new Response(Response::NOT_FOUND);
		}
		try {
			if (!$this->commandManager->deletePermissionSetMapping(strtolower($source))) {
				return new Response(Response::NOT_FOUND);
			}
		} catch (Exception $e) {
			return new Response(Response::FORBIDDEN);
		}
		return new Response(Response::NO_CONTENT);
	}

	/** Create a new mapping */
	#[
		NCA\Api("/cmd_source/%s/mappings"),
		NCA\POST,
		NCA\AccessLevel("superadmin"),
		NCA\RequestBody(class: "CmdSourceMapping", desc: "The new mapping", required: true),
		NCA\ApiResult(code: 204, desc: "A new command mapping was created")
	]
	public function apiConfigCmdSrcNewMappingEndpoint(Request $request, HttpProtocolWrapper $server, string $source): Response {
		$cmdSrc = $this->getCmdSource(strtolower($source));
		if (!isset($cmdSrc)) {
			return new Response(Response::NOT_FOUND);
		}
		$set = $request->decodedBody;
		try {
			if (!is_object($set)) {
				throw new Exception("Wrong content body");
			}

			/** @var CmdSourceMapping */
			$decoded = JsonImporter::convert(CmdSourceMapping::class, $set);
			$decoded->source = $source;
		} catch (Throwable $e) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		return $this->createCmdSourceMapping($decoded);
	}

	/** Modify mapping for a specific command source */
	#[
		NCA\Api("/cmd_source/%s/mappings"),
		NCA\PUT,
		NCA\AccessLevel("superadmin"),
		NCA\ApiResult(code: 200, class: "CmdSourceMapping", desc: "The new, modified source mapping")
	]
	public function apiConfigCmdSrcMappingPutEndpoint(Request $request, HttpProtocolWrapper $server, string $source): Response {
		$cmdSrc = $this->getCmdSource(strtolower($source));
		if (!isset($cmdSrc) || $cmdSrc->has_sub_sources) {
			return new Response(Response::NOT_FOUND);
		}
		$set = $request->decodedBody;
		try {
			if (!is_object($set)) {
				throw new Exception("Wrong content body");
			}

			/** @var CmdSourceMapping */
			$decoded = JsonImporter::convert(CmdSourceMapping::class, $set);
			$decoded->source = strtolower($source);
			$decoded->sub_source = null;
		} catch (Throwable $e) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		return $this->modifyCmdSourceMapping($decoded);
	}

	/** Modify mapping for a specific command source */
	#[
		NCA\Api("/cmd_source/%s/mappings/%s"),
		NCA\PUT,
		NCA\AccessLevel("superadmin"),
		NCA\ApiResult(code: 200, class: "CmdSourceMapping", desc: "The new, modified source mapping")
	]
	public function apiConfigCmdSubSrcMappingPutEndpoint(Request $request, HttpProtocolWrapper $server, string $source, string $subSource): Response {
		$cmdSrc = $this->getCmdSource(strtolower($source));
		if (!isset($cmdSrc) || !$cmdSrc->has_sub_sources) {
			return new Response(Response::NOT_FOUND);
		}
		$set = $request->decodedBody;
		try {
			if (!is_object($set)) {
				throw new Exception("Wrong content body");
			}

			/** @var CmdSourceMapping */
			$decoded = JsonImporter::convert(CmdSourceMapping::class, $set);
			$decoded->source = strtolower($source);
			$decoded->sub_source = strtolower($subSource);
		} catch (Throwable $e) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		return $this->modifyCmdSourceMapping($decoded);
	}

	/** @return Collection<CmdSourceMapping> */
	protected function getCmdSourceMappings(?string $source=null): Collection {
		/** @var Collection<CmdSourceMapping> */
		$maps = $this->commandManager->getPermSetMappings()
			->map([CmdSourceMapping::class, "fromPermSetMapping"]);
		if (isset($source)) {
			return $maps->where("source", $source)->values();
		}
		return $maps;
	}

	protected function getCmdSource(string $sourceName): ?CmdSource {
		$sourceName = strtolower($sourceName);
		$sources = new Collection($this->commandManager->getSources());
		$source = $sources->first(function (string $source) use ($sourceName): bool {
			return preg_replace("/\(.+$/", "", $source) === $sourceName;
		});
		return isset($source) ? CmdSource::fromMask($source) : null;
	}

	protected function createCmdSourceMapping(CmdSourceMapping $decoded): Response {
		try {
			$cmdSrc = $this->getCmdSource($decoded->source);
			if (!isset($cmdSrc)) {
				throw new Exception("No command source {$decoded->source} found.");
			}
			$source = $decoded->source;
			if ($cmdSrc->has_sub_sources) {
				if (!isset($decoded->sub_source) || !strlen($decoded->sub_source)) {
					$source .= "(*)";
				} else {
					$source .= "({$decoded->sub_source})";
				}
			}
			$source = strtolower($source);
			if ($this->commandManager->getPermSetMappings()->where("source", $source)->isNotEmpty()) {
				return new Response(Response::CONFLICT);
			}
			$decoded->permission_set = strtolower($decoded->permission_set);
			if (!$this->commandManager->hasPermissionSet($decoded->permission_set)) {
				return new Response(
					Response::UNPROCESSABLE_ENTITY,
					["Content-type" => "text/plain"],
					"There is no permission set {$decoded->permission_set}."
				);
			}
			$map = $decoded->toPermSetMapping();
			try {
				$map->id = $this->db->insert(CommandManager::DB_TABLE_MAPPING, $map);
			} catch (SQLException $e) {
				return new Response(
					Response::INTERNAL_SERVER_ERROR,
					["Content-type" => "text/plain"],
					$e->getMessage()
				);
			}
			$this->commandManager->loadPermsetMappings();
		} catch (Exception $e) {
			return new Response(
				Response::UNPROCESSABLE_ENTITY,
				["Content-type" => "text/plain"],
				$e->getMessage()
			);
		}
		return new Response(Response::NO_CONTENT);
	}

	protected function modifyCmdSourceMapping(CmdSourceMapping $decoded): Response {
		try {
			$cmdSrc = $this->getCmdSource($decoded->source);
			if (!isset($cmdSrc)) {
				throw new Exception("No command source {$decoded->source} found.");
			}
			$source = $decoded->source;
			if ($cmdSrc->has_sub_sources) {
				if (!isset($decoded->sub_source) || !strlen($decoded->sub_source)) {
					return new Response(Response::NOT_FOUND);
				}
				$source .= "({$decoded->sub_source})";
			}
			$source = strtolower($source);

			/** @var ?CmdPermSetMapping */
			$old = $this->commandManager->getPermSetMappings()->where("source", $source)->first();
			if (!isset($old)) {
				return new Response(Response::NOT_FOUND);
			}
			$decoded->permission_set = strtolower($decoded->permission_set);
			if (!$this->commandManager->hasPermissionSet($decoded->permission_set)) {
				return new Response(
					Response::UNPROCESSABLE_ENTITY,
					["Content-type" => "text/plain"],
					"There is no permission set {$decoded->permission_set}."
				);
			}
			$map = $decoded->toPermSetMapping();
			$map->id = $old->id;
			try {
				$map->id = $this->db->update(CommandManager::DB_TABLE_MAPPING, "id", $map);
			} catch (SQLException $e) {
				return new Response(
					Response::INTERNAL_SERVER_ERROR,
					["Content-type" => "text/plain"],
					$e->getMessage()
				);
			}
			$this->commandManager->loadPermsetMappings();
		} catch (Exception $e) {
			return new Response(
				Response::UNPROCESSABLE_ENTITY,
				["Content-type" => "text/plain"],
				$e->getMessage()
			);
		}
		return new ApiResponse(CmdSourceMapping::fromPermSetMapping($map));
	}
}
