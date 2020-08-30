<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\{
	CommandReply,
	Nadybot,
	Text,
	Util,
};

/**
 * @author Tyrence
 *
 * Read values from the MDB file
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'mdb',
 *		accessLevel = 'all',
 *		description = 'Search for values in the MDB file',
 *		help        = 'mdb.txt'
 *	)
 */
class MdbController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/**
	 * @var \Nadybot\Core\Nadybot $chatBot
	 * @Inject
	 */
	public Nadybot $chatBot;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Text $text;
	
	/**
	 * @HandlesCommand("mdb")
	 * @Matches("/^mdb$/i")
	 */
	public function mdbCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$categories = $this->chatBot->mmdbParser->getCategories();
		
		$blob = '';
		foreach ($categories as $category) {
			$blob .= $this->text->makeChatcmd((string)$category['id'], "/tell <myname> mdb " . $category['id']) . "\n";
		}
		
		$msg = $this->text->makeBlob("MDB Categories", $blob);
		
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("mdb")
	 * @Matches("/^mdb ([0-9]+)$/i")
	 */
	public function mdbCategoryCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$categoryId = (int)$args[1];
		
		$instances = $this->chatBot->mmdbParser->findAllInstancesInCategory($categoryId);

		$blob = '';
		foreach ($instances as $instance) {
			$blob .= $this->text->makeChatcmd((string)$instance['id'], "/tell <myname> mdb $categoryId " . $instance['id']) . "\n";
		}
		
		$msg = $this->text->makeBlob("MDB Instances for Category $categoryId", $blob);
		
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("mdb")
	 * @Matches("/^mdb ([0-9]+) ([0-9]+)$/i")
	 */
	public function mdbInstanceCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$categoryId = (int)$args[1];
		$instanceId = (int)$args[2];
		
		$messageString = $this->chatBot->mmdbParser->getMessageString($categoryId, $instanceId);
		$msg = "Unable to find MDB string category <highlight>$categoryId<end>, ".
			"instance <highlight>$instanceId<end>.";
		if ($messageString !== null) {
			$msg = "[$categoryId : $instanceId] $messageString";
		}
		$sendto->reply($msg);
	}
}
