<?php declare(strict_types=1);

namespace Nadybot\Modules\RECIPE_MODULE;

use Exception;
use JsonException;
use Nadybot\Core\CommandReply;
use Nadybot\Core\DB;
use Nadybot\Core\Text;
use Nadybot\Core\Util;
use Nadybot\Modules\ITEMS_MODULE\ItemsController;
use Nadybot\Modules\ITEMS_MODULE\AODBEntry;

/**
 * @author Tyrence
 *
 * Based on a module written by Captainzero (RK1) of the same name for an earlier version of Budabot
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'recipe',
 *		accessLevel = 'all',
 *		description = 'Search for a recipe',
 *		help        = 'recipe.txt'
 *	)
 */
class RecipeController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public ItemsController $itemsController;

	private string $path;

	protected function parseTextFile(int $id, string $fileName): Recipe {
		$recipe = new Recipe();
		$recipe->id = $id;
		$lines = file($this->path . $fileName);
		$nameLine = trim(array_shift($lines));
		$authorLine = trim(array_shift($lines));
		$recipe->name = (strlen($nameLine) > 6) ? substr($nameLine, 6) : "Unknown";
		$recipe->author = (strlen($authorLine) > 8) ? substr($authorLine, 8) : "Unknown";
		$recipe->recipe = implode("", $lines);
		$recipe->date = filemtime($this->path . $fileName);

		return $recipe;
	}

	protected function parseJSONFile(int $id, string $fileName): Recipe {
		$recipe = new Recipe();
		$recipe->id = $id;
		try {
			$data = json_decode(
				file_get_contents($this->path . $fileName),
				false,
				JSON_THROW_ON_ERROR
			);
		} catch (JsonException $e) {
			throw new Exception("Could not read '$fileName': invalid JSON");
		}
		$recipe->name = $data->name ?? "<unnamed>";
		$recipe->author = $data->author ?? "<unknown>";
		$recipe->date = filemtime($this->path . $fileName);
		/** @var array<string,AODBEntry> */
		$items = [];
		foreach ($data->items as $item) {
			$dbItem = $this->itemsController->findById($item->item_id);
			if ($dbItem === null) {
				throw new Exception("Could not find item '{$item->item_id}'");
			}
			$items[$item->alias] = $dbItem;
			$items[$item->alias]->ql = $item->ql;
		}

		$recipe->recipe = "<font color=#FFFF00>------------------------------</font>\n";
		$recipe->recipe .= "<font color=#FF0000>Ingredients</font>\n";
		$recipe->recipe .= "<font color=#FFFF00>------------------------------</font>\n\n";
		$ingredients = $items;
		foreach ($data->steps as $step) {
			unset($ingredients[$step->result]);
		}
		foreach ($ingredients as $ingredient) {
			$recipe->recipe .= $this->text->makeImage($ingredient->icon) . "\n";
			$recipe->recipe .= $this->text->makeItem($ingredient->lowid, $ingredient->highid, $ingredient->ql, $ingredient->name) . "\n\n\n";
		}

		$recipe->recipe .= "<pagebreak><yellow>------------------------------<end>\n";
		$recipe->recipe .= "<red>Recipe<end>\n";
		$recipe->recipe .= "<yellow>------------------------------<end>\n\n";
		$stepNum = 1;
		foreach ($data->steps as $step) {
			$recipe->recipe .= "<pagebreak><header2>Step {$stepNum}<end>\n";
			$stepNum++;
			$source = $items[$step->source];
			$target = $items[$step->target];
			$result = $items[$step->result];
			$recipe->recipe .= "<tab>".
				$this->text->makeItem($source->lowid, $source->highid, $source->ql, $this->text->makeImage($source->icon)).
				"<tab><img src=tdb://id:GFX_GUI_CONTROLCENTER_BIGARROW_RIGHT_STATE1><tab>".
				$this->text->makeItem($target->lowid, $target->highid, $target->ql, $this->text->makeImage($target->icon)).
				"<tab><img src=tdb://id:GFX_GUI_CONTROLCENTER_BIGARROW_RIGHT_STATE1><tab>".
				$this->text->makeItem($result->lowid, $result->highid, $result->ql, $this->text->makeImage($result->icon)).
				"\n";
			$recipe->recipe .= "<tab>{$source->name} ".
				"<highlight>+<end> {$target->name} <highlight>=<end> ".
				$this->text->makeItem($result->lowid, $result->highid, $result->ql, $result->name).
				"\n";
			if ($step->skills) {
				$recipe->recipe .= "<tab><yellow>Skills: {$step->skills}<end>\n";
			}
			$recipe->recipe .= "\n\n";
		}
		return $recipe;
	}
	
	/** @Setup */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, "recipes");
	}

	/**
	 * @Event("connect")
	 * @Description("Initializes the recipe database")
	 * @DefaultStatus("1")
	 *
	 * This is an Event("connect") instead of Setup since it depends on the items db being loaded
	 */
	public function connectEvent(): void {
		$this->path = __DIR__ . "/recipes/";
		if (($handle = opendir($this->path)) === false) {
			throw new Exception("Could not open '$this->path' for loading recipes");
		}
		/** @var array<string,Recipe> */
		$recipes = [];
		/** @var Recipe[] */
		$allRecipes = $this->db->fetchAll(Recipe::class, "SELECT * FROM `recipes`");
		foreach ($allRecipes as $recipe) {
			$recipes[$recipe->id] = $recipe;
		}
		while (($fileName = readdir($handle)) !== false) {
			if (!preg_match("/(\d+)\.(txt|json)$/", $fileName, $args)
				|| (isset($recipes[$args[1]])
					&& filemtime($this->path . $fileName) === $recipes[$args[1]]->date)
			 ) {
				continue;
			}
			// if file has the correct extension, load recipe into database
			if ($args[2] === 'txt') {
				$recipe = $this->parseTextFile((int)$args[1], $fileName);
			} elseif ($args[2] === 'json') {
				$recipe = $this->parseJSONFile((int)$args[1], $fileName);
			} else {
				continue;
			}
			if (isset($recipes[$args[1]])) {
				$this->db->exec(
					"UPDATE `recipes` SET `name`=?, `author`=?, `recipe`=?, `date`=? ".
					"WHERE `id`=?",
					$recipe->name,
					$recipe->author,
					$recipe->recipe,
					$recipe->date,
					$recipe->id,
				);
			} else {
				$this->db->exec(
					"INSERT INTO `recipes` (`id`, `name`, `author`, `recipe`, `date`) ".
					"VALUES (?, ?, ?, ?, ?)",
					$recipe->id,
					$recipe->name,
					$recipe->author,
					$recipe->recipe,
					$recipe->date
				);
			}
		}
		closedir($handle);
	}
	
	/**
	 * @HandlesCommand("recipe")
	 * @Matches("/^recipe (\d+)$/i")
	 */
	public function recipeShowCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];
		
		/** @var ?Recipe */
		$row = $this->db->fetch(Recipe::class, "SELECT * FROM recipes WHERE id = ?", $id);

		if ($row === null) {
			$msg = "Could not find recipe with id <highlight>$id<end>.";
		} else {
			$msg = $this->createRecipeBlob($row);
		}
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("recipe")
	 * @Matches("/^recipe (.+)$/i")
	 */
	public function recipeSearchCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (preg_match('|<a href="itemref://(\d+)/(\d+)/(\d+)">([^<]+)</a>|', $args[1], $matches)) {
			$lowId = (int)$matches[1];
			$search = $matches[4];
			
			/** @var Recipe[] */
			$data = $this->db->fetchAll(
				Recipe::class,
				"SELECT * FROM recipes WHERE recipe LIKE ? OR recipe LIKE ? ORDER BY name ASC",
				"%{$search}%",
				"%{$lowId}%"
			);
		} else {
			$search = $args[1];
			
			[$query, $queryParams] = $this->util->generateQueryFromParams(explode(" ", $search), "recipe");
			/** @var Recipe[] */
			$data = $this->db->fetchAll(
				Recipe::class,
				"SELECT * FROM recipes WHERE $query ORDER BY name ASC",
				...$queryParams
			);
		}
		
		$count = count($data);

		if ($count === 0) {
			$msg = "Could not find any recipes matching your search criteria.";
			$sendto->reply($msg);
			return;
		}
		if ($count === 1) {
			$msg = $this->createRecipeBlob($data[0]);
			$sendto->reply($msg);
			return;
		}
		$blob = "<header2>Recipes containing \"{$search}\"<end>\n";
		foreach ($data as $row) {
			$blob .= "<tab>" . $this->text->makeChatcmd($row->name, "/tell <myname> recipe $row->id") . "\n";
		}

		$msg = $this->text->makeBlob("Recipes matching '$search' ($count)", $blob);

		$sendto->reply($msg);
	}
	
	public function formatRecipeText(string $input): string {
		$input = str_replace("\\n", "\n", $input);
		$input = preg_replace_callback('/#L "([^"]+)" "([0-9]+)"/', [$this, 'replaceItem'], $input);
		$input = preg_replace('/#L "([^"]+)" "([^"]+)"/', "<a href='chatcmd://\\2'>\\1</a>", $input);
		
		// we can't use <myname> in the sql since that will get converted on load,
		// and we need to wait to convert until display time due to the possibility
		// of several bots sharing the same db
		$input = str_replace("{myname}", "<myname>", $input);

		return $input;
	}
	
	/**
	 * @return string[]
	 */
	public function createRecipeBlob(Recipe $row): array {
		$recipe_name = $row->name;
		$author = empty($row->author) ? "Unknown" : $row->author;

		$recipeText = "Recipe Id: <highlight>$row->id<end>\n";
		$recipeText .= "Author: <highlight>$author<end>\n\n";
		$recipeText .= $this->formatRecipeText($row->recipe);

		return (array)$this->text->makeBlob("Recipe for $recipe_name", $recipeText);
	}

	private function replaceItem(array $arr): string {
		$id = (int)$arr[2];
		$row = $this->itemsController->findById($id);
		if ($row !== null) {
			$output = $this->text->makeItem($row->lowid, $row->highid, $row->highql, $row->name);
		} else {
			$output = "#L \"{$arr[1]}\" \"/tell <myname> itemid {$arr[2]}\"";
		}
		return $output;
	}
}
