<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use function Amp\async;
use Amp\Http\Client\{HttpClientBuilder, Request, Response};
use DateTime;
use ErrorException;
use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	BotRunner,
	CmdContext,
	Config\BotConfig,
	DB,
	EventManager,
	LoggerWrapper,
	MessageEmitter,
	MessageHub,
	ModuleInstance,
	Nadybot,
	Routing\Source,
	SettingManager,
	UserException,
};
use Safe\Exceptions\FilesystemException;

use Throwable;

use ZipArchive;

#[
	NCA\DefineCommand(
		command: "webui",
		accessLevel: "mod",
		description: "Install or upgrade the NadyUI",
	),
	NCA\Instance,
	NCA\HasMigrations
]
class WebUiController extends ModuleInstance implements MessageEmitter {
	#[NCA\Inject]
	public HttpClientBuilder $builder;

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
	public BotConfig $config;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** The currently installed NadyUI version */
	#[NCA\Setting\Timestamp(mode: 'noedit')]
	public int $nadyuiVersion = 0;

	#[NCA\Setup]
	public function setup(): void {
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
			options: $uiBranches,
		);
		$this->messageHub->registerMessageEmitter($this);
	}

	public function getChannelName(): string {
		return Source::SYSTEM . '(webui)';
	}

	#[NCA\SettingChangeHandler('nadyui_channel')]
	public function changeNadyUiChannel(string $setting, string $old, string $new): void {
		if (empty($new) || $new === "off") {
			return;
		}
		async($this->updateWebUI(...));
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

		try {
			[$response, $artifact] = $this->downloadBuildArtifact($channel);
			$msg = $this->installArtifact($response, $artifact);
			$sendto->reply($msg);
		} catch (UserException $e) {
		} catch (Throwable $e) {
			$this->logger->warning("Error downloading/installing new WebUI: " . $e->getMessage());
		}
	}

	/** Remove all files from the NadyUI installation (if any) and reset the version in the DB */
	public function uninstallNadyUi(bool $updateDB=false): bool {
		if ($updateDB && $this->settingManager->exists("nadyui_version")) {
			$this->settingManager->save("nadyui_version", "0");
		}
		$path = $this->config->paths->html;
		return (realpath("{$path}/css") ? $this->recursiveRemoveDirectory(\Safe\realpath("{$path}/css")) : true)
			&& (realpath("{$path}/img") ? $this->recursiveRemoveDirectory(\Safe\realpath("{$path}/img")) : true)
			&& (realpath("{$path}/js") ? $this->recursiveRemoveDirectory(\Safe\realpath("{$path}/js")) : true)
			&& (realpath("{$path}/index.html") ? unlink(\Safe\realpath("{$path}/index.html")) : true)
			&& (realpath("{$path}/favicon.ico") ? unlink(\Safe\realpath("{$path}/favicon.ico")) : true);
	}

	/** Delete a directory and all its subdirectories */
	public function recursiveRemoveDirectory(string $directory): bool {
		foreach (\Safe\glob("{$directory}/*") as $file) {
			if (is_dir($file)) {
				$this->recursiveRemoveDirectory($file);
			} else {
				try {
					\Safe\unlink($file);
				} catch (FilesystemException) {
					return false;
				}
			}
		}
		if (rmdir($directory) === false) {
			return false;
		}
		return true;
	}

	/** Manually install the WebUI "NadyUI" */
	#[NCA\HandlesCommand("webui")]
	#[NCA\Help\Epilogue(
		"You should only use these commands for debugging. The regular way to install\n".
		"the WebUI is via the ".
		"<a href='chatcmd:///tell <myname> settings change nadyui_channel'>nadyui_channel</a> setting."
	)]
	#[NCA\Help\Example("<symbol>webui install stable")]
	#[NCA\Help\Example("<symbol>webui install unstable")]
	public function webUiInstallCommand(
		CmdContext $context,
		#[NCA\Str("install")]
		string $action,
		string $channel
	): void {
		try {
			[$response, $artifact] = $this->downloadBuildArtifact($channel);
			$msg = $this->installArtifact($response, $artifact);
		} catch (UserException $e) {
			$msg = $e->getMessage();
		}
		$context->reply($msg);
	}

	/** Completely remove the WebUI installation */
	#[NCA\HandlesCommand("webui")]
	public function webUiUninstallCommand(CmdContext $context, #[NCA\Str("uninstall")] string $action): void {
		$msg = "There was an error removing the old files from NadyUI, please clean up manually.";
		if ($this->uninstallNadyUi(true)) {
			$msg = "NadyUI successfully uninstalled.";
		}
		$context->reply($msg);
	}

	protected function createAdminLogin(): void {
		if (!$this->settingManager->getBool('webserver')) {
			return;
		}
		if ($this->settingManager->getString('webserver_auth') !== WebserverController::AUTH_BASIC) {
			return;
		}
		$schema = "http"; /* $this->settingManager->getBool('webserver_tls') ? "https" : "http"; */
		$port = $this->settingManager->getInt('webserver_port');
		if (empty($this->config->general->superAdmins)) {
			return;
		}
		$superUser = $this->config->general->superAdmins[0];
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

	/** @return array{Response,string} */
	private function downloadBuildArtifact(string $channel): array {
		if (!extension_loaded("zip")) {
			$this->eventManager->deactivateIfActivated($this, "updateWebUI");
			throw new UserException(
				"In order to install or update NadyUI from within the bot, " .
					"you must have the PHP Zip extension installed."
			);
		}
		$uri = sprintf(
			"https://github.com/Nadybot/nadyui/releases/download/ci-%s/nadyui.zip",
			$channel
		);
		$client = $this->builder->build();

		$response = $client->request(new Request($uri));
		if ($response->getStatus() === 404) {
			throw new UserException("No release found for <highlight>{$channel}<end>.");
		} elseif ($response->getStatus() !== 200) {
			throw new UserException("Error retrieving {$uri}, code " . $response->getStatus());
		}
		$body = $response->getBody()->buffer();
		if ($body === '') {
			throw new UserException("Empty response received from {$uri}");
		}
		return [$response, $body];
	}

	/** Install the NadyUI version that was returned into ./html */
	private function installArtifact(Response $response, string $artifact): string {
		$currentVersion = $this->nadyuiVersion;
		$lastModifiedHeader = $response->getHeader("last-modified");
		$lastModified = false;
		if (isset($lastModifiedHeader)) {
			$lastModified = DateTime::createFromFormat(DateTime::RFC7231, $lastModifiedHeader);
		}
		if ($lastModified === false) {
			$this->logger->warning("Cannot parse last modification date, assuming now");
			$lastModified = new DateTime();
		}
		$dlVersion = $lastModified->getTimestamp();
		if ($dlVersion === $currentVersion) {
			$this->logger->notice("Already using the latest version of NadyUI");
			if ($this->chatBot->getUptime() < 120) {
				$this->createAdminLogin();
			}
			return "You are already using the latest version (" . $lastModified->format("Y-m-d H:i:s") . ").";
		}
		try {
			$this->uninstallNadyUi();
			$this->installNewRelease($artifact);
		} catch (Exception $e) {
			$msg = $e->getMessage();
			$this->logger->error($msg);
			throw $e;
		}
		if ($currentVersion === 0) {
			$action = "<green>installed<end> with version";
			$this->createAdminLogin();
		} elseif ($dlVersion > $currentVersion) {
			$action = "<green>upgraded<end> to version";
		} else {
			$action = "<green>downgraded<end> to version";
		}
		$this->settingManager->save("nadyui_version", (string)$dlVersion);
		$msg = "Webfrontend NadyUI {$action} <highlight>" . $lastModified->format("Y-m-d H:i:s") . "<end>";
		return $msg;
	}

	/**
	 * Install the new NadyUI release form the response object into ./html and clean up before
	 *
	 * @throws Exception on installation error
	 */
	private function installNewRelease(string $body): void {
		try {
			$oldMask = umask(0027);
			$file = \Safe\tmpfile();
			$archiveName = stream_get_meta_data($file)['uri'];
			if ($body === '') {
				throw new Exception("Cannot write to temp file {$archiveName}.");
			}
			\Safe\fwrite($file, $body);
			$extractor = new ZipArchive();
			$openResult = $extractor->open($archiveName);
			if ($openResult !== true) {
				throw new Exception("Error opening {$archiveName}. Code {$openResult}.");
			}
			$path = \Safe\realpath($this->config->paths->html);
			error_clear_last();
			if ($extractor->extractTo($path) === false) {
				$lastError = error_get_last();
				if (isset($lastError)) {
					throw new ErrorException("Error extracting {$archiveName}: " . $lastError['message'] . ".", 0, $lastError['type']);
				}
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
}
