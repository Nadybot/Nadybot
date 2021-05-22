<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	CommandReply,
	DB,
	SettingManager,
	Text,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'links',
 *		accessLevel = 'guild',
 *		description = 'Displays, adds, or removes links from the org link list',
 *		help        = 'links.txt'
 *	)
 */
class LinksController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Setup */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations/Links");
		$this->settingManager->add(
			$this->moduleName,
			'showfullurls',
			'Enable full urls in the link list output',
			'edit',
			"options",
			"0",
			"true;false",
			"1;0"
		);
	}

	/**
	 * @HandlesCommand("links")
	 * @Matches("/^links$/i")
	 */
	public function linksListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var Collection<Link> */
		$links = $this->db->table("links")
			->orderBy("name")
			->asObj(Link::class);
		if ($links->count() === 0) {
			$msg = "No links found.";
			$sendto->reply($msg);
			return;
		}

		$blob = "<header2>All my links<end>\n";
		foreach ($links as $link) {
			$remove = $this->text->makeChatcmd('Remove', "/tell <myname> links rem $link->id");
			if ($this->settingManager->getBool('showfullurls')) {
				$website = $this->text->makeChatcmd($link->website, "/start $link->website");
			} else {
				$website = $this->text->makeChatcmd('[Link]', "/start $link->website");
			}
			$blob .= "<tab>$website <highlight>$link->comments<end> [$link->name] $remove\n";
		}

		$msg = $this->text->makeBlob('Links', $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("links")
	 * @Matches("/^links add ([^ ]+) (.+)$/i")
	 */
	public function linksAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$website = htmlspecialchars($args[1]);
		$comments = $args[2];
		if (filter_var($website, FILTER_VALIDATE_URL) === false) {
			$msg = "<highlight>$website<end> is not a valid URL.";
			$sendto->reply($msg);
			return;
		}

		$this->db->table("links")
			->insert([
				"name" => $sender,
				"website" => $website,
				"comments" => $comments,
				"dt" => time(),
			]);
		$msg = "Link added successfully.";
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("links")
	 * @Matches("/^links rem (\d+)$/i")
	 */
	public function linksRemoveCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];

		/** @var ?Link */
		$obj = $this->db->table("links")
			->where("id", $id)
			->asObj(Link::class)
			->first();
		if ($obj === null) {
			$msg = "Link with ID <highlight>$id<end> could not be found.";
		} elseif ($obj->name == $sender || $this->accessManager->compareCharacterAccessLevels($sender, $obj->name) > 0) {
			$this->db->table("links")->delete($id);
			$msg = "Link with ID <highlight>$id<end> deleted successfully.";
		} else {
			$msg = "You do not have permission to delete links added by <highlight>{$obj->name}<end>";
		}
		$sendto->reply($msg);
	}
}
