<?php declare(strict_types=1);

namespace Nadybot\Modules\BANK_MODULE;

use function Amp\async;
use function Amp\Future\await;
use function Safe\{preg_match, preg_split};
use Amp\File\{Filesystem, FilesystemException};
use Amp\Http\Client\{HttpClientBuilder, Request};
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	ParamClass\PCharacter,
	Text,
	UserException,
};
use Nadybot\Modules\RAFFLE_MODULE\RaffleItem;

/**
 * @author Tyrence (RK2)
 * @author Marebone (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "bank",
		accessLevel: "guild",
		description: "Browse and search the bank characters",
	),
	NCA\DefineCommand(
		command: "bank update",
		accessLevel: "admin",
		description: "Reloads the bank database from the AO Items Assistant file",
		alias: "updatebank"
	),
]
class BankController extends ModuleInstance {
	/**
	 * Location/URL of the AO Items Assistant CSV dump file
	 *
	 * @var string[]
	 */
	#[NCA\Setting\ArrayOfText]
	public array $bankFileLocation = ['./data/bank.csv'];

	/** Number of items shown in search results */
	#[NCA\Setting\Number(options: [20, 50, 100])]
	public int $maxBankItems = 50;
	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private Filesystem $fs;

	/** List the bank characters in the database: */
	#[NCA\HandlesCommand("bank")]
	public function bankBrowseCommand(CmdContext $context, #[NCA\Str("browse")] string $action): void {
		$characters = $this->db->table("bank")
			->orderBy("player")
			->select("player")->distinct()
			->pluckStrings("player");
		if ($characters->isEmpty()) {
			$context->reply("No bank characters found.");
			return;
		}
		$blob = "<header2>Available characters<end>\n";
		foreach ($characters as $character) {
			$characterLink = $this->text->makeChatcmd($character, "/tell <myname> bank browse {$character}");
			$blob .= "<tab>{$characterLink}\n";
		}

		$msg = $this->text->makeBlob('Bank Characters', $blob);
		$context->reply($msg);
	}

	/** List the containers of a given bank character: */
	#[NCA\HandlesCommand("bank")]
	public function bankBrowsePlayerCommand(
		CmdContext $context,
		#[NCA\Str("browse")]
		string $action,
		PCharacter $char
	): void {
		$name = $char();

		/** @var Collection<string,Collection<Bank>> */
		$data = $this->db->table("bank")
			->where("player", $name)
			->orderBy("container")
			->asObj(Bank::class)
			->groupBy("container");
		if ($data->count() === 0) {
			$msg = "Could not find bank character <highlight>{$name}<end>.";
			$context->reply($msg);
			return;
		}
		$blob = "<header2>Containers on {$name}<end>\n";
		foreach ($data as $container => $items) {
			$firstItem = $items->firstOrFail();
			$container_link = $this->text->makeChatcmd($container, "/tell <myname> bank browse {$name} {$firstItem->container_id}");
			$blob .= "<tab>{$container_link} (" . $items->count() . " items)\n";
		}

		$msg = $this->text->makeBlob("Containers for {$name}", $blob);
		$context->reply($msg);
	}

	/** See the contents of a container on a bank character */
	#[NCA\HandlesCommand("bank")]
	public function bankBrowseContainerCommand(CmdContext $context, #[NCA\Str("browse")] string $action, PCharacter $char, int $containerId): void {
		$name = $char();
		$limit = $this->maxBankItems;

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
			$item = new RaffleItem();
			$itemLink = $this->text->makeItem($row->lowid, $row->highid, $row->ql, $row->name);
			$item->fromString($itemLink);
			$itemLink = $item->toString();
			$compactItemLink = str_replace("'", '', $itemLink);
			$askLink = $this->text->makeChatcmd("ask", "/tell <myname> wish from {$name} {$compactItemLink} from {$data[0]->container}");
			$blob .= "<tab>{$itemLink} [{$askLink}]\n";
		}

		$msg = $this->text->makeBlob("Contents of {$data[0]->container}", $blob);
		$context->reply($msg);
	}

	/** Search for an item on all bank characters */
	#[NCA\HandlesCommand("bank")]
	#[NCA\Help\Example("<symbol>bank search operative")]
	#[NCA\Help\Example("<symbol>bank search 225 sharpshooter")]
	#[NCA\Help\Example("<symbol>bank search 10-200 symbiant")]
	public function bankSearchCommand(
		CmdContext $context,
		#[NCA\Str("search")]
		string $action,
		#[NCA\Regexp("\d+(?:(?:\s*-\s*|\s+)\d+)?", "&lt;ql range&gt;")]
		?string $ql,
		string $search
	): void {
		$search = htmlspecialchars_decode($search);
		$words = explode(' ', $search);
		$limit = $this->maxBankItems;
		$query = $this->db->table("bank")
			->orderBy("name")
			->orderBy("ql")
			->limit($limit);
		if (isset($ql)) {
			[$low, $high] = preg_split("/(\s*-\s*|\s+)/", $ql);
			if (isset($high)) {
				$query->where("ql", ">=", min((int)$low, (int)$high));
				$query->where("ql", "<=", max((int)$low, (int)$high));
			} else {
				$query->where("ql", (int)$low);
			}
		}
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
			$item2 = new RaffleItem();
			$item2->fromString($itemLink);
			$itemLink = $item2->toString();
			$compactItemLink = str_replace("'", '', $itemLink);
			$askLink = $this->text->makeChatcmd("ask", "/tell <myname> wish from {$item->player} {$compactItemLink} from {$item->container}");
			$blob .= "{$itemLink} in <highlight>{$item->player} &gt; {$item->container}<end> [{$askLink}]\n";
		}

		$msg = $this->text->makeBlob("Bank Search Results for {$search}", $blob);
		$context->reply($msg);
	}

	/** Reload the bank database from the file specified with the <a href='chatcmd:///tell <myname> settings change bank_file_location'>bank_file_location</a> setting */
	#[NCA\HandlesCommand("bank update")]
	public function bankUpdateCommand(CmdContext $context, #[NCA\Str("update")] string $action): void {
		$procs = [];
		foreach ($this->bankFileLocation as $location) {
			$procs []= async($this->loadLocation(...), $location);
		}

		$bodies = await($procs);
		$lines = array_merge(
			...array_map(
				fn (string $body) => preg_split("/(?:\r\n|\r|\n)/", $body),
				$bodies
			)
		);
		$this->bankUpdate($lines);
		$context->reply("The bank database has been updated.");
	}

	/**
	 * Load the content of the bank dump from $location and return it
	 *
	 * @param string $location A file path or URL to load
	 *
	 * @return string The complete file content
	 *
	 * @throws UserException On error
	 */
	private function loadLocation(string $location): string {
		if (preg_match("|^https?://|", $location)) {
			$client = $this->builder->build();

			$response = $client->request(new Request($location));
			if ($response->getStatus() !== 200) {
				throw new UserException(
					"Received code <highlight>" . $response->getStatus() . "<end> " .
					"when trying to download the bank ".
					"CSV file <highlight>{$location}<end>."
				);
			}
			return $response->getBody()->buffer();
		}
		try {
			return $this->fs->read($location);
		} catch (FilesystemException $e) {
			$msg = "Could not open file '{$location}': " . $e->getMessage();
			throw new UserException($msg);
		}
	}

	/** @param string[] $lines */
	private function bankUpdate(array $lines): void {
		// remove the header line
		array_shift($lines);

		$this->db->awaitBeginTransaction();
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
	}
}
