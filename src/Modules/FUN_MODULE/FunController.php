<?php

namespace Budabot\Modules\FUN_MODULE;

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
 */
class FunController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public $moduleName;

	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;
	
	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;

	/**
	 * @var \Budabot\Core\Util $util
	 * @Inject
	 */
	public $util;
	
	/**
	 * @Setup
	 */
	public function setup() {
		$this->db->loadSQLFile($this->moduleName, "fun");
		$this->db->loadSQLFile($this->moduleName, "beer");
		$this->db->loadSQLFile($this->moduleName, "brain");
		$this->db->loadSQLFile($this->moduleName, "chuck");
		$this->db->loadSQLFile($this->moduleName, "cybor");
		$this->db->loadSQLFile($this->moduleName, "dwight");
		$this->db->loadSQLFile($this->moduleName, "fc");
		$this->db->loadSQLFile($this->moduleName, "homer");
		$this->db->loadSQLFile($this->moduleName, "pirates");
	}
	
	public function getFunItem($type, $sender, $number=null) {
		$data = $this->db->query("SELECT * FROM fun WHERE type = ?", $type);
		if ($number === null) {
			$row = $this->util->randomArrayValue($data);
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
			$msg = str_replace("*dmg*", $dmg, $msg);
			$msg = str_replace("*creds*", $cred, $msg);
		}
		
		return $msg;
	}
	
	/**
	 * @HandlesCommand("beer")
	 * @Matches("/^beer$/i")
	 * @Matches("/^beer (\d+)$/i")
	 */
	public function beerCommand($message, $channel, $sender, $sendto, $args) {
		$msg = $this->getFunItem('beer', $sender, $args[1]);
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
	public function brainCommand($message, $channel, $sender, $sendto, $args) {
		$msg = $this->getFunItem('brain', $sender, $args[1]);
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
	public function chuckCommand($message, $channel, $sender, $sendto, $args) {
		$msg = $this->getFunItem('chuck', $sender, $args[1]);
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
	public function cyborCommand($message, $channel, $sender, $sendto, $args) {
		$msg = $this->getFunItem('cybor', $sender, $args[1]);
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
	public function dwightCommand($message, $channel, $sender, $sendto, $args) {
		$msg = $this->getFunItem('dwight', $sender, $args[1]);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("fc")
	 * @Matches("/^fc$/i")
	 * @Matches("/^fc (\d+)$/i")
	 */
	public function fcCommand($message, $channel, $sender, $sendto, $args) {
		$msg = $this->getFunItem('fc', $sender, $args[1]);
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
	public function homerCommand($message, $channel, $sender, $sendto, $args) {
		$msg = $this->getFunItem('homer', $sender, $args[1]);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("pirates")
	 * @Matches("/^pirates$/i")
	 * @Matches("/^pirates (\d+)$/i")
	 *
	 * @author Sicarius Legion of Amra, a Age of Conan Guild on the Hyrkania server
	 * @author Tyrence (RK2), converted to Budabot
	 */
	public function piratesCommand($message, $channel, $sender, $sendto, $args) {
		$msg = $this->getFunItem('pirates', $sender, $args[1]);
		$sendto->reply($msg);
	}
}
