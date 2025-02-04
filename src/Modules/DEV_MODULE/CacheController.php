<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use function Safe\{json_decode, json_encode};
use Nadybot\Core\Filesystem;

use Nadybot\Core\{
	Attributes as NCA,
	CacheManager,
	CmdContext,
	Config\BotConfig,
	ModuleInstance,
	ParamClass\PRemove,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'cache',
		accessLevel: 'superadmin',
		description: 'Manage cached files',
	)
]
class CacheController extends ModuleInstance {
	#[NCA\Inject]
	private CacheManager $cacheManager;

	#[NCA\Inject]
	private Filesystem $fs;

	#[NCA\Inject]
	private BotConfig $config;

	/** View a list of cache categories */
	#[NCA\HandlesCommand('cache')]
	public function cacheCommand(CmdContext $context): void {
		$blob = '';
		foreach ($this->cacheManager->getGroups() as $group) {
			$blob .= Text::makeChatcmd($group, "/tell <myname> cache browse {$group}") . "\n";
		}
		$msg = Text::makeBlob('Cache Groups', $blob);
		$context->reply($msg);
	}

	/** View a list of files in a cache category */
	#[NCA\HandlesCommand('cache')]
	public function cacheBrowseCommand(
		CmdContext $context,
		#[NCA\Str('browse')] string $action,
		#[NCA\Regexp('[a-z0-9_-]+')] string $group
	): void {
		$path = $this->config->paths->cache . $group;

		$blob = '';
		foreach ($this->cacheManager->getFilesInGroup($group) as $file) {
			$fileInfo = $this->fs->getStatus($path . '/' . $file);
			if (!isset($fileInfo)) {
				continue;
			}
			$blob .= "<highlight>{$file}<end>  " . Util::bytesConvert($fileInfo['size']) . ' - Last modified ' . Util::date($fileInfo['mtime']);
			$blob .= '  [' . Text::makeChatcmd('View', "/tell <myname> cache view {$group} {$file}") . ']';
			$blob .= '  [' . Text::makeChatcmd('Delete', "/tell <myname> cache rem {$group} {$file}") . "]\n";
		}
		$msg = Text::makeBlob("Cache Group: {$group}", $blob);
		$context->reply($msg);
	}

	/** Delete a cache file from a cache category */
	#[NCA\HandlesCommand('cache')]
	public function cacheRemCommand(
		CmdContext $context,
		PRemove $action,
		#[NCA\Regexp('[a-z0-9_-]+')] string $group,
		#[NCA\FilenameStr] string $file
	): void {
		if ($this->cacheManager->cacheExists($group, $file)) {
			$this->cacheManager->remove($group, $file);
			$msg = "Cache file <highlight>{$file}<end> in cache group <highlight>{$group}<end> has been deleted.";
		} else {
			$msg = "Could not find file <highlight>{$file}<end> in cache group <highlight>{$group}<end>.";
		}
		$context->reply($msg);
	}

	/** View the contents of a cache file */
	#[NCA\HandlesCommand('cache')]
	public function cacheViewCommand(
		CmdContext $context,
		#[NCA\Str('view')] string $action,
		#[NCA\Regexp('[a-z0-9_-]+')] string $group,
		#[NCA\FilenameStr] string $file
	): void {
		if ($this->cacheManager->cacheExists($group, $file)) {
			$contents = $this->cacheManager->retrieve($group, $file)??'null';
			if (str_ends_with($file, '.json')) {
				$contents = json_encode(json_decode($contents), \JSON_PRETTY_PRINT);
			}
			$msg = Text::makeBlob("Cache File: {$group} {$file}", htmlspecialchars($contents));
		} else {
			$msg = "Could not find file <highlight>{$file}<end> in cache group <highlight>{$group}<end>.";
		}
		$context->reply($msg);
	}
}
