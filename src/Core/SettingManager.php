<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\DBSchema\Setting;

/**
 * @Instance
 */
class SettingManager {
	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public HelpManager $helpManager;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @var array<string,SettingValue> $settings */
	public array $settings = [];

	/** @var array<string,ChangeListener[]> $changeListeners */
	private array $changeListeners = [];

	/**
	 * Register a setting for a module
	 *
	 * @param string $module The module name
	 * @param string $name The name of the setting
	 * @param string $description A description for the setting (will appear in the config)
	 * @param string $mode 'edit' or 'noedit'
	 * @param string $type 'color', 'number', 'text', 'options', or 'time'
	 * @param mixed  $value
	 * @param string $options An optional list of values that the setting can be, semi-colon delimited
	 * @param string $intoptions Int values corresponding to $options; if empty, the values from $options will be what is stored in the database (optional)
	 * @param string $accessLevel The permission level needed to change this setting (default: mod) (optional)
	 * @param string $help A help file for this setting; if blank, will use a help topic with the same name as this setting if it exists (optional)
	 * @return void
	 * @throws SQLException if the setting causes SQL errors (text too long, etc.)
	 */
	public function add(
		 string $module,
		 string $name,
		 string $description,
		 string $mode,
		 string $type,
		 $value,
		 ?string $options='',
		 ?string $intoptions='',
		 ?string $accessLevel='mod',
		 ?string $help=''
	): void {
		$name = strtolower($name);
		$type = strtolower($type);

		if ($accessLevel == '') {
			$accessLevel = 'mod';
		}
		$accessLevel = $this->accessManager->getAccessLevel($accessLevel);

		if (!in_array($type, ['color', 'number', 'text', 'options', 'time', 'discord_channel'])) {
			$this->logger->log('ERROR', "Error in registering Setting $module:setting($name). Type should be one of: 'color', 'number', 'text', 'options', 'time'. Actual: '$type'.");
		}

		if ($type == 'time') {
			$oldvalue = $value;
			$value = $this->util->parseTime($value);
			if ($value < 1) {
				$this->logger->log('ERROR', "Error in registering Setting $module:setting($name). Invalid time: '{$oldvalue}'.");
				return;
			}
		}

		if (!empty($help)) {
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
			if (array_key_exists($name, $this->chatBot->existing_settings ?? []) || $this->exists($name)) {
				$sql = "UPDATE settings_<myname> SET `module` = ?, `type` = ?, `mode` = ?, `options` = ?, `intoptions` = ?, `description` = ?, `admin` = ?, `verify` = 1, `help` = ? WHERE `name` = ?";
				$this->db->exec($sql, $module, $type, $mode, $options, $intoptions, $description, $accessLevel, $help, $name);
				$setting->value = $this->settings[$name]->value;
			} else {
				$sql = "INSERT INTO settings_<myname> ".
					"(`name`, `module`, `type`, `mode`, `value`, `options`, `intoptions`, `description`, `source`, `admin`, `verify`, `help`) ".
					"VALUES ".
					"(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
				$this->db->exec($sql, $name, $module, $type, $mode, $value, $options, $intoptions, $description, 'db', $accessLevel, '1', $help);
			}
			$this->settings[$name] = new SettingValue($setting);
		} catch (SQLException $e) {
			$this->logger->log('ERROR', "Error in registering Setting $module:setting($name): " . $e->getMessage());
		}
	}

	/**
	 * Determine if a setting with a given name exists
	 *
	 * @param string $name Setting to check
	 * @return bool true if the setting exists, false otherwise
	 */
	public function exists(string $name): bool {
		return array_key_exists($name, $this->settings);
	}

	/**
	 * Gets the value of a setting
	 *
	 * @param string $name name of the setting to read
	 * @return string|int|false the value of the setting, or false if a setting with that name does not exist
	 */
	public function get(string $name) {
		$name = strtolower($name);
		if ($this->exists($name)) {
			return $this->settings[$name]->value;
		}
		$this->logger->log("ERROR", "Could not retrieve value for setting '$name' because setting does not exist");
		return false;
	}

	public function getTyped(string $name) {
		$name = strtolower($name);
		if ($this->exists($name)) {
			return $this->settings[$name]->typed();
		}
		$this->logger->log("ERROR", "Could not retrieve value for setting '$name' because setting does not exist");
		return null;
	}

	public function getInt(string $name): ?int {
		$value = $this->getTyped($name);
		if (is_int($value) || is_bool($value)) {
			return (int)$value;
		}
		$type = gettype($value);
		$this->logger->log("ERROR", "Wrong type for setting '$name' requested. Expected 'int', got '$type' ($value)");
		return null;
	}

	public function getBool(string $name): ?bool {
		$value = $this->getTyped($name);
		if (is_bool($value)) {
			return $value;
		}
		$type = gettype($value);
		$this->logger->log("ERROR", "Wrong type for setting '$name' requested. Expected 'bool', got '$type'");
		return null;
	}

	public function getString(string $name): ?string {
		$value = $this->getTyped($name);
		if (is_string($value)) {
			return $value;
		}
		$type = gettype($value);
		$this->logger->log("ERROR", "Wrong type for setting '$name' requested. Expected 'string', got '$type'");
		return null;
	}

	/**
	 * Saves a new value for a setting
	 *
	 * @param string $name The name of the setting
	 * @param string|int $value The new value to set the setting to
	 * @return bool false if the setting with that name does not exist, true otherwise
	 */
	public function save(string $name, $value): bool {
		$name = strtolower($name);

		if (!$this->exists($name)) {
			$this->logger->log("ERROR", "Could not save value '$value' for setting '$name' because setting does not exist");
			return false;
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
		$this->settings[$name]->value = $value;
		$this->db->exec("UPDATE settings_<myname> SET `verify` = 1, `value` = ? WHERE `name` = ?", $value, $name);
		return true;
	}

	/**
	 * Load settings from the database
	 */
	public function upload(): void {
		$this->settings = [];

		//Upload Settings from the db that are set by modules
		/** @var Setting[] $data */
		$data = $this->db->fetchAll(Setting::class, "SELECT * FROM settings_<myname>");
		foreach ($data as $row) {
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
	public function registerChangeListener(string $settingName, callable $callback, $data=null): void {
		if (!is_callable($callback)) {
			$this->logger->log('ERROR', 'Given callback is not valid.');
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

	/**
	 * Get the handler for a setting
	 */
	public function getSettingHandler(Setting $row): ?SettingHandler {
		$handler = null;
		switch ($row->type) {
			case 'color':
				$handler = new ColorSettingHandler($row);
				break;
			case 'text':
				$handler = new TextSettingHandler($row);
				break;
			case 'number':
				$handler = new NumberSettingHandler($row);
				break;
			case 'options':
				$handler = new OptionsSettingHandler($row);
				break;
			case 'time':
				$handler = new TimeSettingHandler($row);
				break;
			case 'discord_channel':
				$handler = new DiscordChannelSettingHandler($row);
				break;
			default:
				$this->loggger->log('ERROR', "Could not find setting handler for setting type: '$row->type'");
		}
		Registry::injectDependencies($handler);
		return $handler;
	}
}
