<?php declare(strict_types=1);

namespace Nadybot\Modules\BANK_MODULE;

use Nadybot\Core\Attributes as NCA;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	CmdContext,
	CommandReply,
	DB,
	SettingManager,
	Text,
	Util,
};
use Nadybot\Core\ParamClass\PCharacter;

/**
 * @author Tyrence (RK2)
 * @author Marebone (RK2)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "bank",
		accessLevel: "guild",
		description: "Browse and search the bank characters",
		help: "bank.txt"
	),
	NCA\DefineCommand(
		command: "bank update",
		accessLevel: "admin",
		description: "Reloads the bank database from the AO Items Assistant file",
		help: "bank.txt",
		alias: "updatebank"
	)
]
class BankController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Setup]
	public function setup(): void {

		$this->settingManager->add(
			module: $this->moduleName,
			name: 'bank_file_location',
			description: 'Location of the AO Items Assistant csv dump file',
			mode: 'edit',
			type: 'text',
			value: './src/Modules/BANK_MODULE/import.csv'
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'max_bank_items',
			description: 'Number of items shown in search results',
			mode: 'edit',
			type: 'number',
			value: '50',
			options: '20;50;100'
		);
	}

	#[NCA\HandlesCommand("bank")]
	public function bankBrowseCommand(CmdContext $context, #[NCA\Str("browse")] string $action): void {
		$characters = $this->db->table("bank")
			->orderBy("player")
			->select("player")->distinct()
			->pluckAs("player", "string");
		if ($characters->isEmpty()) {
			$context->reply("No bank characters found.");
			return;
		}
		$blob = "<header2>Available characters<end>\n";
		foreach ($characters as $character) {
			$characterLink = $this->text->makeChatcmd($character, "/tell <myname> bank browse {$character}");
			$blob .= "<tab>$characterLink\n";
		}

		$msg = $this->text->makeBlob('Bank Characters', $blob);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("bank")]
	public function bankBrowsePlayerCommand(
		CmdContext $context,
		#[NCA\Str("browse")] string $action,
		PCharacter $char
	): void {
		$name = $char();

		/** @var Collection<Bank> */
		$data = $this->db->table("bank")
			->where("player", $name)
			->orderBy("container")
			->asObj(Bank::class);
		if ($data->count() === 0) {
			$msg = "Could not find bank character <highlight>$name<end>.";
			$context->reply($msg);
			return;
		}
		$blob = "<header2>Containers on $name<end>\n";
		foreach ($data as $row) {
			$container_link = $this->text->makeChatcmd($row->container, "/tell <myname> bank browse {$row->player} {$row->container_id}");
			$blob .= "<tab>{$container_link}\n";
		}

		$msg = $this->text->makeBlob("Containers for $name", $blob);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("bank")]
	public function bankBrowseContainerCommand(CmdContext $context, #[NCA\Str("browse")] string $action, PCharacter $char, int $containerId): void {
		$name = $char();
		$limit = $this->settingManager->getInt('max_bank_items') ?? 50;

		$data = $this->db->table("bank")
			->where("player", $name)
			->where("container_id", $containerId)
			->orderBy("name")
			->orderBy("ql")
			->limit($limit)
			->asObj(Bank::class);

		if ($data->count() === 0) {
			$msg = "Could not find container with id <highlight>{$containerId}</highlight> on bank character <highlight>{$name}<end>.";
			$context->reply($msg);
			return;
		}
		$blob = '<header2>Items in ' . $data[0]->container . "<end>\n";
		foreach ($data as $row) {
			$item_link = $this->text->makeItem($row->lowid, $row->highid, $row->ql, $row->name);
			$blob .= "<tab>{$item_link} (QL {$row->ql})\n";
		}

		$msg = $this->text->makeBlob("Contents of {$data[0]->container}", $blob);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("bank")]
	public function bankSearchCommand(CmdContext $context, #[NCA\Str("search")] string $action, string $search): void {
		$search = htmlspecialchars_decode($search);
		$words = explode(' ', $search);
		$limit = $this->settingManager->getInt('max_bank_items') ?? 50;
		$query = $this->db->table("bank")
			->orderBy("name")
			->orderBy("ql")
			->limit($limit);
		$this->db->addWhereFromParams($query, $words, 'name');

		/** @var Collection<Bank> */
		$foundItems = $query->asObj(Bank::class);

		$blob = '';
		if ($foundItems->count() === 0) {
			$msg = "Could not find any search results for <highlight>{$search}<end>.";
			$context->reply($msg);
			return;
		}
		foreach ($foundItems as $item) {
			$itemLink = $this->text->makeItem($item->lowid, $item->highid, $item->ql, $item->name);
			$blob .= "{$itemLink} (QL {$item->ql}) in <highlight>{$item->player} &gt; {$item->container}<end>\n";
		}

		$msg = $this->text->makeBlob("Bank Search Results for {$search}", $blob);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("bank update")]
	public function bankUpdateCommand(CmdContext $context, #[NCA\Str("update")] string $action): void {
		$lines = @file($this->settingManager->getString('bank_file_location')??"");

		if ($lines === false) {
			$msg = "Could not open file: '" . ($this->settingManager->getString('bank_file_location')??"") . "'";
			$context->reply($msg);
			return;
		}

		//remove the header line
		array_shift($lines);

		$this->db->beginTransaction();
		$this->db->table("bank")->truncate();

		foreach ($lines as $line) {
			// this is the order of columns in the CSV file (AOIA v1.1.3.0):
			// Item Name,QL,Character,Backpack,Location,LowID,HighID,ContainerID,Link
			[$name, $ql, $player, $container, $location, $lowId, $highId, $containerId] = str_getcsv($line);
			if ($location !== 'Bank' && $location !== 'Inventory') {
				continue;
			}
			if ($container == '') {
				$container = $location;
			}

			$this->db->table("bank")
				->insert([
					"name" => $name,
					"lowid" => $lowId,
					"highid" => $highId,
					"ql" => $ql,
					"player" => $player,
					"container" => $container,
					"container_id" => $containerId,
					"location" => $location,
				]);
		}
		$this->db->commit();

		$msg = "The bank database has been updated.";
		$context->reply($msg);
	}
}
