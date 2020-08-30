<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\{
	CacheManager,
	CommandReply,
	Nadybot,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'cache',
 *		accessLevel = 'superadmin',
 *		description = "Manage cached files",
 *		help        = 'cache.txt'
 *	)
 */
class CacheController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public CacheManager $cacheManager;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Nadybot $chatBot;

	/**
	 * @HandlesCommand("cache")
	 * @Matches("/^cache$/i")
	 */
	public function cacheCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$blob = '';
		foreach ($this->cacheManager->getGroups() as $group) {
			$blob .= $this->text->makeChatcmd($group, "/tell <myname> cache browse $group") . "\n";
		}
		$msg = $this->text->makeBlob("Cache Groups", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("cache")
	 * @Matches("/^cache browse ([a-z0-9_-]+)$/i")
	 */
	public function cacheBrowseCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$group = $args[1];
		
		$path = $this->chatBot->vars['cachefolder'] . $group;
	
		$blob = '';
		foreach ($this->cacheManager->getFilesInGroup($group) as $file) {
			$fileInfo = stat($path . "/" . $file);
			$blob .= "<highlight>$file<end>  " . $this->util->bytesConvert($fileInfo['size']) . " - Last modified " . $this->util->date($fileInfo['mtime']);
			$blob .= "  [" . $this->text->makeChatcmd("View", "/tell <myname> cache view $group $file") . "]";
			$blob .= "  [" . $this->text->makeChatcmd("Delete", "/tell <myname> cache rem $group $file") . "]\n";
		}
		$msg = $this->text->makeBlob("Cache Group: $group", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("cache")
	 * @Matches("/^cache rem ([a-z0-9_-]+) ([a-z0-9_\.-]+)$/i")
	 */
	public function cacheRemCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$group = $args[1];
		$file = $args[2];
		
		if ($this->cacheManager->cacheExists($group, $file)) {
			$$this->cacheManager->remove($group, $file);
			$msg = "Cache file <highlight>$file<end> in cache group <highlight>$group<end> has been deleted.";
		} else {
			$msg = "Could not find file <highlight>$file<end> in cache group <highlight>$group<end>.";
		}
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("cache")
	 * @Matches("/^cache view ([a-z0-9_-]+) ([a-z0-9_\.-]+)$/i")
	 */
	public function cacheViewCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$group = $args[1];
		$file = $args[2];
		
		if ($this->cacheManager->cacheExists($group, $file)) {
			$contents = $this->cacheManager->retrieve($group, $file);
			if (preg_match("/\.json$/", $file)) {
				$contents = json_encode(json_decode($contents), JSON_PRETTY_PRINT);
			}
			$msg = $this->text->makeBlob("Cache File: $group $file", htmlspecialchars($contents));
		} else {
			$msg = "Could not find file <highlight>$file<end> in cache group <highlight>$group<end>.";
		}
		$sendto->reply($msg);
	}
}
