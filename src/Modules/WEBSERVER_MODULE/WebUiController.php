<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use DateTime;
use Exception;
use Nadybot\Core\CommandReply;
use Nadybot\Core\GuildChannelCommandReply;
use Nadybot\Core\Http;
use Nadybot\Core\HttpResponse;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Nadybot;
use Nadybot\Core\PrivateChannelCommandReply;
use Nadybot\Core\SettingManager;
use Nadybot\Core\SQLException;
use Nadybot\Core\Timer;
use Throwable;
use ZipArchive;

/**
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'webui',
 *		accessLevel = 'mod',
 *		description = 'Install or upgrade the NadyUI',
 *		help        = 'webui.txt'
 *	)
 *
 * @Instance
 */
class WebUiController {
	public string $moduleName;

	/** @Inject */
	public Http $http;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Timer $timer;

	/** @Logger */
	public LoggerWrapper $logger;
	
	/** @Setup */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			"nadyui_channel",
			"Which NadyUI webfrontend version to subscribe to",
			"edit",
			"options",
			"off",
			"off;stable;unstable"
		);
		$this->settingManager->registerChangeListener(
			"nadyui_channel",
			[$this, "changeNadyUiChannel"]
		);
	}

	public function changeNadyUiChannel(string $setting, string $old, string $new): void {
		if (empty($new) || $new === "off") {
			return;
		}
		$this->timer->callLater(0, [$this, "updateWebUI"]);
	}
	
	/**
	 * @Event("timer(24hrs)")
	 * @Description("Automatically upgrade NadyUI")
	 */
	public function updateWebUI(): void {
		$channel = $this->settingManager->getString('nadyui_channel');
		if (empty($channel) || $channel === 'off') {
			return;
		}
		if (empty($this->chatBot->vars["my_guild"])) {
			$sendto = new PrivateChannelCommandReply(
				$this->chatBot,
				$this->chatBot->setting->default_private_channel
			);
		} else {
			$sendto = new GuildChannelCommandReply($this->chatBot);
		}
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
			$this->logger->log('ERROR', $msg);
			return null;
		}
		return $response;
	}

	public function processNadyUIRelease(string $channel, ?CommandReply $sendto, callable $callback): void {
		$uri = sprintf(
			"https://github.com/Nadybot/nadyui/releases/download/ci-%s/nadyui.zip",
			$channel
		);
		$this->http->get($uri)
			->withHeader("User-Agent", "Nadybot")
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
				->withHeader("User-Agent", "Nadybot")
				->withCallback([$this, "processArtifact"], $sendto, $callback);
			return;
		}
		$settingName = "nadyui_version";
		if (!$this->settingManager->exists($settingName)) {
			$this->settingManager->add($this->moduleName, $settingName, $settingName, 'noedit', 'number', "0");
		}
		$currentVersion = $this->settingManager->getInt($settingName) ?? 0;
		$lastModified = DateTime::createFromFormat(DateTime::RFC7231, $response->headers["last-modified"]);
		if ($lastModified === null) {
			$msg = "Cannot parse last modification date, assuming now";
			if ($sendto) {
				$sendto->reply($msg);
			}
			$this->logger->log('WARNING', $msg);
			$lastModified = new DateTime();
		}
		$dlVersion = $lastModified->getTimestamp();
		if ($dlVersion === $currentVersion) {
			if ($sendto) {
				$sendto->reply("You are already using the latest version (" . $lastModified->format("Y-m-d H:i:s") . ").");
			} else {
				$this->logger->log("INFO", "Already using the latest version of NadyUI");
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
			$this->logger->log('ERROR', $msg);
		}
		if ($currentVersion === 0) {
			$action = "<green>installed<end> with version";
		} elseif ($dlVersion > $currentVersion) {
			$action = "<green>upgraded<end> to version";
		} elseif ($dlVersion < $currentVersion) {
			$action = "<green>downgraded<end> to version";
		}
		$this->settingManager->save($settingName, (string)$dlVersion);
		$msg = "NadyUI {$action} <highlight>" . $lastModified->format("Y-m-d H:i:s") . "<end>";
		$sendto->reply($msg);
		$callback();
	}

	/**
	 * Remove all files from the NadyUI installation (if any) and reset teh version in the DB
	 */
	public function uninstallNadyUi(bool $updateDB=false): bool {
		if ($updateDB && $this->settingManager->exists("nadyui_version")) {
			$this->settingManager->save("nadyui_version", "0");
		}
		return (realpath("./html/css") ? $this->recursiveRemoveDirectory(realpath("./html/css")) : true)
			&& (realpath("./html/img") ? $this->recursiveRemoveDirectory(realpath("./html/img")) : true)
			&& (realpath("./html/js")  ? $this->recursiveRemoveDirectory(realpath("./html/js")) : true)
			&& (realpath("./html/index.html") ? unlink(realpath("./html/index.html")) : true)
			&& (realpath("./html/favicon.ico") ? unlink(realpath("./html/favicon.ico")) : true);
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
			if (fwrite($file, $response->body) === false) {
				throw new Exception("Cannot write to temp file {$archiveName}.");
			}
			$extractor = new ZipArchive();
			$openResult = $extractor->open($archiveName);
			if ($openResult !== true) {
				throw new Exception("Error opening {$archiveName}. Code {$openResult}.");
			}
			if ($extractor->extractTo(realpath("./html")) === false) {
				throw new Exception("Error extracting {$archiveName}.");
			}
		} catch (Throwable $e) {
			$msg = "An unexpected error occurred extracting the release: " . $e->getMessage();
			throw new Exception($msg);
		} finally {
			umask($oldMask);
			if (isset($extractor)) {
				@$extractor->close();
			}
			if (isset($file)) {
				@fclose($file);
			}
		}
	}
	
	/**
	 * @HandlesCommand("webui")
	 * @Matches("/^webui install (.+)$/")
	 */
	public function webUiInstallCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->processNadyUIRelease($args[1], $sendto, function() {
		});
	}

	/**
	 * @HandlesCommand("webui")
	 * @Matches("/^webui uninstall$/")
	 */
	public function webUiUninstallCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = "There was an error removig the old files from NadyUI, please clean up manually.";
		if ($this->uninstallNadyUi(true)) {
			$msg = "NadyUI successfully uninstalled.";
		}
		$sendto->reply($msg);
	}
}
