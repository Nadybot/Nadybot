<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\Attributes as NCA;
use DateTime;
use Exception;
use Nadybot\Core\BotRunner;
use Nadybot\Core\CmdContext;
use Nadybot\Core\CommandReply;
use Nadybot\Core\ConfigFile;
use Nadybot\Core\DB;
use Nadybot\Core\EventManager;
use Nadybot\Core\Http;
use Nadybot\Core\HttpResponse;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageEmitter;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SettingManager;
use Nadybot\Core\Timer;
use Throwable;
use ZipArchive;

/**
 * Commands this controller contains:
 */
#[
	NCA\DefineCommand(
		command: "webui",
		accessLevel: "mod",
		description: "Install or upgrade the NadyUI",
		help: "webui.txt"
	),
	NCA\Instance
]
class WebUiController implements MessageEmitter {
	public string $moduleName;

	#[NCA\Inject]
	public Http $http;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public WebserverController $webserverController;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Timer $timer;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
		$uiBranches = ["off", "stable", "unstable"];
		if (preg_match("/@(?<branch>.+)$/", BotRunner::getVersion(), $matches)) {
			if (!in_array($matches['branch'], $uiBranches)) {
				$uiBranches []= $matches['branch'];
			}
		}
		$this->settingManager->add(
			module: $this->moduleName,
			name: "nadyui_channel",
			description: "Which NadyUI webfrontend version to subscribe to",
			mode: "edit",
			type: "options",
			value: "stable",
			options: join(";", $uiBranches)
		);
		$this->settingManager->registerChangeListener(
			"nadyui_channel",
			[$this, "changeNadyUiChannel"]
		);
		$this->messageHub->registerMessageEmitter($this);
	}

	public function getChannelName(): string {
		return Source::SYSTEM . '(webui)';
	}

	public function changeNadyUiChannel(string $setting, string $old, string $new): void {
		if (empty($new) || $new === "off") {
			return;
		}
		$this->timer->callLater(0, [$this, "updateWebUI"]);
	}

	#[NCA\Event(
		name: "timer(24hrs)",
		description: "Automatically upgrade NadyUI",
		defaultStatus: 1
	)]
	public function updateWebUI(): void {
		$channel = $this->settingManager->getString('nadyui_channel');
		if (empty($channel) || $channel === 'off') {
			return;
		}
		$sendto = new WebUIChannel($this->messageHub);
		$sendto->reply("Checking for new NadyUI release...");
		$this->processNadyUIRelease($channel, $sendto, function() {
		});
	}

	protected function getGitHubData(HttpResponse $response, ?CommandReply $sendto): ?HttpResponse {
		$msg = null;
		if ($response->error) {
			$msg = 'Error received from GitHub: ' . trim($response->error);
		} elseif (!isset($response->body)) {
			$msg = 'Empty reply received from GitHub';
		} elseif ((int)$response->headers["status-code"] !== 200) {
			if ((int)$response->headers["status-code"] === 302) {
				return $response;
			}
			if ((int)$response->headers["status-code"] === 404) {
				$msg = "No release found with that name.";
			} else {
				$msg = "Error code {$response->headers['status-code']} received from GitHub: " . trim($response->body);
			}
		}
		if (isset($msg)) {
			if ($sendto) {
				$sendto->reply($msg);
			}
			$this->logger->error($msg);
			return null;
		}
		return $response;
	}

	public function processNadyUIRelease(string $channel, ?CommandReply $sendto, callable $callback): void {
		if (!extension_loaded("zip")) {
			if (isset($sendto)) {
				$sendto->reply(
					"In order to install or update NadyUI from within the bot, " .
						"you must have the PHP Zip extension installed."
				);
			}
			$this->eventManager->deactivateIfActivated($this, "updateWebUI");
			return;
		}
		$uri = sprintf(
			"https://github.com/Nadybot/nadyui/releases/download/ci-%s/nadyui.zip",
			$channel
		);
		$this->http->get($uri)
			->withCallback([$this, "processArtifact"], $sendto, $callback);
	}

	/**
	 * Install the NadyUI version that was returned into ./html
	 */
	public function processArtifact(HttpResponse $response, ?CommandReply $sendto, callable $callback): void {
		$response = $this->getGitHubData($response, $sendto);
		if ($response === null) {
			return;
		}
		if ((int)$response->headers["status-code"] === 302) {
			$this->http->get($response->headers["location"])
				->withCallback([$this, "processArtifact"], $sendto, $callback);
			return;
		}
		$settingName = "nadyui_version";
		if (!$this->settingManager->exists($settingName)) {
			$this->settingManager->add(
				module: $this->moduleName,
				name: $settingName,
				description: $settingName,
				mode: 'noedit',
				type: 'number',
				value: "0"
			);
		}
		$currentVersion = $this->settingManager->getInt($settingName) ?? 0;
		$lastModified = DateTime::createFromFormat(DateTime::RFC7231, $response->headers["last-modified"]);
		if ($lastModified === false) {
			$msg = "Cannot parse last modification date, assuming now";
			if ($sendto) {
				$sendto->reply($msg);
			}
			$this->logger->warning($msg);
			$lastModified = new DateTime();
		}
		$dlVersion = $lastModified->getTimestamp();
		if ($dlVersion === $currentVersion) {
			if ($sendto) {
				$sendto->reply("You are already using the latest version (" . $lastModified->format("Y-m-d H:i:s") . ").");
			} else {
				$this->logger->notice("Already using the latest version of NadyUI");
			}
			if ($this->chatBot->getUptime() < 120) {
				$this->createAdminLogin();
			}
			return;
		}
		try {
			$this->uninstallNadyUi();
			$this->installNewRelease($response);
		} catch (Exception $e) {
			$msg = $e->getMessage();
			if ($sendto) {
				$sendto->reply($msg);
			}
			$this->logger->error($msg);
			return;
		}
		if ($currentVersion === 0) {
			$action = "<green>installed<end> with version";
			$this->createAdminLogin();
		} elseif ($dlVersion > $currentVersion) {
			$action = "<green>upgraded<end> to version";
		} else {
			$action = "<green>downgraded<end> to version";
		}
		$this->settingManager->save($settingName, (string)$dlVersion);
		if (isset($sendto)) {
			$msg = "Webfrontend NadyUI {$action} <highlight>" . $lastModified->format("Y-m-d H:i:s") . "<end>";
			$sendto->reply($msg);
		}
		$callback();
	}

	protected function createAdminLogin(): void {
		if (!$this->settingManager->getBool('webserver')) {
			return;
		}
		if ($this->settingManager->getString('webserver_auth') !== WebserverController::AUTH_BASIC) {
			return;
		}
		$schema = "http"; /*$this->settingManager->getBool('webserver_tls') ? "https" : "http";*/
		$port = $this->settingManager->getInt('webserver_port');
		$superUser = $this->config->superAdmin;
		$uuid = $this->webserverController->authenticate($superUser, 6 * 3600);
		$this->logger->notice(
			">>> You can now configure this bot at {$schema}://127.0.0.1:{$port}/"
		);
		$this->logger->notice(
			">>> Login with username \"{$superUser}\" and password \"{$uuid}\""
		);
		$this->logger->notice(
			">>> Use the " . ($this->settingManager->getString('symbol')??"!").
				"webauth command to create a new password after this expired"
		);
	}

	/**
	 * Remove all files from the NadyUI installation (if any) and reset the version in the DB
	 */
	public function uninstallNadyUi(bool $updateDB=false): bool {
		if ($updateDB && $this->settingManager->exists("nadyui_version")) {
			$this->settingManager->save("nadyui_version", "0");
		}
		$path = $this->config->htmlFolder;
		return (realpath("{$path}/css") ? $this->recursiveRemoveDirectory(realpath("{$path}/css")) : true)
			&& (realpath("{$path}/img") ? $this->recursiveRemoveDirectory(realpath("{$path}/img")) : true)
			&& (realpath("{$path}/js")  ? $this->recursiveRemoveDirectory(realpath("{$path}/js")) : true)
			&& (realpath("{$path}/index.html") ? unlink(realpath("{$path}/index.html")) : true)
			&& (realpath("{$path}/favicon.ico") ? unlink(realpath("{$path}/favicon.ico")) : true);
	}

	/**
	 * Delete a directory and all its subdirectories
	 */
	public function recursiveRemoveDirectory(string $directory): bool {
		foreach (glob("{$directory}/*") as $file) {
			if (is_dir($file)) {
				$this->recursiveRemoveDirectory($file);
			} else {
				if (unlink($file) === false) {
					return false;
				}
			}
		}
		if (rmdir($directory) === false) {
			return false;
		}
		return true;
	}

	/**
	 * Install the new NadyUI release form the response object into ./html and clean up before
	 * @throws Exception on installation error
	 */
	public function installNewRelease(HttpResponse $response): void {
		try {
			$oldMask = umask(0027);
			$file = tmpfile();
			$archiveName = stream_get_meta_data($file)['uri'];
			if (!isset($response->body) || fwrite($file, $response->body) === false) {
				throw new Exception("Cannot write to temp file {$archiveName}.");
			}
			$extractor = new ZipArchive();
			$openResult = $extractor->open($archiveName);
			if ($openResult !== true) {
				throw new Exception("Error opening {$archiveName}. Code {$openResult}.");
			}
			$path = realpath($this->config->htmlFolder);
			if ($path === false || $extractor->extractTo($path) === false) {
				throw new Exception("Error extracting {$archiveName}.");
			}
		} catch (Throwable $e) {
			$msg = "An unexpected error occurred extracting the release: " . $e->getMessage();
			throw new Exception($msg);
		} finally {
			if (isset($oldMask)) {
				umask($oldMask);
			}
			if (isset($extractor)) {
				@$extractor->close();
			}
			if (isset($file)) {
				@fclose($file);
			}
		}
	}

	#[NCA\HandlesCommand("webui")]
	public function webUiInstallCommand(CmdContext $context, #[NCA\Str("install")] string $action, string $channel): void {
		$this->processNadyUIRelease($channel, $context, function() {
		});
	}

	#[NCA\HandlesCommand("webui")]
	public function webUiUninstallCommand(CmdContext $context, #[NCA\Str("uninstall")] string $action): void {
		$msg = "There was an error removig the old files from NadyUI, please clean up manually.";
		if ($this->uninstallNadyUi(true)) {
			$msg = "NadyUI successfully uninstalled.";
		}
		$context->reply($msg);
	}
}
