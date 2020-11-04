<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\{
	EventCfg,
};
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\DiscordRelayController;

use Nadybot\Modules\WEBSERVER_MODULE\ApiResponse;
use Nadybot\Modules\WEBSERVER_MODULE\HttpProtocolWrapper;
use Nadybot\Modules\WEBSERVER_MODULE\Request;
use Nadybot\Modules\WEBSERVER_MODULE\Response;

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
	 * Activate or deactivate a Command
	 * @Api("/module/%s/commands/%s")
	 * @PATCH
	 * @AccessLevel("mod")
	 * @RequestBody(class='ModuleCommand', desc='One of the parameters to change', required=true)
	 * @ApiResult(code=200, desc='operation applied successfully')
	 * @ApiResult(code=402, desc='Wrong or no operation given')
	 */
	public function toggleCommandStatusEndpoint(Request $request, HttpProtocolWrapper $server, string $module, string $command): Response {
		$op = $request->decodedBody->op ?? null;
		if (!in_array($op, ["enable", "disable"], true)) {
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
	 * @ApiResult(code=200, desc='operation applied successfully')
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
