<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	CommandReply,
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
	public function setup() {
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
	 * @Matches("/^beer$/i")
	 * @Matches("/^beer (\d+)$/i")
	 */
	public function beerCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = $this->getFunItem('beer', $sender, isset($args[1]) ? (int)$args[1] : null);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("brain")
	 * @Matches("/^brain$/i")
	 * @Matches("/^brain (\d+)$/i")
	 *
	 * aypwip.php - A Social Worrrrrld Domination! Module
	 *
	 * @author Mastura (RK2)
	 * @author Tyrence (RK2), converted to Budabot
	 */
	public function brainCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = $this->getFunItem('brain', $sender, isset($args[1]) ? (int)$args[1] : null);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("chuck")
	 * @Matches("/^chuck$/i")
	 * @Matches("/^chuck (\d+)$/i")
	 *
	 * @author Honge (RK2)
	 * @author Temar
	 *
	 * @url http://bebot.shadow-realm.org/0-3-x-customunofficial-modules/chuck-norris/
	 */
	public function chuckCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = $this->getFunItem('chuck', $sender, isset($args[1]) ? (int)$args[1] : null);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("cybor")
	 * @Matches("/^cybor$/i")
	 * @Matches("/^cybor (\d+)$/i")
	 *
	 * @author Derroylo (RK2)
	 * @author Xenixa (RK1)
	 */
	public function cyborCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = $this->getFunItem('cybor', $sender, isset($args[1]) ? (int)$args[1] : null);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("dwight")
	 * @Matches("/^dwight$/i")
	 * @Matches("/^dwight (\d+)$/i")
	 *
	 * @author Sicarius Legion of Amra, a Age of Conan Guild on the Hyrkania server
	 * @author Tyrence (RK2), converted to Budabot
	 */
	public function dwightCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = $this->getFunItem('dwight', $sender, isset($args[1]) ? (int)$args[1] : null);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("fc")
	 * @Matches("/^fc$/i")
	 * @Matches("/^fc (\d+)$/i")
	 */
	public function fcCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = $this->getFunItem('fc', $sender, isset($args[1]) ? (int)$args[1] : null);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("homer")
	 * @Matches("/^homer$/i")
	 * @Matches("/^homer (\d+)$/i")
	 *
	 * @author Derroylo (RK2)
	 * @author MysterF aka Floryn from Band of Brothers
	 * @url http://bebot.shadow-realm.org/generic-custom-modules/homer-social-mod-for-bebot-0-6-2
	 */
	public function homerCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = $this->getFunItem('homer', $sender, isset($args[1]) ? (int)$args[1] : null);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("pirates")
	 * @Matches("/^pirates$/i")
	 * @Matches("/^pirates (\d+)$/i")
	 *
	 * @author Sicarius Legion of Amra, an Age of Conan Guild on the Hyrkania server
	 * @author Tyrence (RK2), converted to Budabot
	 */
	public function piratesCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = $this->getFunItem('pirates', $sender, isset($args[1]) ? (int)$args[1] : null);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("compliment")
	 * @Matches("/^compliment$/i")
	 * @Matches("/^compliment (\d+)$/i")
	 */
	public function complimentCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = $this->getFunItem('compliment', $sender, isset($args[1]) ? (int)$args[1] : null);
		$sendto->reply($msg);
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
