<?php

namespace Budabot\Core;

use stdClass;

/**
 * @Instance
 */
class SettingManager {
	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;

	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;

	/**
	 * @var \Budabot\Core\Util $util
	 * @Inject
	 */
	public $util;

	/**
	 * @var \Budabot\Core\HelpManager $helpManager
	 * @Inject
	 */
	public $helpManager;

	/**
	 * @var \Budabot\Core\AccessManager $accessManager
	 * @Inject
	 */
	public $accessManager;

	/**
	 * @var \Budabot\Core\LoggerWrapper $logger
	 * @Logger
	 */
	public $logger;

	/** @var mixed[] $settings */
	public $settings = array();

	/** @var \StdClass[] $changeListeners */
	private $changeListeners = array();

	/**
	 * Register a setting for a module
	 *
	 * @param string $module The module name
	 * @param string $name The name of the setting
	 * @param string $description A description for the setting (will appear in the config)
	 * @param string $mode 'edit' or 'noedit'
	 * @param string $type 'color', 'number', 'text', 'options', or 'time'
	 * @param string $options An optional list of values that the setting can be, semi-colon delimited
	 * @param string $intoptions Int values corresponding to $options; if empty, the values from $options will be what is stored in the database (optional)
	 * @param string $accessLevel The permission level needed to change this setting (default: mod) (optional)
	 * @param string $help A help file for this setting; if blank, will use a help topic with the same name as this setting if it exists (optional)
	 * @return void
	 * @throws \SQLException if the setting causes SQL errors (text too long, etc.)
	 */
	public function add($module, $name, $description, $mode, $type, $value, $options='', $intoptions='', $accessLevel='mod', $help='') {
		$name = strtolower($name);
		$type = strtolower($type);

		if ($accessLevel == '') {
			$accessLevel = 'mod';
		}
		$accessLevel = $this->accessManager->getAccessLevel($accessLevel);

		if (!in_array($type, array('color', 'number', 'text', 'options', 'time'))) {
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
			if (array_key_exists($name, $this->chatBot->existing_settings) || array_key_exists($name, $this->settings)) {
				$sql = "UPDATE settings_<myname> SET `module` = ?, `type` = ?, `mode` = ?, `options` = ?, `intoptions` = ?, `description` = ?, `admin` = ?, `verify` = 1, `help` = ? WHERE `name` = ?";
				$this->db->exec($sql, $module, $type, $mode, $options, $intoptions, $description, $accessLevel, $help, $name);
			} else {
				$sql = "INSERT INTO settings_<myname> ".
					"(`name`, `module`, `type`, `mode`, `value`, `options`, `intoptions`, `description`, `source`, `admin`, `verify`, `help`) ".
					"VALUES ".
					"(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
				$this->db->exec($sql, $name, $module, $type, $mode, $value, $options, $intoptions, $description, 'db', $accessLevel, '1', $help);
				$this->settings[$name] = $value;
			}
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
	public function exists($name) {
		return array_key_exists($name, $this->settings);
	}

	/**
	 * Gets the value of a setting
	 *
	 * @param string $name name of the setting to read
	 * @return string|int|false the value of the setting, or false if a setting with that name does not exist
	 */
	public function get($name) {
		$name = strtolower($name);
		if (array_key_exists($name, $this->settings)) {
			return $this->settings[$name];
		} else {
			$this->logger->log("ERROR", "Could not retrieve value for setting '$name' because setting does not exist");
			return false;
		}
	}

	/**
	 * Saves a new value for a setting
	 *
	 * @param string $name The name of the setting
	 * @param string|int $value The new value to set the setting to
	 * @return bool false if the setting with that name does not exist, true otherwise
	 */
	public function save($name, $value) {
		$name = strtolower($name);

		if (array_key_exists($name, $this->settings)) {
			if ($this->settings[$name] !== $value) {
				// notify any listeners
				if (isset($this->changeListeners[$name])) {
					foreach ($this->changeListeners[$name] as $listener) {
						call_user_func($listener->callback, $name, $this->settings[$name], $value, $listener->data);
					}
				}
				$this->settings[$name] = $value;
				$this->db->exec("UPDATE settings_<myname> SET `verify` = 1, `value` = ? WHERE `name` = ?", $value, $name);
			}
			return true;
		} else {
			$this->logger->log("ERROR", "Could not save value '$value' for setting '$name' because setting does not exist");
			return false;
		}
	}

	/**
	 * Load settings from the database
	 *
	 * @return void
	 */
	public function upload() {
		$this->settings = array();

		//Upload Settings from the db that are set by modules
		$data = $this->db->query("SELECT * FROM settings_<myname>");
		foreach ($data as $row) {
			$this->settings[$row->name] = $row->value;
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
	public function registerChangeListener($settingName, $callback, $data=null) {
		if (!is_callable($callback)) {
			$this->logger->log('ERROR', 'Given callback is not valid.');
			return;
		}
		$settingName = strtolower($settingName);

		$listener = new StdClass();
		$listener->callback = $callback;
		$listener->data = $data;
		$this->changeListeners[$settingName] []= $listener;
	}

	/**
	 * Get the handler for a setting
	 *
	 * @param \Budabot\Core\DBRow $row The database row with the setting
	 * @return \Budabot\Core\SettingHandler|null null if none found for the setting type
	 */
	public function getSettingHandler($row) {
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
			default:
				$this->loggger->log('ERROR', "Could not find setting handler for setting type: '$row->type'");
		}
		Registry::injectDependencies($handler);
		return $handler;
	}
}
