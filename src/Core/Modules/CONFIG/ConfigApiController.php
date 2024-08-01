<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use function Safe\preg_match;

use Amp\Http\HttpStatus;
use Amp\Http\Server\{Request, Response};
use EventSauce\ObjectHydrator\{DefinitionProvider, KeyFormatterWithoutConversion, ObjectMapper, ObjectMapperUsingReflection};
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
	Exceptions\InsufficientAccessException,
	Exceptions\SQLException,
	HelpManager,
	ModuleInstance,
	Safe,
	SettingManager,
};
use Nadybot\Modules\WEBSERVER_MODULE\{WebserverController};
use Nadybot\Modules\{
	DISCORD_GATEWAY_MODULE\DiscordRelayController,
	WEBSERVER_MODULE\ApiResponse,
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

	public function __construct(
		private ObjectMapper $mapper=new ObjectMapperUsingReflection(
			new DefinitionProvider(
				keyFormatter: new KeyFormatterWithoutConversion(),
			),
		)
	) {
	}

	/** Get a list of available modules to configure */
	#[
		NCA\Api('/module'),
		NCA\GET,
		NCA\AccessLevel('mod'),
		NCA\ApiResult(code: 200, class: 'ConfigModule[]', desc: 'A list of modules to configure')
	]
	public function moduleGetEndpoint(Request $request): Response {
		return ApiResponse::create($this->configController->getModules());
	}

	/** Activate or deactivate an event */
	#[
		NCA\Api('/module/%s/events/%s/%s'),
		NCA\PATCH,
		NCA\PUT,
		NCA\AccessLevel('mod'),
		NCA\RequestBody(class: 'Operation', desc: 'Either "enable" or "disable"', required: true),
		NCA\ApiResult(code: 204, desc: 'operation applied successfully'),
		NCA\ApiResult(code: 402, desc: 'Wrong or no operation given'),
		NCA\ApiResult(code: 404, desc: 'Module or Event not found')
	]
	public function toggleEventStatusEndpoint(Request $request, string $module, string $event, string $handler): Response {
		$body = $request->getAttribute(WebserverController::BODY);
		if (!is_array($body) || !isset($body['op'])) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}
		$op = $body['op'];
		if (!in_array($op, ['enable', 'disable'], true)) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}

		try {
			if (!$this->configController->toggleEvent($event, $handler, $op === 'enable')) {
				return new Response(
					status: HttpStatus::NOT_FOUND,
					headers: ['Content-Type' => 'text/plain'],
					body: 'Event or handler not found',
				);
			}
		} catch (Exception $e) {
			return new Response(
				status: HttpStatus::UNPROCESSABLE_ENTITY,
				headers: ['Content-Type' => 'text/plain'],
				body: $e->getMessage()
			);
		}
		return new Response(status: HttpStatus::NO_CONTENT);
	}

	/** Change a setting's value */
	#[
		NCA\Api('/module/%s/settings/%s'),
		NCA\PATCH,
		NCA\PUT,
		NCA\AccessLevel('mod'),
		NCA\RequestBody(class: 'string|bool|int', desc: 'New value for the setting', required: true),
		NCA\ApiResult(code: 204, desc: 'operation applied successfully'),
		NCA\ApiResult(code: 404, desc: 'Wrong module or setting'),
		NCA\ApiResult(code: 422, desc: 'Invalid value given')
	]
	public function changeModuleSettingEndpoint(Request $request, string $module, string $setting): Response {
		/** @var Setting|null */
		$oldSetting = $this->db->table(Setting::getTable())
			->where('name', $setting)->where('module', $module)
			->asObj(Setting::class)->first();
		if ($oldSetting === null) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		$settingHandler = $this->settingManager->getSettingHandler($oldSetting);
		if (!isset($settingHandler)) {
			return new Response(status: HttpStatus::INTERNAL_SERVER_ERROR);
		}
		$modSet = ModuleSetting::fromSetting($oldSetting);
		$value = $request->getAttribute(WebserverController::BODY);
		if (!is_string($value) && !is_int($value) && !is_bool($value)) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}
		if ($modSet->type === $modSet::TYPE_BOOL) {
			if (!is_bool($value)) {
				return new Response(
					status: HttpStatus::UNPROCESSABLE_ENTITY,
					headers: ['Content-Type' => 'text/plain'],
					body: 'Bool value required',
				);
			}
			$value = $value ? '1' : '0';
		} elseif (
			in_array(
				$modSet->type,
				[
					$modSet::TYPE_INT_OPTIONS,
					$modSet::TYPE_NUMBER,
					$modSet::TYPE_TIME,
				],
				true
			)
		) {
			if (!is_int($value)) {
				return new Response(
					status: HttpStatus::UNPROCESSABLE_ENTITY,
					headers: ['Content-Type' => 'text/plain'],
					body: 'Integer value required',
				);
			}
		} elseif (!is_string($value)) {
			return new Response(
				status: HttpStatus::UNPROCESSABLE_ENTITY,
				headers: ['Content-Type' => 'text/plain'],
				body: 'String value required'
			);
		}
		if ($modSet->type === $modSet::TYPE_COLOR) {
			if (is_string($value) && count($matches = Safe::pregMatch('/(#[0-9a-fA-F]{6})/', $value))) {
				$value = "<font color='{$matches[1]}'>";
			}
		}
		try {
			$newValueToSave = $settingHandler->save((string)$value);
			if (!$this->settingManager->save($setting, $newValueToSave)) {
				return new Response(status: HttpStatus::NOT_FOUND);
			}
		} catch (Exception $e) {
			return new Response(
				status: HttpStatus::UNPROCESSABLE_ENTITY,
				headers: ['Content-Type' => 'text/plain'],
				body: 'Invalid value: ' . $e->getMessage()
			);
		}
		return new Response(status: HttpStatus::NO_CONTENT);
	}

	/** Activate or deactivate a Command */
	#[
		NCA\Api('/module/%s/commands/%s/%s'),
		NCA\PATCH,
		NCA\PUT,
		NCA\AccessLevel('mod'),
		NCA\RequestBody(class: 'ModuleSubcommandChannel', desc: 'Parameters to change', required: true),
		NCA\ApiResult(code: 200, class: 'ModuleCommand', desc: 'operation applied successfully'),
		NCA\ApiResult(code: 422, desc: 'Wrong or no operation given')
	]
	public function toggleCommandChannelSettingsEndpoint(Request $request, string $module, string $command, string $channel): Response {
		$user = $request->getAttribute(WebserverController::USER);
		$body = $request->getAttribute(WebserverController::BODY);
		$subCmd = (bool)preg_match("/\s/", $command);
		$result = 0;
		$parsed = 0;
		$exception = null;
		if (isset($body->access_level) && is_string($body->access_level)) {
			$parsed++;
			try {
				if ($subCmd) {
					$result += (int)($this->configController->changeSubcommandAL($user??'_', $command, $channel, $body->access_level) === 1);
				} else {
					$result += (int)($this->configController->changeCommandAL($user??'_', $command, $channel, $body->access_level) === 1);
				}
			} catch (Exception $e) {
				$exception = $e;
			}
		}
		if (isset($body->enabled) && is_bool($body->enabled)) {
			$parsed++;
			$result += (int)$this->configController->toggleCmd($user??'_', $subCmd, $command, $channel, $body->enabled);
		}
		if ($parsed === 0) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}
		if ($parsed === 1) {
			if (isset($exception) && $exception instanceof InsufficientAccessException) {
				return new Response(status: HttpStatus::FORBIDDEN);
			}
			if (isset($exception)) {
				return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
			}
		}
		if ($result === 0 && !isset($exception)) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		$cmd = $this->commandManager->get($command);
		if (!isset($cmd) || $cmd->module !== $module) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		$moduleCommand = ModuleCommand::fromCmdCfg($cmd);
		return ApiResponse::create($moduleCommand);
	}

	/** Activate or deactivate a command */
	#[
		NCA\Api('/module/%s/commands/%s'),
		NCA\PATCH,
		NCA\PUT,
		NCA\AccessLevel('mod'),
		NCA\RequestBody(class: 'Operation', desc: 'Either "enable" or "disable"', required: true),
		NCA\ApiResult(code: 200, desc: 'operation applied successfully'),
		NCA\ApiResult(code: 402, desc: 'Wrong or no operation given')
	]
	public function toggleCommandStatusEndpoint(Request $request, string $module, string $command): Response {
		$user = $request->getAttribute(WebserverController::USER) ?? '_';
		$body = $request->getAttribute(WebserverController::BODY);
		if (!is_array($body) || !isset($body['op'])) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}

		$op = $body['op'];
		if (!in_array($op, ['enable', 'disable'], true)) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}
		$subCmd = (bool)preg_match("/\s/", $command);
		try {
			if ($this->configController->toggleCmd($user, $subCmd, $command, 'all', $op === 'enable') === true) {
				$cmd = $this->commandManager->get($command);
				if (!isset($cmd) || $cmd->module !== $module) {
					return new Response(status: HttpStatus::NOT_FOUND);
				}
				return ApiResponse::create(ModuleSubcommand::fromCmdCfg($cmd));
			}
		} catch (InsufficientAccessException) {
			return new Response(status: HttpStatus::FORBIDDEN);
		} catch (Exception) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}
		return new Response(status: HttpStatus::NOT_FOUND);
	}

	/** Activate or deactivate a module */
	#[
		NCA\Api('/module/%s'),
		NCA\PATCH,
		NCA\PUT,
		NCA\AccessLevel('mod'),
		NCA\RequestBody(class: 'Operation', desc: 'Either "enable" or "disable"', required: true),
		NCA\QueryParam(name: 'channel', desc: 'Either "msg", "priv", "guild" or "all"'),
		NCA\ApiResult(code: 204, desc: 'operation applied successfully'),
		NCA\ApiResult(code: 402, desc: 'Wrong or no operation given')
	]
	public function toggleModuleStatusEndpoint(Request $request, string $module): Response {
		$body = $request->getAttribute(WebserverController::BODY);
		if (!is_array($body) || !isset($body['op'])) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}

		$op = $body['op'];
		if (!in_array($op, ['enable', 'disable'], true)) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}
		$channel = $request->getQueryParameter('channel') ?? 'all';
		$channels = $this->commandManager->getPermissionSets()->pluck('name');
		if ($channel !== 'all' && !$channels->containsStrict($channel)) {
			return new Response(HttpStatus::UNPROCESSABLE_ENTITY);
		}
		if ($this->configController->toggleModule($module, $channel, $op === 'enable')) {
			return new Response(status: HttpStatus::NO_CONTENT);
		}
		return new Response(status: HttpStatus::NOT_FOUND);
	}

	/** Get the description of a module */
	#[
		NCA\Api('/module/%s/description'),
		NCA\GET,
		NCA\AccessLevel('all'),
		NCA\ApiResult(code: 200, class: 'string', desc: 'A description of the module'),
		NCA\ApiResult(code: 204, desc: 'No description set')
	]
	public function apiModuleDescriptionGetEndpoint(Request $request, string $module): Response {
		$description = $this->configController->getModuleDescription($module);
		if (!isset($description)) {
			return new Response(status: HttpStatus::NO_CONTENT);
		}
		return ApiResponse::create($description);
	}

	/** Get a list of available settings for a module */
	#[
		NCA\Api('/module/%s/settings'),
		NCA\GET,
		NCA\AccessLevel('mod'),
		NCA\ApiResult(code: 200, class: 'ModuleSetting[]', desc: 'A list of all settings for this module')
	]
	public function apiConfigSettingsGetEndpoint(Request $request, string $module): Response {
		$user = $request->getAttribute(WebserverController::USER) ?? '_';
		$settings = $this->configController->getModuleSettings($module);
		$result = [];
		foreach ($settings as $setting) {
			$modSet = ModuleSetting::fromSetting($setting->getData());
			if (strlen($modSet->description??'') > 0) {
				$modSet->description = $this->webChatConverter->parseAOFormat(trim($modSet->description))->message;
				$modSet->description = str_replace('<br />', ' ', $modSet->description);
				$modSet->description = htmlspecialchars_decode($modSet->description);
			}
			if (strlen($setting->getData()->help??'') > 0) {
				$help = $this->helpManager->find($modSet->name, $user);
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
		return ApiResponse::create($result);
	}

	/** Get a list of available events for a module */
	#[
		NCA\Api('/module/%s/events'),
		NCA\GET,
		NCA\AccessLevel('mod'),
		NCA\ApiResult(code: 200, class: 'ModuleEventConfig[]', desc: 'A list of all events and their status for this module')
	]
	public function apiConfigEventsGetEndpoint(Request $request, string $module): Response {
		$events = $this->db->table(EventCfg::getTable())
			->where('type', '!=', 'setup')
			->where('module', $module)
			->asObj(EventCfg::class)
			->map(static function (EventCfg $event): ModuleEventConfig {
				return ModuleEventConfig::fromEventCfg($event);
			});
		return ApiResponse::create($events->toArray());
	}

	/** Get a list of available commands for a module */
	#[
		NCA\Api('/module/%s/commands'),
		NCA\GET,
		NCA\AccessLevel('mod'),
		NCA\ApiResult(code: 200, class: 'ModuleCommand[]', desc: 'A list of all command and possible subcommands this module provides')
	]
	public function apiConfigCommandsGetEndpoint(Request $request, string $module): Response {
		$cmds = $this->commandManager->getAllForModule($module, true)->sortBy('cmdevent');

		/** @var array<string,ModuleCommand> */
		$result = [];
		foreach ($cmds as $cmd) {
			if ($cmd->cmdevent === 'cmd') {
				$result[$cmd->cmd] = ModuleCommand::fromCmdCfg($cmd);
			} else {
				$result[$cmd->dependson]->subcommands []= ModuleSubcommand::fromCmdCfg($cmd);
			}
		}
		return ApiResponse::create(array_values($result));
	}

	/** Get a list of configured access levels */
	#[
		NCA\Api('/access_levels'),
		NCA\GET,
		NCA\AccessLevel('all'),
		NCA\ApiResult(code: 200, class: 'ModuleAccessLevel[]', desc: 'A list of all access levels')
	]
	public function apiConfigAccessLevelsGetEndpoint(Request $request): Response {
		return ApiResponse::create($this->configController->getValidAccessLevels());
	}

	/** Get a list of permission sets */
	#[
		NCA\Api('/permission_set'),
		NCA\GET,
		NCA\AccessLevel('all'),
		NCA\ApiResult(code: 200, class: 'ExtCmdPermissionSet[]', desc: 'A list of permission sets')
	]
	public function apiConfigPermissionSetGetEndpoint(Request $request): Response {
		return ApiResponse::create($this->commandManager->getExtPermissionSets()->toArray());
	}

	/** Get a permission set by its name */
	#[
		NCA\Api('/permission_set/%s'),
		NCA\GET,
		NCA\AccessLevel('all'),
		NCA\ApiResult(code: 200, class: 'ExtCmdPermissionSet', desc: 'A permission set')
	]
	public function apiConfigPermissionSetGetByNameEndpoint(Request $request, string $name): Response {
		$set = $this->commandManager->getExtPermissionSet($name);
		if (!isset($set)) {
			return new Response(HttpStatus::NOT_FOUND);
		}
		return ApiResponse::create($set);
	}

	/** Create a new permission set */
	#[
		NCA\Api('/permission_set'),
		NCA\POST,
		NCA\RequestBody(class: 'CmdPermissionSet', desc: 'The new permission set', required: true),
		NCA\AccessLevel('superadmin'),
		NCA\ApiResult(code: 204, desc: 'Permission Set created successfully')
	]
	public function apiConfigPermissionSetCreateEndpoint(Request $request): Response {
		$set = $request->getAttribute(WebserverController::BODY);
		try {
			if (!is_array($set)) {
				throw new Exception('Wrong content body');
			}

			$permSet = $this->mapper->hydrateObject(CmdPermissionSet::class, $set);
		} catch (Throwable) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}

		try {
			$this->commandManager->createPermissionSet($permSet->name, $permSet->letter);
		} catch (Exception $e) {
			return new Response(
				status: HttpStatus::UNPROCESSABLE_ENTITY,
				headers: [],
				body: $e->getMessage()
			);
		}
		return new Response(HttpStatus::NO_CONTENT);
	}

	/** Change a permission set */
	#[
		NCA\Api('/permission_set/%s'),
		NCA\PATCH,
		NCA\RequestBody(class: 'CmdPermissionSet', desc: 'The new permission set data', required: true),
		NCA\AccessLevel('superadmin'),
		NCA\ApiResult(code: 204, class: 'ExtCmdPermissionSet', desc: 'Permission Set changed successfully')
	]
	public function apiConfigPermissionSetPatchEndpoint(Request $request, string $name): Response {
		$set = $request->getAttribute(WebserverController::BODY);
		try {
			if (!is_array($set)) {
				throw new Exception('Wrong content body');
			}

			$permSet = $this->mapper->hydrateObject(CmdPermissionSet::class, $set);
		} catch (Throwable) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}
		$old = $this->commandManager->getPermissionSet($name);
		if (!isset($old)) {
			return new Response(HttpStatus::NOT_FOUND);
		}
		foreach (get_object_vars($permSet) as $key => $value) {
			$old->{$key} = $value;
		}
		try {
			$this->commandManager->changePermissionSet($name, $old);
		} catch (Exception $e) {
			return new Response(
				status: HttpStatus::UNPROCESSABLE_ENTITY,
				headers: [],
				body: $e->getMessage()
			);
		}
		return ApiResponse::create($this->commandManager->getExtPermissionSet($old->name));
	}

	/** Get a list of command sources */
	#[
		NCA\Api('/cmd_source'),
		NCA\GET,
		NCA\AccessLevel('all'),
		NCA\ApiResult(code: 200, class: 'CmdSource[]', desc: 'A list of command sources and their mappings')
	]
	public function apiConfigCmdSrcGetEndpoint(Request $request): Response {
		$sources = $this->commandManager->getSources();
		$maps = $this->getCmdSourceMappings()->groupBy('source');
		$result = [];
		foreach ($sources as $source) {
			$cmdSrc = CmdSource::fromMask($source);
			$cmdSrc->mappings = $maps->get($cmdSrc->source, new Collection())->toList();
			$result []= $cmdSrc;
		}
		return ApiResponse::create($result);
	}

	/** Get details for a specific command source */
	#[
		NCA\Api('/cmd_source/%s'),
		NCA\GET,
		NCA\AccessLevel('all'),
		NCA\ApiResult(code: 200, class: 'CmdSource', desc: 'The command source and its mappings')
	]
	public function apiConfigCmdSrcDetailGetEndpoint(Request $request, string $source): Response {
		$cmdSrc = $this->getCmdSource(strtolower($source));
		if (!isset($cmdSrc)) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		$cmdSrc->mappings = $this->getCmdSourceMappings($source)->toList();
		return ApiResponse::create($cmdSrc);
	}

	/** Get mappings for a specific command source */
	#[
		NCA\Api('/cmd_source/%s/mappings'),
		NCA\GET,
		NCA\AccessLevel('all'),
		NCA\ApiResult(code: 200, class: 'CmdSourceMapping[]', desc: "The command source's mappings")
	]
	public function apiConfigCmdSrcMappingsGetEndpoint(Request $request, string $source): Response {
		$cmdSrc = $this->getCmdSource(strtolower($source));
		if (!isset($cmdSrc)) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		return ApiResponse::create($this->getCmdSourceMappings($source)->toArray());
	}

	/** Get mappings for a specific command source */
	#[
		NCA\Api('/cmd_source/%s/mappings/%s'),
		NCA\GET,
		NCA\AccessLevel('all'),
		NCA\ApiResult(code: 200, class: 'CmdSourceMapping', desc: "The command's sub-source mapping")
	]
	public function apiConfigCmdSrcSubMappingGetEndpoint(Request $request, string $source, string $subSource): Response {
		$cmdSrc = $this->getCmdSource(strtolower($source));
		if (!isset($cmdSrc)) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		$mappings = $this->getCmdSourceMappings($source);
		$mapping = $mappings->where('sub_source', strtolower($subSource))->first();
		if (!isset($mapping)) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		return ApiResponse::create($mapping);
	}

	/** Delete mapping for a specific command sub-source */
	#[
		NCA\Api('/cmd_source/%s/mappings/%s'),
		NCA\DELETE,
		NCA\AccessLevel('superadmin'),
		NCA\ApiResult(code: 204, desc: 'The sub-source mapping was deleted successfully')
	]
	public function apiConfigCmdSrcSubMappingDeleteEndpoint(Request $request, string $source, string $subSource): Response {
		$cmdSrc = $this->getCmdSource(strtolower($source));
		if (!isset($cmdSrc)) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		$fullSource = strtolower("{$source}({$subSource})");
		try {
			if (!$this->commandManager->deletePermissionSetMapping($fullSource)) {
				return new Response(status: HttpStatus::NOT_FOUND);
			}
		} catch (Exception $e) {
			return new Response(status: HttpStatus::FORBIDDEN);
		}
		return new Response(status: HttpStatus::NO_CONTENT);
	}

	/** Delete mapping for a specific command source */
	#[
		NCA\Api('/cmd_source/%s/mappings'),
		NCA\DELETE,
		NCA\AccessLevel('superadmin'),
		NCA\ApiResult(code: 204, desc: 'The source mapping was deleted successfully')
	]
	public function apiConfigCmdSrcMappingDeleteEndpoint(Request $request, string $source): Response {
		$cmdSrc = $this->getCmdSource(strtolower($source));
		if (!isset($cmdSrc) || $cmdSrc->has_sub_sources) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		try {
			if (!$this->commandManager->deletePermissionSetMapping(strtolower($source))) {
				return new Response(status: HttpStatus::NOT_FOUND);
			}
		} catch (Exception) {
			return new Response(status: HttpStatus::FORBIDDEN);
		}
		return new Response(status: HttpStatus::NO_CONTENT);
	}

	/** Create a new mapping */
	#[
		NCA\Api('/cmd_source/%s/mappings'),
		NCA\POST,
		NCA\AccessLevel('superadmin'),
		NCA\RequestBody(class: 'CmdSourceMapping', desc: 'The new mapping', required: true),
		NCA\ApiResult(code: 204, desc: 'A new command mapping was created')
	]
	public function apiConfigCmdSrcNewMappingEndpoint(Request $request, string $source): Response {
		$cmdSrc = $this->getCmdSource(strtolower($source));
		if (!isset($cmdSrc)) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		$body = $request->getAttribute(WebserverController::BODY);
		try {
			if (!is_array($body)) {
				throw new Exception('Wrong content body');
			}
			$body['source'] = $source;

			$mapping = $this->mapper->hydrateObject(CmdSourceMapping::class, $body);
		} catch (Throwable $e) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}

		return $this->createCmdSourceMapping($mapping);
	}

	/** Modify mapping for a specific command source */
	#[
		NCA\Api('/cmd_source/%s/mappings'),
		NCA\PUT,
		NCA\AccessLevel('superadmin'),
		NCA\ApiResult(code: 200, class: 'CmdSourceMapping', desc: 'The new, modified source mapping')
	]
	public function apiConfigCmdSrcMappingPutEndpoint(Request $request, string $source): Response {
		$cmdSrc = $this->getCmdSource(strtolower($source));
		if (!isset($cmdSrc) || $cmdSrc->has_sub_sources) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		$body = $request->getAttribute(WebserverController::BODY);
		try {
			if (!is_array($body)) {
				throw new Exception('Wrong content body');
			}

			$body['source'] = strtolower($source);
			$body['sub_source'] = null;
			$mapping = $this->mapper->hydrateObject(CmdSourceMapping::class, $body);
		} catch (Throwable) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}

		return $this->modifyCmdSourceMapping($mapping);
	}

	/** Modify mapping for a specific command source */
	#[
		NCA\Api('/cmd_source/%s/mappings/%s'),
		NCA\PUT,
		NCA\AccessLevel('superadmin'),
		NCA\ApiResult(code: 200, class: 'CmdSourceMapping', desc: 'The new, modified source mapping')
	]
	public function apiConfigCmdSubSrcMappingPutEndpoint(Request $request, string $source, string $subSource): Response {
		$cmdSrc = $this->getCmdSource(strtolower($source));
		if (!isset($cmdSrc) || !$cmdSrc->has_sub_sources) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		$body = $request->getAttribute(WebserverController::BODY);
		try {
			if (!is_array($body)) {
				throw new Exception('Wrong content body');
			}

			$body['source'] = strtolower($source);
			$body['sub_source'] = strtolower($subSource);
			$mapping = $this->mapper->hydrateObject(CmdSourceMapping::class, $body);
		} catch (Throwable) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}

		return $this->modifyCmdSourceMapping($mapping);
	}

	/** @return Collection<int,CmdSourceMapping> */
	protected function getCmdSourceMappings(?string $source=null): Collection {
		$maps = $this->commandManager->getPermSetMappings()
			->map(CmdSourceMapping::fromPermSetMapping(...));
		if (isset($source)) {
			return $maps->where('source', $source)->values();
		}
		return $maps;
	}

	protected function getCmdSource(string $sourceName): ?CmdSource {
		$sourceName = strtolower($sourceName);
		$sources = collect($this->commandManager->getSources());
		$source = $sources->first(static function (string $source) use ($sourceName): bool {
			return Safe::pregReplace("/\(.+$/", '', $source) === $sourceName;
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
					$source .= '(*)';
				} else {
					$source .= "({$decoded->sub_source})";
				}
			}
			$source = strtolower($source);
			if ($this->commandManager->getPermSetMappings()->where('source', $source)->isNotEmpty()) {
				return new Response(HttpStatus::CONFLICT);
			}
			$decoded->permission_set = strtolower($decoded->permission_set);
			if (!$this->commandManager->hasPermissionSet($decoded->permission_set)) {
				return new Response(
					HttpStatus::UNPROCESSABLE_ENTITY,
					['Content-type' => 'text/plain'],
					"There is no permission set {$decoded->permission_set}."
				);
			}
			$map = $decoded->toPermSetMapping();
			try {
				$this->db->insert($map);
			} catch (SQLException $e) {
				return new Response(
					HttpStatus::INTERNAL_SERVER_ERROR,
					['Content-type' => 'text/plain'],
					$e->getMessage()
				);
			}
			$this->commandManager->loadPermsetMappings();
		} catch (Exception $e) {
			return new Response(
				HttpStatus::UNPROCESSABLE_ENTITY,
				['Content-type' => 'text/plain'],
				$e->getMessage()
			);
		}
		return new Response(HttpStatus::NO_CONTENT);
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
					return new Response(HttpStatus::NOT_FOUND);
				}
				$source .= "({$decoded->sub_source})";
			}
			$source = strtolower($source);

			/** @var ?CmdPermSetMapping */
			$old = $this->commandManager->getPermSetMappings()->where('source', $source)->first();
			if (!isset($old)) {
				return new Response(status: HttpStatus::NOT_FOUND);
			}
			$decoded->permission_set = strtolower($decoded->permission_set);
			if (!$this->commandManager->hasPermissionSet($decoded->permission_set)) {
				return new Response(
					status: HttpStatus::UNPROCESSABLE_ENTITY,
					headers: ['Content-type' => 'text/plain'],
					body: "There is no permission set {$decoded->permission_set}."
				);
			}
			$map = $decoded->toPermSetMapping();
			$map->id = $old->id;
			try {
				$this->db->update($map);
			} catch (SQLException $e) {
				return new Response(
					HttpStatus::INTERNAL_SERVER_ERROR,
					['Content-type' => 'text/plain'],
					$e->getMessage()
				);
			}
			$this->commandManager->loadPermsetMappings();
		} catch (Exception $e) {
			return new Response(
				HttpStatus::UNPROCESSABLE_ENTITY,
				['Content-type' => 'text/plain'],
				$e->getMessage()
			);
		}
		return ApiResponse::create(CmdSourceMapping::fromPermSetMapping($map));
	}
}
