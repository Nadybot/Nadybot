<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	CacheManager,
	CmdContext,
	ConfigFile,
	Nadybot,
	ParamClass\PFilename,
	ParamClass\PRemove,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "cache",
		accessLevel: "superadmin",
		description: "Manage cached files",
		help: "cache.txt"
	)
]
class CacheController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public CacheManager $cacheManager;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\HandlesCommand("cache")]
	public function cacheCommand(CmdContext $context): void {
		$blob = '';
		foreach ($this->cacheManager->getGroups() as $group) {
			$blob .= $this->text->makeChatcmd($group, "/tell <myname> cache browse $group") . "\n";
		}
		$msg = $this->text->makeBlob("Cache Groups", $blob);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("cache")]
	public function cacheBrowseCommand(CmdContext $context, #[NCA\Str("browse")] string $action, #[NCA\Regexp("[a-z0-9_-]+")] string $group): void {
		$path = $this->config->cacheFolder . $group;

		$blob = '';
		foreach ($this->cacheManager->getFilesInGroup($group) as $file) {
			$fileInfo = stat($path . "/" . $file);
			$blob .= "<highlight>$file<end>  " . $this->util->bytesConvert($fileInfo['size']) . " - Last modified " . $this->util->date($fileInfo['mtime']);
			$blob .= "  [" . $this->text->makeChatcmd("View", "/tell <myname> cache view $group $file") . "]";
			$blob .= "  [" . $this->text->makeChatcmd("Delete", "/tell <myname> cache rem $group $file") . "]\n";
		}
		$msg = $this->text->makeBlob("Cache Group: $group", $blob);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("cache")]
	public function cacheRemCommand(CmdContext $context, PRemove $action, #[NCA\Regexp("[a-z0-9_-]+")] string $group, PFilename $file): void {
		$file = $file();

		if ($this->cacheManager->cacheExists($group, $file)) {
			$this->cacheManager->remove($group, $file);
			$msg = "Cache file <highlight>$file<end> in cache group <highlight>$group<end> has been deleted.";
		} else {
			$msg = "Could not find file <highlight>$file<end> in cache group <highlight>$group<end>.";
		}
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("cache")]
	public function cacheViewCommand(CmdContext $context, #[NCA\Str("view")] string $action, #[NCA\Regexp("[a-z0-9_-]+")] string $group, PFilename $file): void {
		$file = $file();

		if ($this->cacheManager->cacheExists($group, $file)) {
			$contents = $this->cacheManager->retrieve($group, $file)??'null';
			if (preg_match("/\.json$/", $file)) {
				$contents = json_encode(json_decode($contents), JSON_PRETTY_PRINT);
			}
			$msg = $this->text->makeBlob("Cache File: $group $file", htmlspecialchars($contents));
		} else {
			$msg = "Could not find file <highlight>$file<end> in cache group <highlight>$group<end>.";
		}
		$context->reply($msg);
	}
}
