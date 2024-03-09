<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\DBSchema\Setting;

#[
	NCA\Instance,
	NCA\ProvidesEvent("setting(*)")
]
class SettingManager {
	public const DB_TABLE = "settings_<myname>";

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** @var array<string,SettingValue> */
	public array $settings = [];

	public static bool $isInitialized = false;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private Util $util;

	#[NCA\Inject]
	private HelpManager $helpManager;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private BotConfig $config;

	/** @var array<string,ChangeListener[]> */
	private array $changeListeners = [];

	/** @var array<string,string> */
	private array $settingHandlers = [];

	/** Return the hardcoded value for a setting or a given default */
	public function getHardcoded(string $setting, bool|int|string|null $default=null): ?string {
		$value = $this->config->settings[$setting]??$default;
		if (is_bool($value)) {
			return $value ? "1" : "0";
		} elseif (is_int($value)) {
			return (string)$value;
		} elseif (is_string($value)) {
			return $value;
		}
		return null;
	}

	/**
	 * Register a setting for a module
	 *
	 * @param string                       $module      The module name
	 * @param string                       $name        The name of the setting
	 * @param string                       $description A description for the setting (will appear in the config)
	 * @param string                       $mode        'edit' or 'noedit'
	 * @param string                       $type        'color', 'number', 'text', 'options', or 'time'
	 * @param array<string|int,int|string> $options     An optional list of values that the setting can be, semi-colon delimited.
	 *                                                  Alternatively, use an associative array [label => value], where label is optional.
	 * @param string                       $accessLevel The permission level needed to change this setting (default: mod) (optional)
	 * @param ?string                      $help        A help file for this setting; if blank, will use a help topic with the same name as this setting if it exists (optional)
	 *
	 * @throws SQLException if the setting causes SQL errors (text too long, etc.)
	 */
	public function add(
		string $module,
		string $name,
		string $description,
		string $mode,
		int|float|string|bool $value,
		string $type,
		array $options=[],
		string $accessLevel='mod',
		?string $help=null,
	): void {
		$value = $this->getHardcoded($name) ?? $value;
		$name = strtolower($name);
		$type = strtolower($type);

		if ($accessLevel === '') {
			$accessLevel = 'mod';
		}
		$accessLevel = $this->accessManager->getAccessLevel($accessLevel);

		if (!isset($this->settingHandlers[$type])) {
			$this->logger->error(
				"Error in registering Setting {module}:{name}. ".
				"Invalid type '{type}'. Allowed are: {allowed_types}.",
				[
					"allowed_types" => join(", ", array_keys($this->settingHandlers)),
					"type" => $type,
					"module" => $module,
					"name" => $name,
				]
			);
		}

		if ($type === 'time') {
			$oldvalue = $value;
			$value = $this->util->parseTime((string)$value);
			if ($value < 1) {
				$this->logger->error("Error in registering Setting {module}:setting({setting}). Invalid time: '{time}'.", [
					"module" => $module,
					"setting" => $name,
					"time" => $oldvalue,
				]);
				return;
			}
		}

		$kv = [];
		$needIntOptions = array_keys($options) !== range(0, count($options) - 1);
		foreach ($options as $key => $optVal) {
			if (!$needIntOptions) {
				$key = (string)$optVal;
			} elseif (is_int($key)) {
				$key = (string)$optVal;
			}
			$kv[$key] = (string)$optVal;
		}
		$options = join(";", array_keys($kv));
		$intoptions = null;
		if ($needIntOptions) {
			$intoptions = join(";", array_values($kv));
		}

		if (isset($help) && $help !== "") {
			$help = $this->helpManager->checkForHelpFile($module, $help);
		}

		try {
			$setting = new Setting();
			$setting->admin       = $accessLevel;
			$setting->description = $description;
			$setting->help        = $help;
			$setting->intoptions  = $intoptions;
			$setting->mode        = $mode;
			$setting->module      = $module;
			$setting->name        = $name;
			$setting->options     = $options;
			$setting->source      = "db";
			$setting->type        = $type;
			$setting->verify      = 1;
			$setting->value       = (string)$value;
			if (array_key_exists($name, $this->chatBot->existing_settings) || $this->exists($name)) {
				$this->db->table(self::DB_TABLE)
					->where("name", $name)
					->update([
						"module" => $module,
						"type" => $type,
						"mode" => $mode,
						"options" => $options,
						"intoptions" => $intoptions,
						"description" => $description,
						"verify" => 1,
						"help" => $help,
					]);
				if (array_key_exists($name, $this->settings)) {
					$setting->value = $this->settings[$name]->value;
				} else {
					$this->settings[$name] = new SettingValue($setting);
				}
			} else {
				$this->db->table(self::DB_TABLE)
					->insert([
						"name" => $name,
						"module" => $module,
						"type" => $type,
						"mode" => $mode,
						"value" => $value,
						"options" => $options,
						"intoptions" => $intoptions,
						"description" => $description,
						"source" => "db",
						"admin" => $accessLevel,
						"verify" => 1,
						"help" => $help,
					]);
			}
			$this->settings[$name] = new SettingValue($setting);
		} catch (SQLException $e) {
			$this->logger->error("Error in registering Setting {module}:setting({setting}): {error}", [
				"module" => $module,
				"setting" => $name,
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
		}
	}

	/**
	 * Determine if a setting with a given name exists
	 *
	 * @param string $name Setting to check
	 *
	 * @return bool true if the setting exists, false otherwise
	 */
	public function exists(string $name): bool {
		return array_key_exists($name, $this->settings);
	}

	/**
	 * Gets the value of a setting
	 *
	 * @param string $name name of the setting to read
	 *
	 * @return null|string|int|false the value of the setting, or false if a setting with that name does not exist
	 */
	public function get(string $name): null|string|int|false {
		$name = strtolower($name);
		if ($this->exists($name)) {
			return $this->settings[$name]->value;
		} elseif (!static::$isInitialized) {
			/** @var ?Setting */
			$value = $this->db->table(self::DB_TABLE)
				->where("name", $name)
				->asObj(Setting::class)
				->first();
			if (isset($value)) {
				return (new SettingValue($value))->value;
			}
		}
		$this->logger->error("Could not retrieve value for setting '{setting}' because setting does not exist", [
			"setting" => $name,
		]);
		return false;
	}

	/** @return int|bool|string|mixed[]|null */
	public function getTyped(string $name): int|bool|string|array|null {
		$name = strtolower($name);
		if ($this->exists($name)) {
			return $this->settings[$name]->typed();
		} elseif (!static::$isInitialized) {
			/** @var ?Setting */
			$value = $this->db->table(self::DB_TABLE)
				->where("name", $name)
				->asObj(Setting::class)
				->first();
			if (isset($value)) {
				return (new SettingValue($value))->typed();
			}
			return null;
		}

		$this->logger->error("Could not retrieve value for setting '{setting}' because setting does not exist", [
			"setting" => $name,
		]);
		return null;
	}

	public function getInt(string $name): ?int {
		$value = $this->getTyped($name);
		if (is_int($value) || is_bool($value)) {
			return (int)$value;
		}
		$this->logger->error("Wrong type for setting '{name}' requested. Expected 'int', got '{type}' ({value})", [
			"name" => $name,
			"type" => gettype($value),
			"value" => $value,
		]);
		return null;
	}

	public function getBool(string $name): ?bool {
		$value = $this->getTyped($name);
		if (is_bool($value)) {
			return $value;
		}
		$this->logger->error("Wrong type for setting '{name}' requested. Expected 'bool', got '{type}' ({value})", [
			"name" => $name,
			"type" => gettype($value),
			"value" => $value,
		]);
		return null;
	}

	public function getString(string $name): ?string {
		$value = $this->getTyped($name);
		if (is_string($value)) {
			return $value;
		}
		$this->logger->error("Wrong type for setting '{setting}' requested. Expected 'string', got '{type}'", [
			"setting" => $name,
			"type" => gettype($value),
		]);
		return null;
	}

	/**
	 * Saves a new value for a setting
	 *
	 * @param string     $name  The name of the setting
	 * @param string|int $value The new value to set the setting to
	 *
	 * @return bool false if the setting with that name does not exist, true otherwise
	 */
	public function save(string $name, string|int $value): bool {
		$name = strtolower($name);

		if (!$this->exists($name)) {
			$this->logger->error("Could not save value '{value}' for setting '{setting}' because setting does not exist", [
				"value" => $value,
				"setting" => $name,
			]);
			return false;
		}
		if ($this->getHardcoded($name, null) !== null) {
			throw new Exception("<highlight>{$name}<end> is immutable.");
		}
		if ($this->settings[$name]->value === $value) {
			return true;
		}
		// notify any listeners
		if (isset($this->changeListeners[$name])) {
			foreach ($this->changeListeners[$name] as $listener) {
				call_user_func($listener->callback, $name, $this->settings[$name]->value, $value, $listener->data);
			}
		}
		$event = new SettingEvent();
		$event->setting = $name;
		$event->type = "setting({$name})";
		$event->oldValue = $this->settings[$name];
		$event->newValue = clone $event->oldValue;
		$event->newValue->value = (string)$value;
		$this->eventManager->fireEvent($event);

		$this->settings[$name]->value = (string)$value;
		$this->db->table(self::DB_TABLE)
			->where("name", $name)
			->update([
				"verify" => 1,
				"value" => $value,
			]);
		return true;
	}

	/** Load settings from the database */
	public function upload(): void {
		$this->settings = [];

		// Upload Settings from the db that are set by modules
		/** @var Setting[] $data */
		$data = $this->db->table(self::DB_TABLE)->asObj(Setting::class)->toArray();
		foreach ($data as $row) {
			$row->value = $this->getHardcoded($row->name, $row->value);
			$this->settings[$row->name] = new SettingValue($row);
		}
	}

	/**
	 * Adds listener callback which will be called if given $settingName changes.
	 *
	 * The callback has following signature:
	 * <code>function callback($value, $data)</code>
	 * $value: new value of the setting
	 * $data:  optional data variable given on register
	 *
	 * Example usage:
	 * <code>
	 *	registerChangeListener("some_setting_name", function($settingName, $oldValue, $newValue, $data) {
	 *		// ...
	 *	} );
	 * </code>
	 *
	 * @param string   $settingName changed setting's name
	 * @param callable $callback    the callback function to call
	 * @param mixed    $data        any data which will be passed to to the callback (optional)
	 */
	public function registerChangeListener(string $settingName, callable $callback, mixed $data=null): void {
		if (!is_callable($callback)) {
			$this->logger->error('Given callback is not valid.');
			return;
		}
		$settingName = strtolower($settingName);

		$listener = new ChangeListener();
		$listener->callback = $callback;
		$listener->data = $data;
		if (!array_key_exists($settingName, $this->changeListeners)) {
			$this->changeListeners[$settingName] = [];
		}
		$this->changeListeners[$settingName] []= $listener;
	}

	/** Registers a new setting type $name that's implemented by $class */
	public function registerSettingHandler(string $name, string $class): void {
		$this->settingHandlers[$name] = $class;
	}

	/** Get the handler for a setting */
	public function getSettingHandler(Setting $row): ?SettingHandler {
		$handler = $this->settingHandlers[$row->type] ?? null;
		if (!isset($handler)) {
			$this->logger->error("Could not find setting handler for setting type '{type}'", [
				"type" => $row->type,
			]);
			return null;
		}
		$handlerObj = new $handler($row);
		if (!is_subclass_of($handlerObj, SettingHandler::class)) {
			throw new Exception("Invalid SettingHandler {$handler}.");
		}
		Registry::injectDependencies($handlerObj);
		return $handlerObj;
	}
}
