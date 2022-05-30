<?php declare(strict_types=1);

namespace Nadybot\Modules\GUIDE_MODULE;

use Amp\Cache\FileCache;
use Amp\Failure;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Promise;
use Amp\Success;
use Amp\Sync\LocalKeyedMutex;
use DOMDocument;
use DOMElement;
use Exception;
use Generator;
use Throwable;
use Nadybot\Core\{
	Attributes as NCA,
	CacheManager,
	CmdContext,
	ConfigFile,
	ModuleInstance,
	Text,
};
use Nadybot\Modules\ITEMS_MODULE\{
	AODBEntry,
	ItemsController,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "aou",
		accessLevel: "guest",
		description: "Search for or view a guide from AO-Universe",
	)
]
class AOUController extends ModuleInstance {
	#[NCA\Inject]
	public HttpClientBuilder $builder;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public ItemsController $itemsController;

	#[NCA\Inject]
	public CacheManager $cacheManager;

	public const AOU_URL = "https://www.ao-universe.com/mobile/parser.php?bot=nadybot";

	public function isValidXML(?string $data): bool {
		if (!isset($data) || !strlen($data)) {
			return false;
		}
		/** @phpstan-var non-empty-string $data */
		try {
			$dom = new DOMDocument();
			return $dom->loadXML($data) !== false;
		} catch (Throwable $e) {
			return false;
		}
	}

	/**
	 * View a specific guide on AO-Universe
	 */
	#[NCA\HandlesCommand("aou")]
	public function aouView(CmdContext $context, int $guideId): Generator {
		$params = [
			'mode' => 'view',
			'id' => $guideId
		];
		$cache = new FileCache(
			$this->config->cacheFolder . '/guide',
			new LocalKeyedMutex()
		);
		$cacheKey = (string)$guideId;
		$body = yield $cache->get($cacheKey);
		if ($body === null) {
			$client = $this->builder->build();
			/** @var Response */
			$response = yield $client->request(new Request(
				self::AOU_URL . '&' . http_build_query($params)
			));
			$body = yield $response->getBody()->buffer();
			if ($response->getStatus() !== 200 || $body === '' || !$this->isValidXML($body)) {
				$msg = "An error occurred while trying to retrieve AOU guide with id <highlight>$guideId<end>.";
				$context->reply($msg);
			}
			$cache->set($cacheKey, $body, 3600*24);
		}
		try {
			/** @phpstan-var non-empty-string $body */
			$msg = yield $this->renderAOUGuide($body, $guideId);
		} catch (Exception $e) {
			$context->reply("Error with AOU guide <highlight>{$guideId}<end>: ".
				$e->getMessage());
			return;
		}
		$context->reply($msg);
	}

	/**
	 * @phpstan-param non-empty-string $body
	 * @return Promise<string|string[]>
	 */
	public function renderAOUGuide(string $body, int $guideId): Promise {
		$dom = new DOMDocument();
		$dom->loadXML($body);

		if ($dom->getElementsByTagName('error')->length > 0) {
			return new Failure(new Exception(
				$dom->getElementsByTagName('text')->item(0)->nodeValue
			));
		}

		$content = $dom->getElementsByTagName('content')->item(0);
		if ($content == null || !($content instanceof DOMElement)) {
			return new Failure(new Exception("Invalid XML structure"));
		}
		$title = $content->getElementsByTagName('name')->item(0)->nodeValue;

		$blob = $this->text->makeChatcmd("Guide on AO-Universe", "/start https://www.ao-universe.com/main.php?site=knowledge&id={$guideId}") . "\n\n";

		$blob .= "Updated: <highlight>" . $content->getElementsByTagName('update')->item(0)->nodeValue . "<end>\n";
		$blob .= "Profession: <highlight>" . $content->getElementsByTagName('class')->item(0)->nodeValue . "<end>\n";
		$blob .= "Faction: <highlight>" . $content->getElementsByTagName('faction')->item(0)->nodeValue . "<end>\n";
		$blob .= "Level: <highlight>" . $content->getElementsByTagName('level')->item(0)->nodeValue . "<end>\n";
		$blob .= "Author: <highlight>" . $this->processInput($content->getElementsByTagName('author')->item(0)->nodeValue) . "<end>\n\n";

		$blob .= $this->processInput($content->getElementsByTagName('text')->item(0)->nodeValue);

		$blob .= "\n\n<i>Powered by " . $this->text->makeChatcmd("AO-Universe", "/start https://www.ao-universe.com") . "</i>";

		$msg = $this->text->makeBlob($title, $blob);
		return new Success($msg);
	}

	/**
	 * Search for an AO-Universe guide and include guides that have the search terms in the guide text
	 *
	 * Note: this will search the name, category, and description as well as the guide body for matches.
	 */
	#[NCA\HandlesCommand("aou")]
	public function aouAllSearch(CmdContext $context, #[NCA\Str("all")] string $action, string $search): Generator {
		$msg = yield from $this->searchAndGetAOUGuide($search, true);
		$context->reply($msg);
	}

	/**
	 * Search for an AO-Universe guide
	 *
	 * Note: this will search the name, category, and description for matches
	 */
	#[NCA\HandlesCommand("aou")]
	public function aouSearch(CmdContext $context, string $search): Generator {
		$msg = yield from $this->searchAndGetAOUGuide($search, false);
		$context->reply($msg);
	}

	private function searchAndGetAOUGuide(string $search, bool $searchGuideText): Generator {
		$params = [
			'mode' => 'search',
			'search' => $search
		];
		$client = $this->builder->build();
		/** @var Response */
		$response = yield $client->request(new Request(
			self::AOU_URL . '&' . http_build_query($params)
		));
		$body = yield $response->getBody()->buffer();
		if ($response->getStatus() !== 200 || $body === '' || !$this->isValidXML($body)) {
			return new Success("An error occurred while trying to search the AOU guides.");
		}
		/** @phpstan-var non-empty-string $body */
		return yield $this->renderAOUGuideList($body, $searchGuideText, $search);
	}

	/**
	 * @phpstan-param non-empty-string $body
	 * @return Promise<string|string[]>
	 */
	private function renderAOUGuideList(string $body, bool $searchGuideText, string $search): Promise {
		$searchTerms = explode(" ", $search);

		$dom = new DOMDocument();
		$dom->loadXML($body);

		$sections = $dom->getElementsByTagName('section');
		$blob = '';
		$count = 0;
		foreach ($sections as $section) {
			$category = $this->getSearchResultCategory($section);

			$guides = $section->getElementsByTagName('guide');
			$tempBlob = '';
			$found = false;
			foreach ($guides as $guide) {
				$guideObj = $this->getGuideObject($guide);
				// since aou returns guides that have keywords in the guide body, we filter the results again
				// to only include guides that contain the keywords in the category, name, or description
				if ($searchGuideText || $this->striposarray($category . ' ' . $guideObj->name . ' ' . $guideObj->description, $searchTerms)) {
					$count++;
					$tempBlob .= '  ' . $this->text->makeChatcmd("$guideObj->name", "/tell <myname> aou $guideObj->id") . " - " . $guideObj->description . "\n";
					$found = true;
				}
			}

			if ($found) {
				$blob .= "<pagebreak><header2>" . $category . "<end>\n";
				$blob .= $tempBlob;
				$blob .= "\n";
			}
		}

		$blob .= "\n<i>Powered by " . $this->text->makeChatcmd("AO-Universe.com", "/start https://www.ao-universe.com") . "</i>";

		if ($count > 0) {
			if ($searchGuideText) {
				$title = "All AO-Universe Guides containing '$search' ($count)";
			} else {
				$title = "AO-Universe Guides containing '$search' ($count)";
			}
			$msg = $this->text->makeBlob($title, $blob);
		} else {
			$msg = "Could not find any guides containing: '$search'.";
			if (!$searchGuideText) {
				$msg .= " Try including all results with <highlight>!aou all $search<end>.";
			}
		}
		return new Success($msg);
	}

	/**
	 * @param string $haystack
	 * @param string[] $needles
	 * @return bool
	 */
	private function striposarray(string $haystack, array $needles): bool {
		foreach ($needles as $needle) {
			if (stripos($haystack, $needle) === false) {
				return false;
			}
		}
		return true;
	}

	private function getSearchResultCategory(DOMElement $section): string {
		$folders = $section->getElementsByTagName('folder');
		$output = [];
		foreach ($folders as $folder) {
			$output []= $folder->getElementsByTagName('name')->item(0)->nodeValue;
		}
		return implode(" - ", array_reverse($output));
	}

	private function getGuideObject(DOMElement $guide): AOUGuide {
		$obj = new AOUGuide();
		$obj->id = (int)$guide->getElementsByTagName('id')->item(0)->nodeValue;
		$obj->name = $guide->getElementsByTagName('name')->item(0)->nodeValue;
		$obj->description = $guide->getElementsByTagName('desc')->item(0)->nodeValue;
		return $obj;
	}

	/** @param string[] $arr */
	private function replaceItem(array $arr): string {
		$type = $arr[1];
		$id = (int)$arr[3];

		$output = '';

		$row = $this->itemsController->findById($id);
		if ($row !== null) {
			$output = $this->generateItemMarkup($type, $row);
		} else {
			$output = (string)$id;
		}
		return $output;
	}

	/** @param string[] $arr */
	private function replaceWaypoint(array $arr): string {
		$label = $arr[2];
		$params = explode(" ", $arr[1]);
		$wp = [];
		foreach ($params as $param) {
			[$name, $value] = explode("=", $param);
			$wp[$name] = $value;
		}

		return $this->text->makeChatcmd($label . " ({$wp['x']}x{$wp['y']})", "/waypoint {$wp['x']} {$wp['y']} {$wp['pf']}");
	}

	/** @param string[] $arr */
	private function replaceGuideLinks(array $arr): string {
		$url = $arr[2];
		$label = $arr[3];

		if (preg_match("/pid=(\\d+)/", $url, $idArray)) {
			return $this->text->makeChatcmd($label, "/tell <myname> aou " . $idArray[1]);
		}
		return $this->text->makeChatcmd($label, "/start $url");
	}

	private function processInput(string $input): string {
		$input = preg_replace("/(\[size.+?\])\[b\]/i", "[b]$1", $input);
		$input = preg_replace("/(\[color.+?\])\[b\]/i", "[b]$1", $input);
		$input = preg_replace_callback("/\[(item|itemname|itemicon)( nolink)?\](\d+)\[\/(item|itemname|itemicon)\]/i", [$this, 'replaceItem'], $input);
		$input = preg_replace_callback("/\[waypoint ([^\]]+)\]([^\]]*)\[\/waypoint\]/", [$this, 'replaceWaypoint'], $input);
		$input = preg_replace_callback("/\[(localurl|url)=([^ \]]+)\]([^\[]+)\[\/(localurl|url)\]/", [$this, 'replaceGuideLinks'], $input);
		$input = preg_replace("/\[img\](.*?)\[\/img\]/", "-image-", $input);
		$input = preg_replace("/\[color=#([0-9A-F]+)\]/", "<font color=#$1>", $input);
		$input = preg_replace("/\[color=(.+?)\]/", "<$1>", $input);
		$input = preg_replace("/\[\/color\]/", "<end>", $input);
		$input = str_replace(["[center]", "[/center]"], ["<center>", "</center>"], $input);
		$input = str_replace(["[i]", "[/i]"], ["<i>", "</i>"], $input);
		$input = str_replace(["[b]", "[/b]"], ["<highlight>", "<end>"], $input);

		$pattern = "/(\[.+?\])/";
		$matches = \Safe\preg_split($pattern, $input, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

		$output = '';
		foreach ($matches as $match) {
			$output .= $this->processTag($match);
		}

		return $output;
	}

	private function processTag(string $tag): string {
		switch ($tag) {
			case "[ts_ts]":
				return " + ";
			case "[ts_ts2]":
				return " = ";
			case "[cttd]":
				return " | ";
			case "[cttr]":
			case "[br]":
				return "\n";
		}

		if ($tag[0] === '[') {
			return "";
		}

		return $tag;
	}

	private function generateItemMarkup(string $type, AODBEntry $obj): string {
		$output = '';
		if ($type === "item" || $type === "itemicon") {
			$output .= $this->text->makeImage($obj->icon);
		}

		if ($type === "item" || $type === "itemname") {
			$output .= $this->text->makeItem($obj->lowid, $obj->highid, $obj->highql, $obj->name);
		}

		return $output;
	}
}
