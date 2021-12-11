<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	CmdContext,
	DB,
	Util,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'beer',
 *		accessLevel = 'all',
 *		description = 'Shows a random beer message',
 *		help        = 'fun_module.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'brain',
 *		accessLevel = 'all',
 *		description = 'Shows a random pinky and the brain quote',
 *		help        = 'fun_module.txt',
 *		alias       = 'pinky'
 *	)
 *	@DefineCommand(
 *		command     = 'chuck',
 *		accessLevel = 'all',
 *		description = 'Shows a random Chuck Norris joke',
 *		help        = 'fun_module.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'cybor',
 *		accessLevel = 'all',
 *		description = 'Shows a random cybor message',
 *		help        = 'fun_module.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'dwight',
 *		accessLevel = 'all',
 *		description = 'Shows a random Dwight quote',
 *		help        = 'fun_module.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'fc',
 *		accessLevel = 'all',
 *		description = 'Shows a random FC quote',
 *		help        = 'fun_module.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'homer',
 *		accessLevel = 'all',
 *		description = 'Shows a random homer quote',
 *		help        = 'fun_module.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'pirates',
 *		accessLevel = 'all',
 *		description = 'Shows a random Pirates of the Caribbean quote',
 *		help        = 'fun_module.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'compliment',
 *		accessLevel = 'all',
 *		description = 'Shows a random compliment',
 *		help        = 'fun_module.txt'
 *	)
 */
class FunController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Util $util;

	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
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

	public function getFunItem(string $type, string $sender, int $number=null): string {
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

		return $msg;
	}

	/**
	 * @HandlesCommand("beer")
	 * @HandlesCommand("brain")
	 * @HandlesCommand("chuck")
	 * @HandlesCommand("cybor")
	 * @HandlesCommand("dwight")
	 * @HandlesCommand("fc")
	 * @HandlesCommand("homer")
	 * @HandlesCommand("pirates")
	 * @HandlesCommand("compliment")
	 */
	public function funCommand(CmdContext $context, ?int $num): void {
		$msg = $this->getFunItem(
			explode(" ", $context->message)[0],
			$context->char->name,
			$num
		);
		$context->reply($msg);
	}

	/**
	 * @NewsTile("fun-compliment")
	 * @Description("Gives a random motivational compliment")
	 * @Example("» You inspire be to do good things.")
	 */
	public function complimentTile(string $sender, callable $callback): void {
		$msg = "» " . $this->getFunItem('compliment', $sender, null);
		$callback($msg);
	}
}
