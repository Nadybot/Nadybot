<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SETUP;

use Nadybot\Core\ConfigFile;

/**
 * Description: Configuration of the Basicbot settings
 *
 * @author Derroylo (RK2)
 * @link http://sourceforge.net/projects/budabot
 *
 * Date(created): 15.01.2006
 * Date(last modified): 22.07.2006
 *
 * @copyright 2006 Carsten Lohmann
 *
 * @license GPL
 */
class Setup {
	const INDENT = 13;

	public ConfigFile $configFile;
	public array $vars = [];

	public function __construct(ConfigFile $configFile) {
		$this->configFile = $configFile;
	}

	public function readInput(string $output=""): string {
		echo $output;
		return trim(fgets(STDIN));
	}

	public function showStep(string $text) {
		$indentString = str_repeat(" ", self::INDENT);
		$lines = explode("\n", trim($text));
		echo "\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n";
		echo "{$indentString}**********************************************************\n";
		echo $indentString.join("\n$indentString", $lines)."\n";
		echo "{$indentString}**********************************************************\n";
		echo "\n\n\n\n\n\n\n\n\n";
	}

	public function showIntro(): void {
		$this->showStep(
			"You will need to provide some information\n".
			"regarding the basic configuration of the bot.\n"
		);
		$msg = "Press enter to continue.\n";
		$this->readInput($msg);
		$this->queryAccountUsername();
	}

	public function queryAccountUsername(): void {
		$this->showStep(
			"Enter the account username that contains the\n".
			"character the bot will run on. \n".
			"Remember this name is case-sensitive!\n"
		);
		$msg = "Enter the account username (case-senstitive): ";
		do {
			$this->vars["login"] = $this->readInput($msg);
		} while ($this->vars["login"] === "");
		$this->queryAccountPassword();
	}

	public function queryAccountPassword(): void {
		$this->showStep(
			"Enter the Password for the account.\n".
			"Remember this is also case-sensitive!\n"
		);
		$msg = "Enter the account password (case-senstitive): ";
		do {
			$this->vars["password"] = $this->readInput($msg);
		} while ($this->vars["password"] === "");
		$this->queryAccountDimension();
	}

	public function queryAccountDimension(): void {
		$this->showStep(
			"Enter the dimension that the bot will run on.\n".
			"The following are available:\n".
			"  [4] Test Server\n".
			"  [5] Rubi-Ka (a.k.a. RK5)\n".
			"  [6] RK2019\n"
		);

		$msg = "Choose a Dimension: ";
		do {
			$this->vars["dimension"] = (int)$this->readInput($msg);
		} while (!in_array($this->vars["dimension"], [4, 5, 6], true));
		$this->queryCharacter();
	}

	public function queryCharacter(): void {
		$this->showStep(
			"Enter the character the bot will run on.\n".
			"If the character does not already exist, close this\n".
			"and create the character and then start the bot again.\n".
			"Make sure the bot toon is not currently logged on\n".
			"or the bot will not be able to log on.\n"
		);

		$msg = "Enter the Character the bot will run as: ";
		do {
			$this->vars["name"] = $this->readInput($msg);
		} while ($this->vars["name"] === "");
		$this->queryOrgname();
	}

	public function queryOrgname(): void {
		$this->showStep(
			"To run the bot as a raid bot, leave this setting blank.\n".
			"To run the bot as an org bot, enter the organization name.\n".
			"The organization name must match exactly including case\n".
			"and punctuation!\n".
			"Leave this blank if this bot is not going to be an org bot\n"
		);

		$msg = "Enter your Organization: ";
		$this->vars["my_guild"] = $this->readInput($msg);
		$this->querySuperuser();
	}

	public function querySuperuser(): void {
		$this->showStep(
			"Who should be the Administrator for this bot?\n".
			"This is the character that has access to all commands\n".
			"and settings for this bot.\n"
		);

		$msg = "Enter the Administrator for this bot: ";
		do {
			$this->vars["SuperAdmin"] = $this->readInput($msg);
		} while ($this->vars["SuperAdmin"] === "");
		$this->queryDatabaseInstallation();
	}

	public function queryDatabaseInstallation(): void {
		$this->showStep(
			"Now we are coming to the 'heart' of this bot,\n".
			"the database system where nearly everything is\n".
			"stored. You have 2 options now. Either you can\n".
			"set it up manually or leave the default settings.\n".
			"The default settings are recommended for normal\n".
			"users. If you choose to set it up manually,\n".
			"you will be able to choose between\n".
			"MySQL and Sqlite.\n"
		);
		$msg = "Do you want to setup the database manually (yes/no): ";
		do {
			$manualSetupDB = strtolower($this->readInput($msg));
		} while (!in_array($manualSetupDB, ['no', 'yes'], true));
		if ($manualSetupDB === 'no') {
			$this->queryEnabledModules();
		} else {
			$this->queryDatabaseType();
		}
	}

	public function queryEnabledModules(): void {
		$this->showStep(
			"Do you want to have all modules/commands enabled\n".
			"by default?\n".
			"This is usefull when you are using this bot the\n".
			"first time so that all commands are available\n".
			"from the beginning.  If you say 'no' to this question\n".
			"you will need to enable the commands manually.\n".
			"(Recommended: yes)\n"
		);

		$msg = "Should all modules be enabled ? (yes/no): ";
		do {
			$this->vars["default_module_status"] = strtolower($this->readInput($msg));
		} while (!in_array($this->vars["default_module_status"], ['yes', 'no'], true));

		$this->vars["default_module_status"] = ($this->vars['default_module_status'] === 'yes') ? 1 : 0;
		$this->saveSettings();
	}

	public function queryDatabaseType(): void {
		$this->showStep(
			"The bot is able to use 2 different Database Types:\n".
			"  [1] Sqlite\n".
			"      It is the easiest way to go and provides\n".
			"      faster bot startup than MySQL.\n".
			"  [2] MySQL/MariaDB\n".
			"      An Open-Source Database.\n".
			"      You need to install and setup it manually\n".
			"      https://www.mysql.com/ https://mariadb.org/ \n".
			"      Be aware that when you set it up incorrectly,\n".
			"      it can be slower than Sqlite!\n"
		);

		$msg = "Choose a Database system (1/2): ";
		do {
			$this->vars["DB Type"] = (int)$this->readInput($msg);
		} while (!in_array($this->vars["DB Type"], [1, 2], true));

		$this->vars["DB Type"] = ($this->vars["DB Type"] === 1) ? "sqlite" : "mysql";
		$this->queryDatabaseName();
	}

	public function queryDatabaseName(): void {
		$txt = "What is the name of the database that you\n".
			"wannna use?\n";
		if ($this->vars["DB Type"] === "sqlite") {
			$txt .= "(This is the filename of the database)\n".
				"(Default: nadybot.db)\n";
		} else {
			$txt .= "(Default: nadybot)\n";
		}
		$this->showStep($txt);
		$msg = "Enter the Databasename (leave blank for default setting): ";
		$this->vars["DB Name"] = $this->readInput($msg);

		if ($this->vars["DB Name"] === "" && $this->vars["DB Type"] == "sqlite") {
			$this->vars["DB Name"] = "nadybot.db";
		} elseif ($this->vars["DB Name"] === "" && $this->vars["DB Type"] === "mysql") {
			$this->vars["DB Name"] = "nadybot";
		}
		if ($this->vars["DB Type"] === "mysql") {
			$this->queryMysqlHostname();
		} else {
			$this->querySqlitePath();
		}
	}

	public function queryMysqlHostname(): void {
		$this->showStep(
			"On what Host is the Database running?\n".
			"If it is running on this PC use:\n".
			"localhost or 127.0.0.1\n".
			"otherwise insert Hostname or IP\n".
			"(Default: localhost)\n"
		);

		$msg = "Enter the Hostname for the Database (leave blank for default setting): ";
		$this->vars["DB Host"] = $this->readInput($msg);

		if ($this->vars["DB Host"] === "") {
			$this->vars["DB Host"] = "localhost";
		}
		$this->queryMysqlUsername();
	}

	public function queryMysqlUsername(): void {
		$this->showStep(
			"What is the username for the MySQL Database?\n".
			"If you did not specify a username when you installed\n".
			"the Database then it will be 'root'\n".
			"(Default: root)\n"
		);
		$msg = "Enter username for the Database (leave blank for default setting): ";
		$this->vars["DB username"] = $this->readInput($msg);

		if ($this->vars["DB username"] === "") {
			$this->vars["DB username"] = "root";
		}
		$this->queryMysqlPassword();
	}

	public function queryMysqlPassword(): void {
		$this->showStep(
			"What is the password for the MySQL Database?\n".
			"if you did not specify a username when you installed\n".
			"the Database then it will be blank (none)\n".
			"(Default: <blank>)\n"
		);
		$msg = "Enter password for the Database: ";
		$this->vars["DB password"] = $this->readInput($msg);
		$this->queryEnabledModules();
	}

	public function querySqlitePath(): void {
		$this->showStep(
			"Where is the Sqlite Database stored?\n".
			"You may leave this setting blank to use the default\n".
			"location which is the Data dir of your bot folder.\n".
			"The Database will be created if it does\n".
			"not already exists.\n".
			"(Default: ./data/)\n"
		);
		$msg = "Enter the path for the Database (leave blank for default setting): ";
		$this->vars["DB Host"] = $this->readInput($msg);

		if ($this->vars["DB Host"] === "") {
			$this->vars["DB Host"] = "./data/";
		}
		$this->queryEnabledModules();
	}

	public function saveSettings(): void {
		$this->showStep(
			"If you have entered everything correctly, \n".
			"the bot should now be configured properly.\n".
			"\n".
			"We would appreciate any feedback you have.\n".
			"Comments and suggestions are more than welcome!\n".
			"https://github.com/nadybot/nadybot\n".
			"\n".
			"Have a good day on Rubi-Ka.\n".
			"To rerun this setup simply delete your\n".
			"config file: {$this->configFile->getFilePath()}\n"
		);

		//Save the entered info to $configFile
		$this->configFile->insertVars($this->vars);
		$this->configFile->save();

		$msg = "Press [Enter] to close setup.\n";
		$this->readInput($msg);
	}
}
