<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Util,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "beer",
		accessLevel: "guest",
		description: "Shows a random beer message",
	),
	NCA\DefineCommand(
		command: "brain",
		accessLevel: "guest",
		description: "Shows a random pinky and the brain quote",
		alias: "pinky"
	),
	NCA\DefineCommand(
		command: "chuck",
		accessLevel: "guest",
		description: "Shows a random Chuck Norris joke",
	),
	NCA\DefineCommand(
		command: "cybor",
		accessLevel: "guest",
		description: "Shows a random cybor message",
	),
	NCA\DefineCommand(
		command: "dwight",
		accessLevel: "guest",
		description: "Shows a random Dwight quote",
	),
	NCA\DefineCommand(
		command: "fc",
		accessLevel: "guest",
		description: "Shows a random FC quote",
	),
	NCA\DefineCommand(
		command: "homer",
		accessLevel: "guest",
		description: "Shows a random homer quote",
	),
	NCA\DefineCommand(
		command: "pirates",
		accessLevel: "guest",
		description: "Shows a random Pirates of the Caribbean quote",
	),
	NCA\DefineCommand(
		command: "compliment",
		accessLevel: "guest",
		description: "Shows a random compliment",
	)
]
class FunController extends ModuleInstance {
	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/beer.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/brain.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/chuck.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/cybor.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/dwight.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/fc.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/homer.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/pirates.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/compliment.csv");
	}

	public function getFunItem(string $type, string $sender, ?int $number=null): string {
		/** @var Collection<Fun> */
		$data = $this->db->table("fun")
			->where("type", $type)
			->asObj(Fun::class);
		if ($number === null) {
			/** @var Fun */
			$row = $data->random();
		} else {
			$row = $data[$number];
		}

		if ($row === null) {
			$msg = "There is no item with that id.";
		} else {
			$dmg = rand(100, 999);
			$cred = rand(10000, 9999999);
			$msg = $row->content;
			$msg = str_replace("*name*", $sender, $msg);
			$msg = str_replace("*dmg*", (string)$dmg, $msg);
			$msg = str_replace("*creds*", (string)$cred, $msg);
		}

		// @phpstan-ignore-next-line
		return $msg;
	}

	/** Show a random funny line/quote/compliment, or a specific one given with &lt;num&gt; */
	#[
		NCA\HandlesCommand("beer"),
		NCA\HandlesCommand("brain"),
		NCA\HandlesCommand("chuck"),
		NCA\HandlesCommand("cybor"),
		NCA\HandlesCommand("dwight"),
		NCA\HandlesCommand("fc"),
		NCA\HandlesCommand("homer"),
		NCA\HandlesCommand("pirates"),
		NCA\HandlesCommand("compliment")
	]
	public function funCommand(CmdContext $context, ?int $num): void {
		$msg = $this->getFunItem(
			explode(" ", $context->message)[0],
			$context->char->name,
			$num
		);
		$context->reply($msg);
	}

	#[
		NCA\NewsTile(
			name: "fun-compliment",
			description: "Gives a random motivational compliment",
			example: "» You inspire be to do good things."
		)
	]
	public function complimentTile(string $sender, callable $callback): void {
		$msg = "» " . $this->getFunItem('compliment', $sender, null);
		$callback($msg);
	}
}
