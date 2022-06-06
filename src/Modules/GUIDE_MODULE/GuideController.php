<?php declare(strict_types=1);

namespace Nadybot\Modules\GUIDE_MODULE;

use function Amp\File\filesystem;

use Amp\ByteStream\LineReader;
use Amp\File\File;
use Amp\File\FilesystemException;
use Generator;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandAlias,
	ModuleInstance,
	ParamClass\PFilename,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 * Guides compiled by Plugsz (RK1)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "guides",
		accessLevel: "guest",
		description: "Guides for AO",
		alias: "guide"
	)
]
class GuideController extends ModuleInstance {
	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	private string $path;
	private const FILE_EXT = ".txt";

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
		$this->commandAlias->register($this->moduleName, "guides light", "light");

		$this->path = __DIR__ . "/guides/";
	}

	/** See a list of all the guides in alphabetical order */
	#[NCA\HandlesCommand("guides")]
	public function guidesListCommand(CmdContext $context): Generator {
		/** @var string[] */
		$topicList = [];
		try {
			$fileList = yield filesystem()->listFiles($this->path);
			foreach ($fileList as $fileName) {
				if (!str_ends_with($fileName, self::FILE_EXT)) {
					continue;
				}
				/** @var File */
				$handle = yield filesystem()->openFile($this->path . '/' . $fileName, "r");
				$firstLine = yield (new LineReader($handle))->readLine();
				if ($firstLine === null) {
					continue;
				}
				$firstLine = strip_tags(trim($firstLine));
				$topicList[$firstLine] = basename($fileName, self::FILE_EXT);
			}
		} catch (FilesystemException $e) {
			$msg = "Error reading topics: " . $e->getMessage();
			$context->reply($msg);
			return;
		}

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

	/** See a specific guide */
	#[NCA\HandlesCommand("guides")]
	#[NCA\Help\Epilogue(
		"<header2>Common guides<end>\n\n".
		"To see information about the different breeds:\n".
		"<highlight><tab><symbol>guides breed<end>\n".
		"<highlight><tab><symbol>breed<end>\n\n".
		"To see information about healdelta:\n".
		"<highlight><tab><symbol>guides healdelta<end>\n".
		"<highlight><tab><symbol>healdelta<end>\n\n".
		"To see information about nanodelta:\n".
		"<highlight><tab><symbol>guides nanodelta<end>\n".
		"<highlight><tab><symbol>nanodelta<end>\n\n".
		"To see options for reducing client-side lag:\n".
		"<highlight><tab><symbol>guides lag<end>\n".
		"<highlight><tab><symbol>lag<end>\n\n".
		"To see hidden character stats:\n".
		"<highlight><tab><symbol>guides stats<end>\n".
		"<highlight><tab><symbol>stats<end>\n\n".
		"To see nanos and items that buff abilities and common skills:\n".
		"<highlight><tab><symbol>guides buffs<end>\n".
		"<highlight><tab><symbol>buffs<end>\n\n".
		"To see information about title levels:\n".
		"<highlight><tab><symbol>guides title<end>\n".
		"<highlight><tab><symbol>title<end>\n"
	)]
	public function guidesShowCommand(CmdContext $context, PFilename $guideName): Generator {
		// get the filename and read in the file
		$fileName = strtolower($guideName());
		$file = $this->path . $fileName . self::FILE_EXT;
		try {
			$info = yield filesystem()->read($file);
			$lines = explode("\n", $info);
			$firstLine = preg_replace("/<header>(.+)<end>/", "$1", array_shift($lines));
			$info = trim(implode("\n", $lines));
			$msg = $this->text->makeBlob('Guide for "' . $firstLine . '"', $info, $firstLine);
		} catch (FilesystemException) {
			$msg = "No guide named <highlight>{$fileName}<end> was found.";
		}
		$context->reply($msg);
	}
}
