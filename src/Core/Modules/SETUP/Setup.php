<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SETUP;

use function Amp\ByteStream\getStdin;
use function Amp\Socket\connect;

use Amp\ByteStream\BufferedReader;
use Amp\TimeoutCancellation;
use AO\Client\{SingleClient, WorkerConfig};
use AO\Utils;
use Nadybot\Core\{
	Config\BotConfig,
	DB\DBType,
	Filesystem,
	Terminal,
	Types\Status
};
use Psr\Log\LoggerInterface;

/**
 * Description: Configuration of the Basicbot settings
 *
 * @author Derroylo (RK2)
 *
 * @see http://sourceforge.net/projects/budabot
 *
 * Date(created): 15.01.2006
 * Date(last modified): 22.07.2006
 *
 * @copyright 2006 Carsten Lohmann
 * @license GPL
 */
class Setup {
	private const INDENT = 13;

	public function __construct(
		private BotConfig $configFile,
		private Filesystem $fs,
		private LoggerInterface $logger,
	) {
	}

	public function readInput(string $output=''): string {
		echo $output;
		$input = (new BufferedReader(getStdin()))->readUntil(\PHP_EOL);
		return $input;
	}

	public function showStep(string $text): void {
		$height = Terminal::getHeight();
		$indentString = str_repeat(' ', self::INDENT);
		$lines = explode("\n", trim($text));
		echo str_repeat(\PHP_EOL, $height);
		echo "{$indentString}**********************************************************\n";
		echo $indentString.implode("\n{$indentString}", $lines)."\n";
		echo "{$indentString}**********************************************************\n";
		echo str_repeat("\n", max(1, (int)floor(($height - count($lines) - 2)/2)));
	}

	public function showIntro(): BotConfig {
		$this->showStep(
			"You will need to provide some information\n".
			"regarding the basic configuration of the bot.\n".
			"Do you want to configure the boto via\n".
			"\t[1] Basic text mode questions\n".
			"\t[2] A proper WebUI"
		);
		$msg = 'Choose [1] or [2]: ';
		do {
			$result = $this->readInput($msg);
		} while (!in_array($result, ['1', '2'], true));
		if ($result === '2') {
			$webUI = new WebSetup($this, $this->configFile, $this->fs, $this->logger);
			return $webUI->serve();
		}
		$this->queryAccountUsername();
		return $this->configFile;
	}

	public function queryAccountUsername(): void {
		$this->showStep(
			"Enter the account username that contains the\n".
			"character the bot will run on. \n".
			"Remember this name is case-sensitive!\n"
		);
		$msg = 'Enter the account username (case-senstitive): ';
		do {
			$this->configFile->main->login = $this->readInput($msg);
		} while ($this->configFile->main->login === '');
		$this->queryAccountPassword();
	}

	public function queryAccountPassword(): void {
		$this->showStep(
			"Enter the Password for the account.\n".
			"Remember this is also case-sensitive!\n"
		);
		$msg = 'Enter the account password (case-senstitive): ';
		do {
			$this->configFile->main->password = $this->readInput($msg);
		} while ($this->configFile->main->password === '');
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

		$msg = 'Choose a Dimension: ';
		do {
			$this->configFile->main->dimension = (int)$this->readInput($msg);
		} while (!in_array($this->configFile->main->dimension, [4, 5, 6], true));
		$this->queryCharacter();
	}

	public function queryCharacter(): void {
		/** @var ?list<\AO\Character> */
		$chars = null;
		try {
			$workerConf = new WorkerConfig(
				dimension: $this->configFile->main->dimension,
				username: $this->configFile->main->login,
				password: $this->configFile->main->password,
				character: 'Xxxx'
			);
			$connection = connect(uri: $workerConf->getServer(), cancellation: new TimeoutCancellation(10));
			$client = new SingleClient(
				connection: new \AO\Connection(reader: $connection, writer: $connection),
				parser: \AO\Parser::createDefault(),
			);
			$chars = $client->getChars($workerConf->username, $workerConf->password);
		} catch (\Throwable) {
		}
		$text = "Enter the character the bot will run on.\n".
			"If the character does not already exist, close this\n".
			"and create the character and then start the bot again.\n".
			"Make sure the bot toon is not currently logged on\n".
			"or the bot will not be able to log on.\n";

		if (isset($chars)) {
			if (!count($chars)) {
				$text .= "\nThere are no characters on this account!\n";
			} else {
				$text .= "\nThe following characters were found on this account:";
				$i = 1;
				foreach ($chars as $char) {
					$text .= "\n    [{$i}] {$char->name} (level {$char->level})";
					if ($char->online) {
						$text .= ' - logged in';
					}
					$i++;
				}
				$text .= "\n";
			}
			$text .= "    [B] Back\n";
		}

		$this->showStep($text);

		$msg = 'Enter the Character the bot will run as: ';
		do {
			$choice = $this->readInput($msg);
			if (ctype_digit($choice) && isset($chars) && count($chars) >= (int)$choice) {
				$choice = $chars[(int)$choice-1]->name;
			}
		} while (!in_array($choice, ['b', 'B'], true) && strlen($choice) < 4);
		if (in_array($choice, ['b', 'B'], true)) {
			$this->queryAccountUsername();
		}
		$this->configFile->main->character = Utils::normalizeCharacter($choice);
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

		$msg = 'Enter your Organization: ';
		$this->configFile->general->orgName = $this->readInput($msg);
		$this->querySuperuser();
	}

	public function querySuperuser(): void {
		$this->showStep(
			"Who should be the Administrator for this bot?\n".
			"This is the character that has access to all commands\n".
			"and settings for this bot.\n"
		);

		$msg = 'Enter the Administrator for this bot: ';
		do {
			$superAdmin = $this->readInput($msg);
		} while ($superAdmin === '');
		$this->configFile->general->superAdmins = [Utils::normalizeCharacter($superAdmin)];
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
			"PostgreSQL, MySQL, and Sqlite.\n"
		);
		$msg = 'Do you want to setup the database manually (yes/no): ';
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
			"This is useful when you are using this bot the\n".
			"first time so that all commands are available\n".
			"from the beginning.  If you say 'no' to this question\n".
			"you will need to enable the commands manually.\n".
			"(Recommended: yes)\n"
		);

		$msg = 'Should all modules be enabled ? (yes/no): ';
		do {
			$defaultModuleStatus = strtolower($this->readInput($msg));
		} while (!in_array($defaultModuleStatus, ['yes', 'no'], true));

		$this->configFile->general->defaultModuleStatus = ($defaultModuleStatus === 'yes') ? Status::Enabled : Status::Disabled;
		$this->saveSettings();
	}

	public function queryDatabaseType(): void {
		$this->showStep(
			"The bot is able to use 3 different Database Types:\n".
			"  [1] SQLite\n".
			"      It is the easiest way to go and provides\n".
			"      faster bot startup than MySQL or PostgreSQL.\n".
			"      It's the best choice for 99% of all users.\n".
			"  [2] MySQL/MariaDB\n".
			"      An Open-source database.\n".
			"      You need to install and setup it manually\n".
			"      https://www.mysql.com/ https://mariadb.org/ \n".
			"      Be aware that nowadays MySQL/MariaDB is only\n".
			"      useful if you run multiple bots on the same database,\n".
			"      because for most scenarios it is slower than SQLite.\n".
			"  [3] PostgreSQL\n".
			"      An enterprise-grade Open-source database.\n".
			"      You need to install and setup it manually\n".
			"      https://www.postgresql.org/download/\n".
			"      It offers better performance than MySQL for Nadybot,\n".
			"      but it's also only useful if you run multiple bots\n".
			"      on the same server.\n"
		);

		$msg = 'Choose a Database system (1/2/3): ';
		$dbs = [
			1 => DBType::SQLite,
			2 => DBType::MySQL,
			3 => DBType::PostgreSQL,
		];
		do {
			$dbType = $this->readInput($msg);
		} while (!isset($dbs[(int)$dbType]));

		$this->configFile->database->type = $dbs[(int)$dbType];
		$this->queryDatabaseName();
	}

	public function queryDatabaseName(): void {
		$txt = "What is the name of the database that you\n".
			"wannna use?\n";
		if ($this->configFile->database->type === DBType::SQLite) {
			$txt .= "(This is the filename of the database)\n".
				"(Default: nadybot.db)\n";
		} else {
			$txt .= "(Default: nadybot)\n";
		}
		$this->showStep($txt);
		$msg = 'Enter the Databasename (leave blank for default setting): ';
		$this->configFile->database->name = $this->readInput($msg);

		if ($this->configFile->database->name === '' && $this->configFile->database->type === DBType::SQLite) {
			$this->configFile->database->name = 'nadybot.db';
		} elseif ($this->configFile->database->name === '' && $this->configFile->database->type !== DBType::SQLite) {
			$this->configFile->database->name = 'nadybot';
		}
		if ($this->configFile->database->type === DBType::SQLite) {
			$this->querySqlitePath();
		} else {
			$this->queryMysqlHostname();
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

		$msg = 'Enter the Hostname for the Database (leave blank for default setting): ';
		$this->configFile->database->host = $this->readInput($msg);

		if ($this->configFile->database->host === '') {
			$this->configFile->database->host = 'localhost';
		}
		$this->queryMysqlUsername();
	}

	public function queryMysqlUsername(): void {
		$this->showStep(
			"What is the username for the Database?\n".
			"If you did not specify a username when you installed\n".
			"the Database then it will be 'root'\n".
			"(Default: root)\n"
		);
		$msg = 'Enter username for the Database (leave blank for default setting): ';
		$this->configFile->database->username = $this->readInput($msg);

		if ($this->configFile->database->username === '') {
			$this->configFile->database->username = 'root';
		}
		$this->queryMysqlPassword();
	}

	public function queryMysqlPassword(): void {
		$this->showStep(
			"What is the password for the  Database?\n".
			"If you did not specify a username when you installed\n".
			"the Database then it will be blank (none)\n".
			"(Default: <blank>)\n"
		);
		$msg = 'Enter password for the Database: ';
		$this->configFile->database->password = $this->readInput($msg);
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
		$msg = 'Enter the path for the Database (leave blank for default setting): ';
		$this->configFile->database->host = $this->readInput($msg);

		if ($this->configFile->database->host === '') {
			$this->configFile->database->host = './data/';
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

		// Save the entered info to $configFile
		$this->configFile->save($this->fs);

		$msg = "Press [Enter] to close setup.\n";
		$this->readInput($msg);
	}
}
