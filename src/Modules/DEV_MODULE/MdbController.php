<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	CmdContext,
	ModuleInstance,
	Nadybot,
	Text,
	Util,
};

/**
 * @author Tyrence
 * Read values from the MDB file
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "mdb",
		accessLevel: "all",
		description: "Search for values in the MDB file",
		help: "mdb.txt"
	)
]
class MdbController extends ModuleInstance {

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Text $text;

	#[NCA\HandlesCommand("mdb")]
	public function mdbCommand(CmdContext $context): void {
		$categories = $this->chatBot->mmdbParser->getCategories();
		if (!isset($categories)) {
			$context->reply("Cannot find any categories.");
			return;
		}

		$blob = '';
		foreach ($categories as $category) {
			$blob .= $this->text->makeChatcmd((string)$category['id'], "/tell <myname> mdb " . $category['id']) . "\n";
		}

		$msg = $this->text->makeBlob("MDB Categories", $blob);

		$context->reply($msg);
	}

	#[NCA\HandlesCommand("mdb")]
	public function mdbCategoryCommand(CmdContext $context, int $categoryId): void {
		$instances = $this->chatBot->mmdbParser->findAllInstancesInCategory($categoryId);
		if (!isset($instances)) {
			$context->reply("Cannot find category <highlight>{$categoryId}<end>.");
			return;
		}

		$blob = '';
		foreach ($instances as $instance) {
			$blob .= $this->text->makeChatcmd((string)$instance['id'], "/tell <myname> mdb $categoryId " . $instance['id']) . "\n";
		}

		$msg = $this->text->makeBlob("MDB Instances for Category $categoryId", $blob);

		$context->reply($msg);
	}

	#[NCA\HandlesCommand("mdb")]
	public function mdbInstanceCommand(CmdContext $context, int $categoryId, int $instanceId): void {
		$messageString = $this->chatBot->mmdbParser->getMessageString($categoryId, $instanceId);
		$msg = "Unable to find MDB string category <highlight>{$categoryId}<end>, ".
			"instance <highlight>{$instanceId}<end>.";
		if ($messageString !== null) {
			$msg = "[$categoryId : $instanceId] $messageString";
		}
		$context->reply($msg);
	}
}
