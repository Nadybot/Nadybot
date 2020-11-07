<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Exception;
use Nadybot\Core\{
	DB,
	DBSchema\EventCfg,
	DBSchema\Setting,
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

/**
 * @Instance
 * @package Nadybot\Core\Modules\CONFIG
 */
class ConfigApiConroller {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DiscordRelayController $discordRelayController;

	/** @Inject */
	public ConfigController $configController;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public DB $db;

	/**
	 * Get a list of available modules to configure
	 * @Api("/module")
	 * @GET
	 * @AccessLevel("mod")
	 * @ApiResult(code=200, class='ConfigModule[]', desc='A list of modules to configure')
	 */
	public function moduleGetEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse($this->configController->getModules());
	}

	/**
	 * Change a setting's value
	 * @Api("/module/%s/settings/%s")
	 * @PATCH
	 * @PUT
	 * @AccessLevel("mod")
	 * @RequestBody(class='string|bool|int', desc='New value for the setting', required=true)
	 * @ApiResult(code=204, desc='operation applied successfully')
	 * @ApiResult(code=404, desc='Wrong module or setting')
	 * @ApiResult(code=422, desc='Invalid value given')
	 */
	public function changeModuleSettingEndpoint(Request $request, HttpProtocolWrapper $server, string $module, string $setting): Response {
		$sql = "SELECT * FROM `settings_<myname>` WHERE `name` = ? AND `module` = ?";
		/** @var Setting */
		$oldSetting = $this->db->fetch(Setting::class, $sql, $setting, $module);
		if ($oldSetting === null) {
			return new Response(Response::NOT_FOUND);
		}
		$settingHandler = $this->settingManager->getSettingHandler($oldSetting);
		$modSet = new ModuleSetting($oldSetting);
		$value = $request->decodedBody ?? null;
		if (!is_string($value) && !is_int($value) && !is_bool($value)) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		if ($modSet->type === $modSet::TYPE_BOOL) {
			if (!is_bool($value)) {
				return new Response(Response::UNPROCESSABLE_ENTITY, ["Content-type: text/plain"], "Bool value required");
			}
			$value = $value ? "1" : "0";
		} elseif (
			in_array(
				$modSet->type,
				[
					$modSet::TYPE_DISCORD_CHANNEL,
					$modSet::TYPE_INT_OPTIONS,
					$modSet::TYPE_NUMBER,
					$modSet::TYPE_TIME
				]
			)
		) {
			if (!is_int($value)) {
				return new Response(Response::UNPROCESSABLE_ENTITY, ["Content-type: text/plain"], "Integer value required");
			}
		} elseif (!is_string($value)) {
			return new Response(Response::UNPROCESSABLE_ENTITY, ["Content-type: text/plain"], "String value required");
		}
		try {
			$newValueToSave = $settingHandler->save((string)$value);
			if (!$this->settingManager->save($setting, $newValueToSave)) {
				return new Response(Response::NOT_FOUND);
			}
		} catch (Exception $e) {
			return new Response(Response::UNPROCESSABLE_ENTITY, ["Content-type: text/plain"], "Invalid value: " . $e->getMessage());
		}
		return new Response(Response::NO_CONTENT);
	}

	/**
	 * Activate or deactivate a Command
	 * @Api("/module/%s/commands/%s/%s")
	 * @PATCH
	 * @AccessLevel("mod")
	 * @RequestBody(class='ModuleSubcommandChannel', desc='Parameters to change', required=true)
	 * @ApiResult(code=200, class='ModuleCommand', desc='operation applied successfully')
	 * @ApiResult(code=422, desc='Wrong or no operation given')
	 */
	public function toggleCommandChannelSettingsEndpoint(Request $request, HttpProtocolWrapper $server, string $module, string $command, string $channel): Response {
		/** @var ModuleSubcommandChannel */
		$body = $request->decodedBody ?? [];
		$subCmd = (bool)preg_match("/\s/", $command);
		$result = 0;
		$parsed = 0;
		$exception = null;
		if ($channel === "org") {
			$channel = "guild";
		}
		if (isset($body->access_level)) {
			$parsed++;
			try {
				if ($subCmd) {
					$result += (int)($this->configController->changeSubcommandAL($request->authenticatedAs, $command, $channel, $body->access_level) === 1);
				} else {
					$result += (int)($this->configController->changeCommandAL($request->authenticatedAs, $command, $channel, $body->access_level) === 1);
				}
			} catch (Exception $e) {
				$exception = $e;
			}
		}
		if (isset($body->enabled)) {
			$parsed++;
			$result += (int)$this->configController->toggleCmd($request->authenticatedAs, $subCmd, $command, $channel, $body->enabled);
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
		$cmd = $this->configController->getRegisteredCommand($module, $command);
		if (!isset($cmd)) {
			return new Response(Response::NOT_FOUND);
		}
		if ($channel === "guild") {
			$channel = "org";
		}
		$moduleCommand = new ModuleCommand($cmd);
		return new ApiResponse($moduleCommand->{$channel});
	}

	/**
	 * Activate or deactivate a command
	 * @Api("/module/%s/commands/%s")
	 * @PATCH
	 * @PUT
	 * @AccessLevel("mod")
	 * @RequestBody(class='Operation', desc='Either "enable" or "disable"', required=true)
	 * @ApiResult(code=200, desc='operation applied successfully')
	 * @ApiResult(code=402, desc='Wrong or no operation given')
	 */
	public function toggleCommandStatusEndpoint(Request $request, HttpProtocolWrapper $server, string $module, string $command): Response {
		$op = $request->decodedBody->op ?? null;
		if (!in_array($op, ["enable", "disable"], true)) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		$subCmd = (bool)preg_match("/\s/", $command);
		try {
			if ($this->configController->toggleCmd($request->authenticatedAs, $subCmd, $command, "all", $op === "enable") === true) {
				$cmd = $this->configController->getRegisteredCommand($module, $command);
				if (!isset($cmd)) {
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
	 * @Api("/module/%s")
	 * @PATCH
	 * @PUT
	 * @AccessLevel("mod")
	 * @RequestBody(class='Operation', desc='Either "enable" or "disable"', required=true)
	 * @QueryParam(name='channel', type='string', desc='Either "msg", "priv", "guild" or "all"', required=false)
	 * @ApiResult(code=204, desc='operation applied successfully')
	 * @ApiResult(code=402, desc='Wrong or no operation given')
	 */
	public function toggleModuleStatusEndpoint(Request $request, HttpProtocolWrapper $server, string $module): Response {
		$op = $request->decodedBody->op ?? null;
		if (!in_array($op, ["enable", "disable"], true)) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		$channel = $request->query["channel"] ?? "all";
		if (!in_array($channel, ["all", "msg", "priv", "guild"])) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		if ($this->configController->toggleModule($module, $channel, $op === "enable")) {
			return new Response(Response::NO_CONTENT);
		}
		return new Response(Response::NOT_FOUND);
	}

	/**
	 * Get a list of available settings for a module
	 * @Api("/module/%s/settings")
	 * @GET
	 * @AccessLevel("mod")
	 * @ApiResult(code=200, class='ModuleSetting[]', desc='A list of all settings for this module')
	 */
	public function apiConfigSettingsGetEndpoint(Request $request, HttpProtocolWrapper $server, string $module): Response {
		$settings = $this->configController->getModuleSettings($module);
		$result = [];
		foreach ($settings as $setting) {
			$modSet = new ModuleSetting($setting->getData());
			if ($modSet->type === $modSet::TYPE_DISCORD_CHANNEL) {
				$modSet->options = $this->discordRelayController->getChannelOptionList();
			}
			$result[] = $modSet;
		}
		return new ApiResponse($result);
	}

	/**
	 * Get a list of available events for a module
	 * @Api("/module/%s/events")
	 * @GET
	 * @AccessLevel("mod")
	 * @ApiResult(code=200, class='ModuleEventConfig[]', desc='A list of all events and their status for this module')
	 */
	public function apiConfigEventsGetEndpoint(Request $request, HttpProtocolWrapper $server, string $module): Response {
		/** @var EventCfg[] */
		$events = $this->db->fetchAll(
			EventCfg::class,
			"SELECT * FROM `eventcfg_<myname>` ".
			"WHERE `type` != 'setup' AND `module` = ?",
			$module
		);
		$result = [];
		foreach ($events as $event) {
			$result []= new ModuleEventConfig($event);
		}
		return new ApiResponse($result);
	}

	/**
	 * Get a list of available commands for a module
	 * @Api("/module/%s/commands")
	 * @GET
	 * @AccessLevel("mod")
	 * @ApiResult(code=200, class='ModuleCommand[]', desc='A list of all command and possible subcommands this module provides')
	 */
	public function apiConfigCommandsGetEndpoint(Request $request, HttpProtocolWrapper $server, string $module): Response {
		$cmds = $this->configController->getAllRegisteredCommands($module);
		/** @var array<string,ModuleSubcommand> */
		$result = [];
		foreach ($cmds as $cmd) {
			if ($cmd->cmdevent === "cmd") {
				$result[$cmd->cmd] = new ModuleCommand($cmd);
			} else {
				$result[$cmd->dependson]->subcommands ??= [];
				$result[$cmd->dependson]->subcommands []= new ModuleSubcommand($cmd);
			}
		}
		return new ApiResponse(array_values($result));
	}

	/**
	 * Get a list of available events for a module
	 * @Api("/access_levels")
	 * @GET
	 * @AccessLevel("all")
	 * @ApiResult(code=200, class='ModuleAccessLevel[]', desc='A list of all access levels')
	 */
	public function apiConfigAccessLevelsGetEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse($this->configController->getValidAccessLevels());
	}
}
