<?php declare(strict_types=1);

namespace Nadybot\Modules\GUIDE_MODULE;

use Nadybot\Core\{
	CommandAlias,
	CommandReply,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 *
 * Guides compiled by Plugsz (RK1)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'guides',
 *		alias       = 'guide',
 *		accessLevel = 'all',
 *		description = 'Guides for AO',
 *		help        = 'guides.txt'
 *	)
 */
class GuideController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public Util $util;
	
	/** @Inject */
	public CommandAlias $commandAlias;
	
	private string $path;
	private const FILE_EXT = ".txt";
	
	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup() {
		$this->commandAlias->register($this->moduleName, "guides healdelta", "healdelta");
		$this->commandAlias->register($this->moduleName, "guides lag", "lag");
		$this->commandAlias->register($this->moduleName, "guides nanodelta", "nanodelta");
		$this->commandAlias->register($this->moduleName, "guides stats", "stats");
		$this->commandAlias->register($this->moduleName, "aou 11", "title");
		$this->commandAlias->register($this->moduleName, "guides doja", "doja");
		$this->commandAlias->register($this->moduleName, "guides gos", "gos");
		$this->commandAlias->register($this->moduleName, "guides gos", "faction");
		$this->commandAlias->register($this->moduleName, "guides gos", "guardian");
		$this->commandAlias->register($this->moduleName, "guides adminhelp", "adminhelp");
		$this->commandAlias->register($this->moduleName, "guides light", "light");

		$this->path = __DIR__ . "/guides/";
	}
	
	/**
	 * @HandlesCommand("guides")
	 * @Matches("/^guides$/i")
	 */
	public function guidesListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (($handle = opendir($this->path)) === false) {
			$msg = "Error reading topics.";
			$sendto->reply($msg);
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
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("guides")
	 * @Matches("/^guides ([a-z0-9_-]+)$/i")
	 */
	public function guidesShowCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		// get the filename and read in the file
		$fileName = strtolower($args[1]);
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
		$sendto->reply($msg);
	}
}
