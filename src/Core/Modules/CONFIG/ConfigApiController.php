<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\Attributes as NCA;
use Exception;
use Nadybot\Core\{
	CommandManager,
	DB,
	DBSchema\EventCfg,
	DBSchema\Setting,
	EventManager,
	HelpManager,
	ModuleInstance,
	InsufficientAccessException,
	SettingManager,
};

use Nadybot\Modules\{
	DISCORD_GATEWAY_MODULE\DiscordRelayController,
	WEBSERVER_MODULE\ApiResponse,
	WEBSERVER_MODULE\HttpProtocolWrapper,
	WEBSERVER_MODULE\Request,
	WEBSERVER_MODULE\Response,
};
use Nadybot\Modules\WEBSERVER_MODULE\WebChatConverter;

/**
 * @package Nadybot\Core\Modules\CONFIG
 */
#[NCA\Instance]
class ConfigApiController extends ModuleInstance {
	#[NCA\Inject]
	public DiscordRelayController $discordRelayController;

	#[NCA\Inject]
	public ConfigController $configController;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public HelpManager $helpManager;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public WebChatConverter $webChatConverter;

	#[NCA\Inject]
	public DB $db;

	/**
	 * Get a list of available modules to configure
	 */
	#[
		NCA\Api("/module"),
		NCA\GET,
		NCA\AccessLevel("mod"),
		NCA\ApiResult(code: 200, class: "ConfigModule[]", desc: "A list of modules to configure")
	]
	public function moduleGetEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse($this->configController->getModules());
	}

	/**
	 * Activate or deactivate an event
	 */
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

	/**
	 * Change a setting's value
	 */
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
					$modSet::TYPE_TIME
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

	/**
	 * Activate or deactivate a Command
	 */
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
			if (isset($exception)) {
				return new Response(Response::INTERNAL_SERVER_ERROR);
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

	/**
	 * Activate or deactivate a command
	 */
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

	/**
	 * Activate or deactivate a module
	 */
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

	/**
	 * Get the description of a module
	 */
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

	/**
	 * Get a list of available settings for a module
	 */
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

	/**
	 * Get a list of available events for a module
	 */
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

	/**
	 * Get a list of available commands for a module
	 */
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

	/**
	 * Get a list of available events for a module
	 */
	#[
		NCA\Api("/access_levels"),
		NCA\GET,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 200, class: "ModuleAccessLevel[]", desc: "A list of all access levels")
	]
	public function apiConfigAccessLevelsGetEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse($this->configController->getValidAccessLevels());
	}
}
