<?php declare(strict_types=1);

namespace Nadybot\Modules\GUIDE_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	CmdContext,
	CommandAlias,
	Text,
	Util,
};
use Nadybot\Core\ParamClass\PFilename;

/**
 * @author Tyrence (RK2)
 * Guides compiled by Plugsz (RK1)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "guides",
		accessLevel: "all",
		description: "Guides for AO",
		help: "guides.txt",
		alias: "guide"
	)
]
class GuideController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	private string $path;
	private const FILE_EXT = ".txt";

	/**
	 * This handler is called on bot startup.
	 */
	#[NCA\Setup]
	public function setup(): void {
		$this->commandAlias->register($this->moduleName, "guides healdelta", "healdelta");
		$this->commandAlias->register($this->moduleName, "guides lag", "lag");
		$this->commandAlias->register($this->moduleName, "guides nanodelta", "nanodelta");
		$this->commandAlias->register($this->moduleName, "guides stats", "stats");
		$this->commandAlias->register($this->moduleName, "aou 11", "title");
		$this->commandAlias->register($this->moduleName, "guides breed", "breed");
		$this->commandAlias->register($this->moduleName, "guides breed", "breeds");
		$this->commandAlias->register($this->moduleName, "guides doja", "doja");
		$this->commandAlias->register($this->moduleName, "guides gos", "gos");
		$this->commandAlias->register($this->moduleName, "guides gos", "faction");
		$this->commandAlias->register($this->moduleName, "guides gos", "guardian");
		$this->commandAlias->register($this->moduleName, "guides adminhelp", "adminhelp");
		$this->commandAlias->register($this->moduleName, "guides light", "light");

		$this->path = __DIR__ . "/guides/";
	}

	#[NCA\HandlesCommand("guides")]
	public function guidesListCommand(CmdContext $context): void {
		if (($handle = opendir($this->path)) === false) {
			$msg = "Error reading topics.";
			$context->reply($msg);
			return;
		}
		/** @var string[] */
		$topicList = [];

		while (($fileName = readdir($handle)) !== false) {
			// if file has the correct extension, it's a topic file
			if ($this->util->endsWith($fileName, self::FILE_EXT)) {
				$firstLine = strip_tags(trim(file($this->path . '/' . $fileName)[0]));
				$topicList[$firstLine] = basename($fileName, self::FILE_EXT);
			}
		}

		closedir($handle);

		ksort($topicList);

		$linkContents = "<header2>Available guides<end>\n";
		foreach ($topicList as $topic => $file) {
			$linkContents .= "<tab>".
				$this->text->makeChatcmd($topic, "/tell <myname> guides $file") . "\n";
		}

		if (count($topicList)) {
			$msg = $this->text->makeBlob('Topics (' . count($topicList) . ')', $linkContents);
		} else {
			$msg = "No topics available.";
		}
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("guides")]
	public function guidesShowCommand(CmdContext $context, PFilename $fileName): void {
		// get the filename and read in the file
		$fileName = strtolower($fileName());
		$file = $this->path . $fileName . self::FILE_EXT;
		$info = @file_get_contents($file);

		if ($info === false) {
			$msg = "No guide named <highlight>$fileName<end> was found.";
		} else {
			$lines = explode("\n", $info);
			$firstLine = preg_replace("/<header>(.+)<end>/", "$1", array_shift($lines));
			$info = trim(implode("\n", $lines));
			$msg = $this->text->makeBlob('Guide for "' . $firstLine . '"', $info, $firstLine);
		}
		$context->reply($msg);
	}
}
