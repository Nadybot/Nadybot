<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use AO\MMDB\AsyncClient;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ModuleInstance,
	Nadybot,
	Text,
	Util,
};

/**
 * @author Tyrence
 * Read values from the MDB file
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "mdb",
		accessLevel: "guest",
		description: "Search for values in the MDB file",
	)
]
class MdbController extends ModuleInstance {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Text $text;

	/** Get a list of categories from the MDB */
	#[NCA\HandlesCommand("mdb")]
	public function mdbCommand(CmdContext $context): void {
		$client = AsyncClient::createDefault();
		$categories = $client->getCategories();
		if (!isset($categories)) {
			$context->reply("Cannot find any categories.");
			return;
		}

		$blob = '';
		foreach ($categories as $category) {
			$blob .= $this->text->makeChatcmd((string)$category->id, "/tell <myname> mdb " . $category->id) . "\n";
		}

		$msg = $this->text->makeBlob("MDB Categories", $blob);

		$context->reply($msg);
	}

	/** Get a list of instances for an MDB category */
	#[NCA\HandlesCommand("mdb")]
	public function mdbCategoryCommand(CmdContext $context, int $categoryId): void {
		$client = AsyncClient::createDefault();
		$instances = $client->findAllInstancesInCategory($categoryId);
		if (!isset($instances)) {
			$context->reply("Cannot find category <highlight>{$categoryId}<end>.");
			return;
		}

		$blob = '';
		foreach ($instances as $instance) {
			$blob .= $this->text->makeChatcmd((string)$instance->id, "/tell <myname> mdb {$categoryId} " . $instance->id) . "\n";
		}

		$msg = $this->text->makeBlob("MDB Instances for Category {$categoryId}", $blob);

		$context->reply($msg);
	}

	/** See an MDB by category and instance */
	#[NCA\HandlesCommand("mdb")]
	public function mdbInstanceCommand(CmdContext $context, int $categoryId, int $instanceId): void {
		$client = AsyncClient::createDefault();
		$messageString = $client->getMessageString($categoryId, $instanceId) ?? "- not found -";
		$msg = "Unable to find MDB string category <highlight>{$categoryId}<end>, ".
			"instance <highlight>{$instanceId}<end>.";
		if ($messageString !== null) {
			$msg = "[{$categoryId} : {$instanceId}] {$messageString}";
		}
		$context->reply($msg);
	}
}
