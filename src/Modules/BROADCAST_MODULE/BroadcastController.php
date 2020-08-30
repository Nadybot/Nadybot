<?php declare(strict_types=1);

namespace Nadybot\Modules\BROADCAST_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	Event,
	Nadybot,
	SettingManager,
	StopExecutionException,
	Text,
	Util,
};
use Nadybot\Core\Modules\LIMITS\RateIgnoreController;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'broadcast',
 *		accessLevel = 'mod',
 *		description = 'View/edit the broadcast bots list',
 *		help        = 'broadcast.txt'
 *	)
 */
class BroadcastController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Inject */
	public DB $db;
	
	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public SettingManager $settingManager;
	
	/** @Inject */
	public RateIgnoreController $rateIgnoreController;
	
	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public Util $util;
	
	/** @var array<string,Broadcast> */
	private array $broadcastList = [];
	
	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup() {
		$this->db->loadSQLFile($this->moduleName, 'broadcast');
		
		$this->loadBroadcastListIntoMemory();
		
		$this->settingManager->add(
			$this->moduleName,
			"broadcast_to_guild",
			"Send broadcast message to guild channel",
			"edit",
			"options",
			"1",
			"true;false",
			"1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"broadcast_to_privchan",
			"Send broadcast message to private channel",
			"edit",
			"options",
			"0",
			"true;false",
			"1;0"
		);
	}
	
	private function loadBroadcastListIntoMemory(): void {
		//Upload broadcast bots to memory
		/** @var Broadcast[] */
		$data = $this->db->fetchAll(Broadcast::class, "SELECT * FROM broadcast_<myname>");
		$this->broadcastList = [];
		foreach ($data as $row) {
			$this->broadcastList[$row->name] = $row;
		}
	}
	
	/**
	 * @HandlesCommand("broadcast")
	 * @Matches("/^broadcast$/i")
	 */
	public function broadcastListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$blob = '';

		$sql = "SELECT * FROM broadcast_<myname> ORDER BY dt DESC";
		/** @var Broadcast[] */
		$data = $this->db->fetchAll(Broadcast::class, $sql);
		if (count($data) === 0) {
			$msg = "No bots are on the broadcast list.";
			$sendto->reply($msg);
			return;
		}
		foreach ($data as $row) {
			$remove = $this->text->makeChatcmd('Remove', "/tell <myname> <symbol>broadcast rem $row->name");
			$dt = $this->util->date($row->dt);
			$blob .= "<highlight>{$row->name}<end> [added by {$row->added_by}] {$dt} {$remove}\n";
		}

		$msg = $this->text->makeBlob('Broadcast Bots', $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("broadcast")
	 * @Matches("/^broadcast add (.+)$/i")
	 */
	public function broadcastAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));

		$charid = $this->chatBot->get_uid($name);
		if ($charid === false) {
			$sendto->reply("'$name' is not a valid character name.");
			return;
		}

		if (isset($this->broadcastList[$name])) {
			$sendto->reply("'$name' is already on the broadcast bot list.");
			return;
		}

		$this->db->exec(
			"INSERT INTO broadcast_<myname> (`name`, `added_by`, `dt`) VALUES (?, ?, ?)",
			$name,
			$sender,
			time()
		);
		$msg = "Broadcast bot <highlight>{$name}<end> added successfully.";

		// reload broadcast bot list
		$this->loadBroadcastListIntoMemory();

		$this->rateIgnoreController->add($name, $sender . " (bot)");

		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("broadcast")
	 * @Matches("/^broadcast (rem|remove) (.+)$/i")
	 */
	public function broadcastRemoveCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[2]));

		if (!isset($this->broadcastList[$name])) {
			$sendto->reply("'$name' is not on the broadcast bot list.");
			return;
		}

		$this->db->exec("DELETE FROM broadcast_<myname> WHERE name = ?", $name);
		$msg = "Broadcast bot <highlight>{$name}<end> removed successfully.";

		// reload broadcast bot list
		$this->loadBroadcastListIntoMemory();

		$this->rateIgnoreController->remove($name);

		$sendto->reply($msg);
	}

	/**
	 * @Event("msg")
	 * @Description("Relays incoming messages to the guild/private channel")
	 */
	public function incomingMessageEvent(Event $eventObj): void {
		if ($this->isValidBroadcastSender($eventObj->sender)) {
			$this->processIncomingMessage($eventObj->sender, $eventObj->message);
			
			// keeps the bot from sending a message back
			throw new StopExecutionException();
		}
	}
	
	public function isValidBroadcastSender(string $sender): bool {
		return isset($this->broadcastList[$sender]);
	}

	public function processIncomingMessage(string $sender, string $message): void {
		$msg = "[$sender]: $message";

		if ($this->settingManager->getBool('broadcast_to_guild')) {
			$this->chatBot->sendGuild($msg, true);
		}
		if ($this->settingManager->getBool('broadcast_to_privchan')) {
			$this->chatBot->sendPrivate($msg, true);
		}
	}
}
