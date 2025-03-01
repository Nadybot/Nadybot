<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use AO\MMDB\AsyncMMDBClient;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ModuleInstance,
	Text,
	Types\AccessLevel,
};

/**
 * @author Tyrence
 * Read values from the MDB file
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'mdb',
		accessLevel: AccessLevel::Guest,
		description: 'Search for values in the MDB file',
	)
]
class MdbController extends ModuleInstance {
	/** Get a list of categories from the MDB */
	#[NCA\HandlesCommand('mdb')]
	public function mdbCommand(CmdContext $context): void {
		$client = AsyncMMDBClient::createDefault();
		$categories = $client->getCategories();
		if (!isset($categories)) {
			$context->reply('Cannot find any categories.');
			return;
		}

		$blob = '';
		foreach ($categories as $category) {
			$blob .= Text::makeChatcmd((string)$category->id, '/tell <myname> mdb ' . $category->id) . "\n";
		}

		$msg = Text::makeBlob('MDB Categories', $blob);

		$context->reply($msg);
	}

	/** Get a list of instances for an MDB category */
	#[NCA\HandlesCommand('mdb')]
	public function mdbCategoryCommand(CmdContext $context, int $categoryId): void {
		$client = AsyncMMDBClient::createDefault();
		$instances = $client->findAllInstancesInCategory($categoryId);
		if (!isset($instances)) {
			$context->reply("Cannot find category <highlight>{$categoryId}<end>.");
			return;
		}

		$blob = '';
		foreach ($instances as $instance) {
			$blob .= Text::makeChatcmd((string)$instance->id, "/tell <myname> mdb {$categoryId} " . $instance->id) . "\n";
		}

		$msg = Text::makeBlob("MDB Instances for Category {$categoryId}", $blob);

		$context->reply($msg);
	}

	/** See an MDB by category and instance */
	#[NCA\HandlesCommand('mdb')]
	public function mdbInstanceCommand(CmdContext $context, int $categoryId, int $instanceId): void {
		$client = AsyncMMDBClient::createDefault();
		$messageString = $client->getMessageString($categoryId, $instanceId) ?? '- not found -';
		$msg = "[{$categoryId} : {$instanceId}] {$messageString}";
		$context->reply($msg);
	}
}
