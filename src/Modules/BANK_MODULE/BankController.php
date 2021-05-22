<?php declare(strict_types=1);

namespace Nadybot\Modules\BANK_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	CommandReply,
	DB,
	SettingManager,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 * @author Marebone (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'bank',
 *		accessLevel = 'guild',
 *		description = 'Browse and search the bank characters',
 *		help        = 'bank.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'bank update',
 *		accessLevel = 'admin',
 *		description = 'Reloads the bank database from the AO Items Assistant file',
 *		help        = 'bank.txt',
 *		alias		= 'updatebank'
 *	)
 */
class BankController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public SettingManager $settingManager;

	/**
	 * @Setup
	 */
	public function setup() {
		$this->db->loadMigrations($this->moduleName, __DIR__ . '/Migrations');

		$this->settingManager->add(
			$this->moduleName,
			'bank_file_location',
			'Location of the AO Items Assistant csv dump file',
			'edit',
			'text',
			'./src/Modules/BANK_MODULE/import.csv'
		);
		$this->settingManager->add(
			$this->moduleName,
			'max_bank_items',
			'Number of items shown in search results',
			'edit',
			'number',
			'50',
			'20;50;100'
		);
	}

	/**
	 * @HandlesCommand("bank")
	 * @Matches("/^bank browse$/i")
	 */
	public function bankBrowseCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$characters = $this->db->table("bank")
			->orderBy("player")
			->select("player")->distinct()
			->asObj()->pluck("player");
		$blob = "<header2>Available characters<end>\n";
		foreach ($characters as $character) {
			$characterLink = $this->text->makeChatcmd($character, "/tell <myname> bank browse {$character}");
			$blob .= "<tab>$characterLink\n";
		}

		$msg = $this->text->makeBlob('Bank Characters', $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("bank")
	 * @Matches("/^bank browse ([a-z0-9-]+)$/i")
	 */
	public function bankBrowsePlayerCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));

		$data = $this->db->table("bank")
			->where("player", $name)
			->orderBy("container")
			->select("container_id", "container", "player")
			->asObj();
		if ($data->count() === 0) {
			$msg = "Could not find bank character <highlight>$name<end>.";
			$sendto->reply($msg);
			return;
		}
		$blob = "<header2>Containers on $name<end>\n";
		foreach ($data as $row) {
			$container_link = $this->text->makeChatcmd($row->container, "/tell <myname> bank browse {$row->player} {$row->container_id}");
			$blob .= "<tab>{$container_link}\n";
		}

		$msg = $this->text->makeBlob("Containers for $name", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("bank")
	 * @Matches("/^bank browse ([a-z0-9-]+) (\d+)$/i")
	 */
	public function bankBrowseContainerCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));
		$containerId = $args[2];
		$limit = $this->settingManager->getInt('max_bank_items');

		$data = $this->db->table("bank")
			->where("player", $name)
			->where("container_id", $containerId)
			->orderBy("name")
			->orderBy("ql")
			->limit($limit)
			->asObj(Bank::class);

		if ($data->count() === 0) {
			$msg = "Could not find container with id <highlight>{$containerId}</highlight> on bank character <highlight>{$name}<end>.";
			$sendto->reply($msg);
			return;
		}
		$blob = '<header2>Items in ' . $data[0]->container . "<end>\n";
		foreach ($data as $row) {
			$item_link = $this->text->makeItem($row->lowid, $row->highid, $row->ql, $row->name);
			$blob .= "<tab>{$item_link} (QL {$row->ql})\n";
		}

		$msg = $this->text->makeBlob("Contents of $row->container", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("bank")
	 * @Matches("/^bank search (.+)$/i")
	 */
	public function bankSearchCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$search = htmlspecialchars_decode($args[1]);
		$words = explode(' ', $search);
		$limit = $this->settingManager->getInt('max_bank_items');
		$query = $this->db->table("bank")
			->orderBy("name")
			->orderBy("ql")
			->limit($limit);
		$this->db->addWhereFromParams($query, $words, 'name');

		/** @var Collection<Bank> */
		$foundItems = $query->asObj(Bank::class);

		$blob = '';
		if ($foundItems->count() === 0) {
			$msg = "Could not find any search results for <highlight>{$args[1]}<end>.";
			$sendto->reply($msg);
			return;
		}
		foreach ($foundItems as $item) {
			$itemLink = $this->text->makeItem($item->lowid, $item->highid, $item->ql, $item->name);
			$blob .= "{$itemLink} (QL {$item->ql}) in <highlight>{$item->player} &gt; {$item->container}<end>\n";
		}

		$msg = $this->text->makeBlob("Bank Search Results for {$args[1]}", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("bank update")
	 * @Matches("/^bank update$/i")
	 */
	public function bankUpdateCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$lines = @file($this->settingManager->get('bank_file_location'));

		if ($lines === false) {
			$msg = "Could not open file: '" . $this->settingManager->get('bank_file_location') . "'";
			$sendto->reply($msg);
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
		$sendto->reply($msg);
	}
}
