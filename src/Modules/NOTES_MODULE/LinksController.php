<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	DB,
	Instance,
	SettingManager,
	Text,
};
use Nadybot\Core\ParamClass\PRemove;
use Nadybot\Core\ParamClass\PWord;

/**
 * @author Tyrence (RK2)
 * Commands this class contains:
 */
#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/Links"),
	NCA\DefineCommand(
		command: "links",
		accessLevel: "guild",
		description: "Displays, adds, or removes links from the org link list",
		help: "links.txt"
	)
]
class LinksController extends Instance {

		#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Setup]
	public function setup(): void {
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'showfullurls',
			description: 'Enable full urls in the link list output',
			mode: 'edit',
			type: "options",
			value: "0",
			options: "true;false",
			intoptions: "1;0"
		);
	}

	#[NCA\HandlesCommand("links")]
	public function linksListCommand(CmdContext $context): void {
		/** @var Collection<Link> */
		$links = $this->db->table("links")
			->orderBy("name")
			->asObj(Link::class);
		if ($links->count() === 0) {
			$msg = "No links found.";
			$context->reply($msg);
			return;
		}

		$blob = "<header2>All my links<end>\n";
		foreach ($links as $link) {
			$remove = $this->text->makeChatcmd('remove', "/tell <myname> links rem {$link->id}");
			if ($this->settingManager->getBool('showfullurls')) {
				$website = $this->text->makeChatcmd($link->website, "/start {$link->website}");
			} else {
				$website = "[" . $this->text->makeChatcmd('visit', "/start {$link->website}") . "]";
			}
			$blob .= "<tab>{$website} <highlight>{$link->comments}<end> (by {$link->name}) [{$remove}]\n";
		}

		$msg = $this->text->makeBlob('Links', $blob);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("links")]
	public function linksAddCommand(CmdContext $context, #[NCA\Str("add")] string $action, PWord $url, string $comments): void {
		$website = htmlspecialchars($url());
		if (filter_var($website, FILTER_VALIDATE_URL) === false) {
			$msg = "<highlight>$website<end> is not a valid URL.";
			$context->reply($msg);
			return;
		}

		$this->db->table("links")
			->insert([
				"name" => $context->char->name,
				"website" => $website,
				"comments" => $comments,
				"dt" => time(),
			]);
		$msg = "Link added successfully.";
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("links")]
	public function linksRemoveCommand(CmdContext $context, PRemove $action, int $id): void {
		/** @var ?Link */
		$obj = $this->db->table("links")
			->where("id", $id)
			->asObj(Link::class)
			->first();
		if ($obj === null) {
			$msg = "Link with ID <highlight>{$id}<end> could not be found.";
		} elseif ($obj->name == $context->char->name
			|| $this->accessManager->compareCharacterAccessLevels($context->char->name, $obj->name) > 0) {
			$this->db->table("links")->delete($id);
			$msg = "Link with ID <highlight>{$id}<end> deleted successfully.";
		} else {
			$msg = "You do not have permission to delete links added by <highlight>{$obj->name}<end>";
		}
		$context->reply($msg);
	}
}
