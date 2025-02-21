<?php declare(strict_types=1);

namespace Nadybot\Core;

use Closure;
use Exception;
use Nadybot\Core\Types\AccessLevel;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DBSchema\Setting,
	Events\SettingEvent,
	Exceptions\SQLException,
	SettingHandlers\SettingHandler,
	Types\SettingMode,
};
use Psr\Log\LoggerInterface;

#[NCA\Instance]
class SettingManager {
	public static bool $isInitialized = false;

	/** @var array<string,SettingValue> */
	private array $settings = [];

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private HelpManager $helpManager;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private BotConfig $config;

	/** @var array<string,list<ChangeListener>> */
	private array $changeListeners = [];

	/** @var array<string,string> */
	private array $settingHandlers = [];

	/** @var array<string,bool> */
	private array $configuredSettings = [];

	public function init(): void {
		$this->db->table(Setting::getTable())
			->update(['verify' => 0]);
		$this->db->table(Setting::getTable())
			->asObj(Setting::class)
			->each(function (Setting $row): void {
				$this->configuredSettings[$row->name] = true;
			});
	}

	/** Return the hard-coded value for a setting or a given default */
	public function getHardcoded(string $setting, null|bool|int|string $default=null): ?string {
		$value = $this->config->settings[$setting]??$default;
		if (is_bool($value)) {
			return $value ? '1' : '0';
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
	 * @param SettingMode                  $mode        'edit' or 'noedit'
	 * @param string                       $type        'color', 'number', 'text', 'options', or 'time'
	 * @param array<string|int,int|string> $options     An optional list of values that the setting can be, semi-colon delimited.
	 *                                                  Alternatively, use an associative array [label => value], where label is optional.
	 * @param AccessLevel                  $accessLevel The permission level needed to change this setting (default: mod) (optional)
	 * @param ?string                      $help        A help file for this setting; if blank, will use a help topic with the same name as this setting if it exists (optional)
	 *
	 * @throws SQLException if the setting causes SQL errors (text too long, etc.)
	 */
	public function add(
		string $module,
		string $name,
		string $description,
		SettingMode $mode,
		int|float|string|bool $value,
		string $type,
		array $options=[],
		AccessLevel $accessLevel=AccessLevel::Mod,
		?string $help=null,
		?bool $confidential=false,
	): void {
		$value = $this->getHardcoded($name) ?? $value;
		$name = strtolower($name);
		$type = strtolower($type);

		if (!isset($this->settingHandlers[$type])) {
			$this->logger->error(
				'Error in registering Setting {module}:{name}. '.
				"Invalid type '{type}'. Allowed are: {allowed_types}.",
				[
					'allowed_types' => implode(', ', array_keys($this->settingHandlers)),
					'type' => $type,
					'module' => $module,
					'name' => $name,
				]
			);
		}

		if ($type === 'time') {
			$oldvalue = $value;
			$value = Util::parseTime((string)$value);
			if ($value < 1) {
				$this->logger->error("Error in registering Setting {module}:setting({setting}). Invalid time: '{time}'.", [
					'module' => $module,
					'setting' => $name,
					'time' => $oldvalue,
				]);
				return;
			}
		}
		if ($type === 'bool' && $value === false) {
			$value = '0';
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
		$options = implode(';', array_keys($kv));
		$intoptions = null;
		if ($needIntOptions) {
			$intoptions = implode(';', array_values($kv));
		}

		if (isset($help) && $help !== '') {
			$help = $this->helpManager->checkForHelpFile($module, $help);
		}

		try {
			$setting = new Setting(
				admin: $accessLevel,
				description: $description,
				help: $help,
				intoptions: $intoptions,
				mode: $mode,
				module: $module,
				name: $name,
				options: $options,
				source: 'db',
				type: $type,
				verify: 1,
				value: (string)$value,
				confidential: $confidential,
			);
			if (isset($this->configuredSettings[$name]) || $this->exists($name)) {
				$this->db->table(Setting::getTable())
					->where('name', $name)
					->update([
						'module' => $module,
						'type' => $type,
						'mode' => $mode,
						'options' => $options,
						'intoptions' => $intoptions,
						'description' => $description,
						'confidential' => $confidential,
						'verify' => 1,
						'help' => $help,
					]);
				if (array_key_exists($name, $this->settings)) {
					$setting->value = $this->settings[$name]->value;
				} else {
					$this->settings[$name] = new SettingValue($setting);
				}
			} else {
				$this->db->insert(new Setting(
					name: $name,
					module: $module,
					type: $type,
					mode: $mode,
					value: (string)$value,
					options: $options,
					intoptions: $intoptions,
					description: $description,
					source: 'db',
					admin: $accessLevel,
					verify: 1,
					help: $help,
					confidential: $confidential,
				));
			}
			$this->settings[$name] = new SettingValue($setting);
		} catch (SQLException $e) {
			$this->logger->error('Error in registering Setting {module}:setting({setting}): {error}', [
				'module' => $module,
				'setting' => $name,
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}

	/** @return array<string,SettingValue> */
	public function getSettings(): array {
		return $this->settings;
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
	 * Gets the stringified value of a setting - nothing else
	 *
	 * @param string $name name of the setting to read
	 *
	 * @return null|string the value of the setting, or false if a setting with that name does not exist
	 */
	public function getValue(string $name): null|string {
		return ($this->settings[strtolower($name)]??null)?->value;
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
			$value = $this->db->table(Setting::getTable())
				->where('name', $name)
				->firstObj(Setting::class);
			if (isset($value)) {
				return (new SettingValue($value))->value;
			}
		}
		$this->logger->error("Could not retrieve value for setting '{setting}' because setting does not exist", [
			'setting' => $name,
		]);
		return false;
	}

	/** @return int|bool|string|list<mixed>|null */
	public function getTyped(string $name): null|int|bool|string|array {
		$name = strtolower($name);
		if ($this->exists($name)) {
			return $this->settings[$name]->typed();
		} elseif (!static::$isInitialized) {
			$value = $this->db->table(Setting::getTable())
				->where('name', $name)
				->firstObj(Setting::class);
			if (isset($value)) {
				return (new SettingValue($value))->typed();
			}
			return null;
		}

		$this->logger->error("Could not retrieve value for setting '{setting}' because setting does not exist", [
			'setting' => $name,
		]);
		return null;
	}

	public function getInt(string $name): ?int {
		$value = $this->getTyped($name);
		if (is_int($value) || is_bool($value)) {
			return (int)$value;
		}
		$this->logger->error("Wrong type for setting '{name}' requested. Expected 'int', got '{type}' ({value})", [
			'name' => $name,
			'type' => gettype($value),
			'value' => $value,
		]);
		return null;
	}

	public function getBool(string $name): ?bool {
		$value = $this->getTyped($name);
		if (is_bool($value)) {
			return $value;
		}
		$this->logger->error("Wrong type for setting '{name}' requested. Expected 'bool', got '{type}' ({value})", [
			'name' => $name,
			'type' => gettype($value),
			'value' => $value,
		]);
		return null;
	}

	public function getString(string $name): ?string {
		$value = $this->getTyped($name);
		if (is_string($value)) {
			return $value;
		}
		$this->logger->error("Wrong type for setting '{setting}' requested. Expected 'string', got '{type}'", [
			'setting' => $name,
			'type' => gettype($value),
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
				'value' => $value,
				'setting' => $name,
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
		$newValue = clone $this->settings[$name];
		$newValue->value = (string)$value;
		$event = new SettingEvent(
			setting: $name,
			oldValue: $this->settings[$name],
			newValue: $newValue,
		);
		$this->eventManager->dispatch($event);

		$this->settings[$name]->value = (string)$value;
		$this->db->table(Setting::getTable())
			->where('name', $name)
			->update([
				'verify' => 1,
				'value' => $value,
			]);
		return true;
	}

	/** Load settings from the database */
	public function upload(): void {
		$this->settings = [];

		// Upload Settings from the db that are set by modules
		$data = $this->db->table(Setting::getTable())->asObj(Setting::class);
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
	 * @param string  $settingName changed setting's name
	 * @param Closure $callback    the callback function to call
	 * @param mixed   $data        any data which will be passed to the callback (optional)
	 */
	public function registerChangeListener(string $settingName, Closure $callback, mixed $data=null): void {
		$settingName = strtolower($settingName);

		$listener = new ChangeListener(
			callback: $callback,
			data: $data,
		);
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
				'type' => $row->type,
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
