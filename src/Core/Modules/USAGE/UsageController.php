<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\USAGE;

use Nadybot\Core\Attributes as NCA;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	BotRunner,
	CmdContext,
	ConfigFile,
	DB,
	EventManager,
	Nadybot,
	SettingManager,
	SQLException,
	Text,
	Util,
};
use Nadybot\Core\ParamClass\PCharacter;
use Nadybot\Core\ParamClass\PDuration;
use Nadybot\Core\ParamClass\PWord;
use Nadybot\Modules\RELAY_MODULE\RelayController;
use Nadybot\Modules\RELAY_MODULE\RelayLayer;
use stdClass;

/**
 * @author Tyrence (RK2)
 * Commands this class contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "usage",
		accessLevel: "guild",
		description: "Shows usage stats",
		help: "usage.txt",
		defaultStatus: 1
	)
]
class UsageController {
	public const DB_TABLE = "usage_<myname>";
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");

		$this->settingManager->add(
			module: $this->moduleName,
			name: "record_usage_stats",
			description: "Record usage stats",
			mode: "edit",
			type: "options",
			value: "1",
			options: "true;false",
			intoptions: "1;0"
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'botid',
			description: 'Botid',
			mode: 'noedit',
			type: 'text',
			value: ''
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'last_submitted_stats',
			description: 'last_submitted_stats',
			mode: 'noedit',
			type: 'text',
			value: '0'
		);
	}

	#[NCA\HandlesCommand("usage")]
	public function usagePlayerCommand(
		CmdContext $context,
		#[NCA\Str("player")] string $action,
		PCharacter $player,
		?PDuration $duration
	): void {
		$time = 604800;
		if (isset($duration)) {
			$time = $duration->toSecs();
			if ($time === 0) {
				$msg = "Please enter a valid time.";
				$context->reply($msg);
				return;
			}
		}

		$timeString = $this->util->unixtimeToReadable($time);
		$time = time() - $time;

		$query = $this->db->table(self::DB_TABLE)
			->where("sender", $player())
			->where("dt", ">", $time)
			->groupBy("command")
			->select("command");
		$query->orderByRaw($query->colFunc("COUNT", "command")->getValue())
			->selectRaw($query->colFunc("COUNT", "command", "count")->getValue());
		$data = $query->asObj();
		$count = $data->count();

		if ($count > 0) {
			$blob = '';
			foreach ($data as $row) {
				$blob .= $this->text->alignNumber($row->count, 3) . " <highlight>{$row->command}<end>\n";
			}

			$msg = $this->text->makeBlob("Usage for $player - $timeString ($count)", $blob);
		} else {
			$msg = "No usage statistics found for <highlight>{$player}<end>.";
		}
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("usage")]
	public function usageCmdCommand(
		CmdContext $context,
		#[NCA\Str("cmd")] string $action,
		PWord $cmd,
		?PDuration $duration
	): void {
		$time = 604800;
		if (isset($duration)) {
			$time = $duration->toSecs();
			if ($time === 0) {
				$msg = "Please enter a valid time.";
				$context->reply($msg);
				return;
			}
		}

		$timeString = $this->util->unixtimeToReadable($time);
		$time = time() - $time;

		$cmd = strtolower($cmd());

		$query = $this->db->table(self::DB_TABLE)
			->where("command", $cmd)
			->where("dt", ">", $time)
			->groupBy("sender");
		$query->orderByColFunc("COUNT", "sender", "desc")
			->select("sender", $query->colFunc("COUNT", "command", "count"));
		$data = $query->asObj()->toArray();
		$count = count($data);

		if ($count > 0) {
			$blob = '';
			foreach ($data as $row) {
				$blob .= $this->text->alignNumber($row->count, 3) . " <highlight>{$row->sender}<end>\n";
			}

			$msg = $this->text->makeBlob("Usage for $cmd - $timeString ($count)", $blob);
		} else {
			$msg = "No usage statistics found for <highlight>{$cmd}<end>.";
		}
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("usage")]
	public function usageInfoCommand(CmdContext $context, #[NCA\Str("info")] string $action): void {
		$info = $this->getUsageInfo(time() - 7*24*3600, time());
		$blob = json_encode(
			$info,
			JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES
		);
		$msg = $this->text->makeBlob("Collected usage info", $blob);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("usage")]
	public function usageCommand(CmdContext $context, ?PDuration $duration): void {
		$time = 604800;
		if (isset($duration)) {
			$time = $duration->toSecs();
			if ($time === 0) {
				$msg = "Please enter a valid time.";
				$context->reply($msg);
				return;
			}
		}

		$timeString = $this->util->unixtimeToReadable($time);
		$time = time() - $time;
		$limit = 25;

		// channel usage
		$query = $this->db->table(self::DB_TABLE)
			->where("dt", ">", $time)
			->groupBy("type")
			->orderBy("type")
			->select("type");
		$query->selectRaw($query->colFunc("COUNT", "type", "cnt")->getValue());
		$data = $query->asObj()->toArray();

		$blob = "<header2>Channel Usage<end>\n";
		foreach ($data as $row) {
			if ($row->type === "msg") {
				$blob .= "<tab>Number of commands executed in tells: <highlight>$row->cnt<end>\n";
			} elseif ($row->type === "priv") {
				$blob .= "<tab>Number of commands executed in private channel: <highlight>$row->cnt<end>\n";
			} elseif ($row->type === "guild") {
				$blob .= "<tab>Number of commands executed in guild channel: <highlight>$row->cnt<end>\n";
			}
		}
		$blob .= "\n";

		// most used commands
		$query = $this->db->table(self::DB_TABLE)
			->where("dt", ">", $time)
			->groupBy("command")
			->orderByColFunc("COUNT", "command", "desc")
			->limit($limit)
			->select("command");
		$query->selectRaw($query->colFunc("COUNT", "command", "count")->getValue());
		$data = $query->asObj()->toArray();

		$blob .= "<header2>$limit Most Used Commands<end>\n";
		foreach ($data as $row) {
			$commandLink = $this->text->makeChatcmd($row->command, "/tell <myname> usage cmd $row->command");
			$blob .= "<tab>" . $this->text->alignNumber($row->count, 3).
				" $commandLink\n";
		}

		// users who have used the most commands
		$query = $this->db->table(self::DB_TABLE)
			->where("dt", ">", $time)
			->groupBy("sender")
			->orderByColFunc("COUNT", "sender", "desc")
			->limit($limit)
			->select("sender");
		$query->selectRaw($query->colFunc("COUNT", "sender", "count")->getValue());
		$data = $query->asObj()->toArray();

		$blob .= "\n<header2>$limit Most Active Users<end>\n";
		foreach ($data as $row) {
			$senderLink = $this->text->makeChatcmd($row->sender, "/tell <myname> usage player $row->sender");
			$blob .= "<tab>" . $this->text->alignNumber($row->count, 3).
				" $senderLink\n";
		}

		$msg = $this->text->makeBlob("Usage Statistics - $timeString", $blob);
		$context->reply($msg);
	}

	/**
	 * Record the use of a command $cmd by player $sender
	 * @throws SQLException
	 */
	public function record(string $type, string $cmd, string $sender, ?string $handler): void {
		// don't record stats for !grc command or command aliases
		if ($cmd === 'grc' || "CommandAlias.process" === $handler) {
			return;
		}

		$this->db->table(self::DB_TABLE)
			->insert([
				"type" => $type,
				"command" => $cmd,
				"sender" => $sender,
				"dt" => time(),
			]);
	}

	public function getUsageInfo(int $lastSubmittedStats, int $now, bool $debug=false): UsageStats {
		global $version;

		$botid = $this->settingManager->getString('botid')??"";
		if ($botid === '') {
			$botid = $this->util->genRandomString(20);
			$this->settingManager->save('botid', $botid);
		}

		$query = $this->db->table(self::DB_TABLE)
			->where("dt", ">=", $lastSubmittedStats)
			->where("dt", "<", time())
			->groupBy("command")
			->select("command");
		$query->selectRaw($query->rawFunc("COUNT", "*", "count")->getValue());
		$commands = $query->asObj()->reduce(function(stdClass $carry, object $entry) {
			$carry->{$entry->command} = $entry->count;
			return $carry;
		}, new stdClass());

		$settings = new SettingsUsageStats();
		$settings->dimension               = $this->config->dimension;
		$settings->is_guild_bot            = strlen($this->config->orgName) > 0;
		$settings->guildsize               = $this->getGuildSizeClass(count($this->chatBot->guildmembers));
		$settings->using_chat_proxy        = (bool)$this->config->useProxy;
		$settings->db_type                 = $this->db->getType();
		$settings->bot_version             = $version;
		$settings->using_git               = @file_exists(BotRunner::getBasedir() . "/.git");
		$settings->os                      = BotRunner::isWindows() ? 'Windows' : php_uname("s");
		$settings->symbol                  = $this->settingManager->getString('symbol')??"!";
		$settings->num_relays              = $this->db->table(RelayController::DB_TABLE)->count();
		$settings->relay_protocols         = $this->db->table(RelayController::DB_TABLE_LAYER)
			->orderBy("relay_id")->orderByDesc("id")->asObj(RelayLayer::class)
			->groupBy("relay_id")
			->map(function(Collection $group): string {
				return $group->first()->layer;
			})->flatten()->unique()->toArray();
		$settings->first_and_last_alt_only = $this->settingManager->getBool('first_and_last_alt_only')??false;
		$settings->aodb_db_version         = $this->settingManager->getString('aodb_db_version')??"unknown";
		$settings->max_blob_size           = $this->settingManager->getInt('max_blob_size')??0;
		$settings->online_show_org_guild   = $this->settingManager->getInt('online_show_org_guild')??-1;
		$settings->online_show_org_priv    = $this->settingManager->getInt('online_show_org_priv')??-1;
		$settings->online_admin            = $this->settingManager->getBool('online_admin')??false;
		$settings->tower_attack_spam       = $this->settingManager->getInt('tower_attack_spam')??-1;
		$settings->http_server_enable      = $this->eventManager->getKeyForCronEvent(60, "httpservercontroller.startHTTPServer") !== null;

		$obj = new UsageStats();
		$obj->id       = sha1($botid . $this->chatBot->char->name . $this->config->dimension);
		$obj->version  = 2;
		$obj->debug    = $debug;
		$obj->commands = $commands;
		$obj->settings = $settings;

		return $obj;
	}

	public function getGuildSizeClass(int $size): string {
		$guildClass = "class7";
		if ($size === 0) {
			$guildClass = "class0";
		} elseif ($size < 10) {
			$guildClass = "class1";
		} elseif ($size < 30) {
			$guildClass = "class2";
		} elseif ($size < 150) {
			$guildClass = "class3";
		} elseif ($size < 300) {
			$guildClass = "class4";
		} elseif ($size < 650) {
			$guildClass = "class5";
		} elseif ($size < 1000) {
			$guildClass = "class6";
		} else {
			$guildClass = "class7";
		}
		return $guildClass;
	}

	#[
		NCA\NewsTile(
			name: "popular-commands",
			description: "A player's 4 most used commands in the last 7 days",
			example:
				"<header2>Popular commands<end>\n".
				"<tab>hot\n".
				"<tab>startpage\n".
				"<tab>config\n".
				"<tab>time"
		)
	]
	public function usageNewsTile(string $sender, callable $callback): void {
		$data = $this->db->table(self::DB_TABLE)
			->where("sender", $sender)
			->where("dt", ">", time() - 7*24*3600)
			->groupBy("command")
			->orderByColFunc("COUNT", "command", "desc")
			->addSelect("command")
			->limit(4)
			->asObj();
		if ($data->isEmpty()) {
			$callback(null);
			return;
		}
		$blob = "<header2>Popular commands<end>\n";
		foreach ($data as $cmdSpec) {
			$blob .= "<tab>{$cmdSpec->command}\n";
		}
		$callback($blob);
	}
}
