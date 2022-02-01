<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use DateTime;
use Exception;
use Throwable;
use Nadybot\Core\{
	Attributes as NCA,
	BotRunner,
	CmdContext,
	DB,
	DBSchema\Setting,
	Http,
	HttpResponse,
	ModuleInstance,
	SettingManager,
};
use Safe\Exceptions\FilesystemException;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "updatecsv",
		accessLevel: "admin",
		description: "Shows a list of all csv files",
	)
]
class UpdateCSVFilesController extends ModuleInstance {
	#[NCA\Inject]
	public Http $http;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public SettingManager $settingManager;

	public function getGitHash(string $file): ?string {
		$baseDir = BotRunner::getBasedir();
		$descriptors = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];

		$pid = proc_open("git hash-object " . escapeshellarg($file), $descriptors, $pipes, $baseDir);
		if ($pid === false) {
			return null;
		}
		\Safe\fclose($pipes[0]);
		$gitHash = trim(\Safe\stream_get_contents($pipes[1]));
		\Safe\fclose($pipes[1]);
		\Safe\fclose($pipes[2]);
		return $gitHash;
	}

	/** Update all your CSV files and thus static database content */
	#[NCA\HandlesCommand("updatecsv")]
	public function updateCsvCommand(CmdContext $context): void {
		$checkCmd = BotRunner::isWindows() ? "where" : "command -v";
		/** @psalm-suppress ForbiddenCode */
		$gitPath = \Safe\shell_exec("{$checkCmd} git");
		$hasGit = is_string($gitPath) && is_executable(rtrim($gitPath));
		if (!$hasGit) {
			$context->reply(
				"In order to check if any files can be updated, you need ".
				"to have git installed and in your path."
			);
			return;
		}
		$this->http->get('https://api.github.com/repos/Nadybot/nadybot/git/trees/unstable')
			->withQueryParams(["recursive" => 1])
			->withTimeout(60)
			->withHeader("Accept", "application/vnd.github.v3+json")
			->withCallback([$this, "updateCSVFiles"], $context);
	}

	public function updateCSVFiles(HttpResponse $response, CmdContext $context): void {
		if ($response->headers["status-code"] !== "200") {
			$error = $response->error ?: $response->body ?? 'null';
			try {
				$error = \Safe\json_decode($error, false, 512, JSON_THROW_ON_ERROR);
				$error = $error->message;
			} catch (Throwable $e) {
				// Ignore it if not json
			}
			$context->reply("Error downloading the file list: {$error}");
			return;
		}
		try {
			if (!isset($response->body)) {
				throw new Exception();
			}
			$data = \Safe\json_decode($response->body, false, 512, JSON_THROW_ON_ERROR);
			if (!is_object($data) || !isset($data->tree)) {
				throw new Exception();
			}
		} catch (Throwable $e) {
			$context->reply("Invalid data received from GitHub");
			return;
		}

		/** @var array<string,mixed> */
		$updates = [];
		$todo = 0;
		foreach ($data->tree as $file) {
			if (!preg_match("/\.csv$/", $file->path)) {
				continue;
			}
			$localHash = $this->getGitHash($file->path);
			if ($localHash === $file->sha) {
				continue;
			}
			$updates[$file->path] = null;
			$todo++;
			$this->checkIfCanUpdateCsvFile(
				/** @param string|bool $result */
				function(string $path, $result) use (&$updates, &$todo, $context): void {
					/** @var array<string,mixed> $updates */
					$updates[$path] = $result;
					$todo--;
					if ($todo > 0) {
						return;
					}
					$msgs = [];
					foreach ($updates as $file => $result) {
						if ($result === false) {
							continue;
						}
						if ($result === true) {
							$msgs []= basename($file) . " was updated.";
						} elseif (is_string($result)) {
							$msgs []= $result;
						}
					}
					if (count($msgs)) {
						$context->reply(join("\n", $msgs));
					} else {
						$context->reply("Your database is already up-to-date.");
					}
				},
				$file->path
			);
		}
		if (count($updates) === 0) {
			$context->reply("No updates available right now.");
		}
	}

	/**
	 * @psalm-param callable(string,string|bool):void $callback
	 */
	protected function checkIfCanUpdateCsvFile(callable $callback, string $file): void {
		$this->http->get("https://api.github.com/repos/Nadybot/Nadybot/commits")
			->withQueryParams([
				"path" => $file,
				"page" => 1,
				"per_page" => 1,
			])
			->withTimeout(60)
			->withCallback([$this, "checkDateAndUpdateCsvFile"], $callback, $file);
	}

	/**
	 * @psalm-param callable(string,string|bool):void $callback
	 */
	public function checkDateAndUpdateCsvFile(HttpResponse $response, callable $callback, string $file): void {
		if ($response->headers["status-code"] !== "200") {
			$callback($file, "Could not request last commit date for {$file}.");
			return;
		}
		try {
			if (!isset($response->body)) {
				throw new Exception();
			}
			$data = \Safe\json_decode($response->body, false, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$callback($file, "Error decoding the JSON data from GitHub for {$file}.");
			return;
		}
		$gitModified = \Safe\DateTime::createFromFormat(
			DateTime::ISO8601,
			$data[0]->commit->committer->date
		);
		$localModified = filemtime(BotRunner::getBasedir() . "/" . $file);
		if ($localModified === false || $localModified >= $gitModified->getTimestamp()) {
			$callback($file, false);
			return;
		}
		$this->http->get("https://raw.githubusercontent.com/Nadybot/Nadybot/unstable/{$file}")
			->withTimeout(60)
			->withHeader("Range", "bytes=0-1023")
			->withCallback([$this, "checkAndUpdateCsvFile"], $callback, $file, $gitModified);
	}

	/**
	 * @psalm-param callable(string,string|bool):void $callback
	 */
	public function checkAndUpdateCsvFile(HttpResponse $response, callable $callback, string $file, DateTime $gitModified): void {
		if ($response->headers["status-code"] !== "206") {
			$callback($file, "Could not get the header of {$file} from GitHub.");
			return;
		}
		$fileBase = basename($file, '.csv');
		$settingName = strtolower("{$fileBase}_db_version");
		if (!$this->settingManager->exists($settingName)) {
			$callback($file, false);
			return;
		}
		/** @var ?Setting */
		$setting = $this->db->table(SettingManager::DB_TABLE)
			->where("name", $settingName)
			->asObj(Setting::class)
			->first();
		if (!isset($setting)) {
			$callback($file, false);
			return;
		}
		if (preg_match("/^#\s*Requires:\s*(.+)$/m", $response->body??"", $matches)) {
			if (!$this->db->hasAppliedMigration($setting->module??"Core", trim($matches[1]))) {
				$callback(
					$file,
					"The new version for {$file} cannot be applied, because you require ".
					"an update to your SQL schema first."
				);
				return;
			}
		}
		if (preg_match("/^\d+$/", $setting->value??"")) {
			if ((int)$setting->value >= $gitModified->getTimestamp()) {
				$callback($file, false);
				return;
			}
		}
		$this->http->get("https://raw.githubusercontent.com/Nadybot/Nadybot/unstable/{$file}")
			->withTimeout(60)
			->withCallback([$this, "updateCsvFile"], $callback, $setting->module, $file, $gitModified);
	}

	/**
	 * @psalm-param callable(string,string|bool):void $callback
	 */
	public function updateCsvFile(HttpResponse $response, callable $callback, string $module, string $file, DateTime $gitModified): void {
		if ($response->headers["status-code"] !== "200" || !isset($response->body)) {
			$callback($file, "Couldn't download {$file} from GitHub.");
			return;
		}
		try {
			$tmpFile = \Safe\tempnam(sys_get_temp_dir(), $file);
			\Safe\file_put_contents($tmpFile, $response->body);
			\Safe\touch($tmpFile, $gitModified->getTimestamp());
			$this->db->beginTransaction();
			$this->db->loadCSVFile($module, $tmpFile);
			$this->db->commit();
			$callback($file, true);
			return;
		} catch (FilesystemException) {
		} catch (Throwable $e) {
			$this->db->rollback();
			$callback($file, "There was an SQL error loading the CSV file {$file}, please check your logs.");
		} finally {
			if (isset($tmpFile)) {
				\Safe\unlink($tmpFile);
			}
		}
		$callback($file, "Unable to save {$file} into a temporary file for updating.");
	}
}
