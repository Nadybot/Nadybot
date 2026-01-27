<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use function Amp\ByteStream\pipe;
use function Amp\File\openFile;
use function Safe\tempnam;

use Amp\File\FilesystemException;
use Amp\Http\Client\{HttpClientBuilder, Request};
use Amp\{CancelledException, TimeoutCancellation};
use ErrorException;
use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	BotRunner,
	CmdContext,
	Config\BotConfig,
	Events\ConnectEvent,
	Filesystem,
	ModuleInstance,
	Safe,
	SettingManager,
	Types\AccessLevel,
};
use Psr\Log\LoggerInterface;
use Throwable;
use ZipArchive;

#[
	NCA\DefineCommand(
		command: 'webui',
		accessLevel: AccessLevel::Mod,
		description: 'Install or upgrade the NadyUI',
	),
	NCA\Instance,
	NCA\HasMigrations
]
class WebUiController extends ModuleInstance {
	public const ARTIFACTS = 'https://artifacts.on.nadybot.org/nadyui/%s.zip';

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private WebserverController $webserverController;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private Filesystem $fs;

	/** Download missing NadyUI */
	#[NCA\HandlesEvent]
	public function onConnect(ConnectEvent $event): void {
		$commit = BotRunner::getCommit();
		$this->logger->debug('Current HEAD commit is {commit}', ['commit' => $commit]);
		$path = $this->config->paths->html;
		if ($this->fs->exists("{$path}/_version")) {
			$installed = trim($this->fs->read("{$path}/_version"));
			$this->logger->info('Installed NadyUI is {commit}', ['commit' => $installed]);
			if ($installed === $commit) {
				$this->logger->info('No WebUI update needed');
				return;
			}
		}
		$this->updateWebUI($commit);
	}

	public function updateWebUI(?string $commit=null): void {
		$commit ??= BotRunner::getCommit();

		try {
			$fileName = $this->downloadBuildArtifact($commit);
			$this->installArtifact($fileName);
		} catch (Throwable $e) {
			$this->logger->warning('Error downloading/installing new WebUI: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
		} finally {
			if (isset($fileName)) {
				$this->fs->deleteFile($fileName);
			}
		}
	}

	/** Remove all files from the NadyUI installation (if any) and reset the version in the DB */
	public function uninstallNadyUi(): bool {
		$path = $this->config->paths->html;

		$success = true;
		foreach (['css', 'img', 'js'] as $subPath) {
			try {
				$fullPath = $this->fs->realPath("{$path}/{$subPath}");
				if (!$this->fs->exists($fullPath)) {
					continue;
				}
			} catch (FilesystemException) {
				continue;
			}
			if (strlen($fullPath)) {
				$success = $success && $this->recursiveRemoveDirectory($fullPath);
			}
		}
		foreach (['index.html', 'favicon.ico', '_version'] as $subPath) {
			try {
				$fullPath = $this->fs->realPath("{$path}/{$subPath}");
				if (!$this->fs->exists($fullPath)) {
					continue;
				}
			} catch (FilesystemException) {
				continue;
			}
			if (strlen($fullPath)) {
				$success = $success && $this->unlink($fullPath);
			}
		}
		return $success;
	}

	/** Delete a directory and all its subdirectories */
	public function recursiveRemoveDirectory(string $directory): bool {
		$files = $this->fs->listFiles($directory);
		foreach ($files as $file) {
			$file = "{$directory}/{$file}";
			if ($this->fs->isDirectory($file)) {
				$this->recursiveRemoveDirectory($file);
			} else {
				try {
					$this->fs->deleteFile($file);
				} catch (FilesystemException) {
					return false;
				}
			}
		}
		try {
			$this->fs->deleteDirectory($directory);
		} catch (FilesystemException) {
			return false;
		}
		return true;
	}

	/** Manually install the WebUI "NadyUI" */
	#[NCA\HandlesCommand('webui')]
	#[NCA\Help\Epilogue(
		"You should only use these commands for debugging. The regular way to install\n".
		'the WebUI is via the '.
		"<a href='chatcmd:///tell <myname> settings change nadyui_channel'>nadyui_channel</a> setting."
	)]
	public function webUiInstallCommand(
		CmdContext $context,
		#[NCA\Parameter\Str('install')] string $action
	): void {
		try {
			$commit = BotRunner::getCommit();
			$fileName = $this->downloadBuildArtifact($commit);
			$this->installArtifact($fileName);
			$msg = "NadyUI {$commit} successfully <green>installed<end>.";
		} catch (Exception $e) {
			$msg = $e->getMessage();
		}
		$context->reply($msg);
	}

	/** Completely remove the WebUI installation */
	#[NCA\HandlesCommand('webui')]
	public function webUiUninstallCommand(
		CmdContext $context,
		#[NCA\Parameter\Str('uninstall')] string $action
	): void {
		$msg = 'There was an error removing the old files from NadyUI, please clean up manually.';
		if ($this->uninstallNadyUi()) {
			$msg = 'NadyUI successfully uninstalled.';
		}
		$context->reply($msg);
	}

	protected function createAdminLogin(): void {
		if (!$this->webserverController->webserver) {
			return;
		}
		if (!count($this->config->general->superAdmins)) {
			return;
		}
		$superUser = $this->config->general->superAdmins[0];
		$this->logger->notice(
			'>>> You can now configure this bot at {schema}://127.0.0.1:{port}/',
			[
				'schema' => 'http',
				'port' => $this->webserverController->webserverPort,
			]
		);
		if ($this->webserverController->webserverAuth === WebserverController::AUTH_BASIC) {
			$uuid = $this->webserverController->authenticate($superUser, 6 * 3_600);
			$this->logger->notice('>>> Login with username "{username}" and password "{password}"', [
				'username' => $superUser,
				'password' => $uuid,
			]);
			$this->logger->notice(
				'>>> Use the {symbol}webauth command to create a new password after '.
				'this expired, or switch to aoauth',
				[
					'symbol' => $this->settingManager->getString('symbol')??'!',
				]
			);
		}
	}

	private function unlink(string $path): bool {
		try {
			$this->fs->deleteFile($path);
		} catch (FilesystemException) {
			return false;
		}
		return true;
	}

	private function downloadBuildArtifact(string $commit): string {
		if (!extension_loaded('zip')) {
			throw new Exception(
				'In order to install or update NadyUI from within the bot, ' .
					'you must have the PHP Zip extension installed.'
			);
		}
		$uri = sprintf(self::ARTIFACTS, $commit);
		$client = $this->builder->build();

		try {
			$response = $client->request(new Request($uri), new TimeoutCancellation(10));
		} catch (CancelledException $e) {
			throw new Exception(
				message: "Downloading the WebUI took too long. Retry manually with '!webui install'",
				previous: $e
			);
		}
		if ($response->getStatus() === 404) {
			throw new Exception("No release found for {$commit}.");
		} elseif ($response->getStatus() !== 200) {
			throw new Exception("Error retrieving {$uri}, code " . $response->getStatus());
		}
		$fileName = tempnam(sys_get_temp_dir(), 'nadyui-');
		$file = openFile($fileName, 'w');
		$bytesRead = pipe($response->getBody(), $file);
		if ($bytesRead === 0) {
			throw new Exception("Unable to read {$uri}, 0 bytes received.");
		}
		return $fileName;
	}

	/** Install the NadyUI version that was returned into ./html */
	private function installArtifact(string $fileName): void {
		try {
			$this->uninstallNadyUi();
			$this->installNewRelease($fileName);
			$this->createAdminLogin();
		} catch (Exception $e) {
			$this->logger->error('{error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			throw $e;
		}
		$this->logger->notice('Webfrontend NadyUI installed.');
	}

	/**
	 * Install the new NadyUI release form the response object into ./html and clean up before
	 *
	 * @throws Exception on installation error
	 */
	private function installNewRelease(string $archiveName): void {
		try {
			$oldMask = umask(0o027);
			$extractor = new ZipArchive();
			$openResult = $extractor->open($archiveName);
			if ($openResult !== true) {
				throw new Exception("Error opening {$archiveName}. Code {$openResult}.");
			}
			$path = $this->fs->realPath($this->config->paths->html);
			error_clear_last();
			if ($extractor->extractTo($path) === false) {
				$lastError = error_get_last();
				if (isset($lastError)) {
					throw new ErrorException("Error extracting {$archiveName}: " . $lastError['message'] . '.', 0, $lastError['type']);
				}
				throw new Exception("Error extracting {$archiveName}.");
			}
		} catch (Throwable $e) {
			$msg = 'An unexpected error occurred extracting the release: ' . $e->getMessage();
			throw new Exception($msg, 0, $e);
		} finally {
			if (isset($oldMask)) {
				umask($oldMask);
			}
			if (isset($extractor)) {
				try {
					/** @var bool */
					$result = Safe::exceptionWrapper($extractor->close(...));
				} catch (ErrorException) {
				}
			}
		}
	}
}
