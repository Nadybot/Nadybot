<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\COLORS;

use function Safe\json_decode;

use Amp\File\Filesystem;
use EventSauce\ObjectHydrator\ObjectMapperUsingReflection;
use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	Attributes\Setting\Color,
	CmdContext,
	DB,
	DBSchema\RouteHopColor,
	MessageHub,
	ModuleInstance,
	Modules\MESSAGES\MessageHubController,
	ParamClass\PFilename,
	SettingManager,
	Text,
};

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "theme",
		description: "View installed color theme",
		accessLevel: "member",
		alias: "themes",
	),
	NCA\DefineCommand(
		command: "theme change",
		description: "Change the bot's color theme",
	)
]
class ColorsController extends ModuleInstance {
	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Filesystem $fs;

	#[NCA\Inject]
	public MessageHubController $msgHubCtrl;

	#[NCA\Inject]
	public MessageHub $msgHub;

	/** default guild color */
	#[Color] public string $defaultGuildColor = "#89D2E8";

	/** default private channel color */
	#[Color] public string $defaultPrivColor = "#89D2E8";

	/** default window color */
	#[Color] public string $defaultWindowColor = "#89D2E8";

	/** default tell color */
	#[Color] public string $defaultTellColor = "#89D2E8";

	/** default routed system color */
	#[Color] public string $defaultRoutedSysColor = "#89D2E8";

	/** default highlight color */
	#[Color] public string $defaultHighlightColor = "#FFFFFF";

	/** default header color */
	#[Color] public string $defaultHeaderColor = "#FFFF00";

	/** default header2 color */
	#[Color] public string $defaultHeader2Color = "#FCA712";

	/** default clan color */
	#[Color] public string $defaultClanColor = "#F79410";

	/** default omni color */
	#[Color] public string $defaultOmniColor = "#00FFFF";

	/** default neut color */
	#[Color] public string $defaultNeutColor = "#E6E1A6";

	/** default unknown color */
	#[Color] public string $defaultUnknownColor = "#FF0000";

	/** default enabled/online/join color */
	#[Color] public string $defaultEnabledColor = "#00DE42";

	/** default disabled/offline/leave color */
	#[Color] public string $defaultDisabledColor = "#FF6666";

	/** Where to search for themes - separate with colons for multiple paths */
	#[NCA\Setting\Text(options: ["./Themes"])] public string $themePath = "./Themes";

	/** Get a list of color themes for the bot */
	#[NCA\HandlesCommand("theme")]
	public function cmdThemeList(CmdContext $context): void {
		$themes = $this->getThemeList();
		$blobs = [];
		foreach ($themes as $theme) {
			$link = "[" . $this->text->makeChatcmd(
				"apply",
				"/tell <myname> theme apply {$theme->name}"
			) . "]";
			if ($this->isThemeActive($theme)) {
				$link = "<black>[apply]<end>";
			}
			$blobs []= "{$link} <highlight>{$theme->name}<end>: {$theme->description}";
		}
		$blob = join("\n<pagebreak>", $blobs);
		$count = count($themes);
		$msg = $this->text->makeBlob("Themes ({$count})", $blob);
		$context->reply($msg);
	}

	/** Get a preview of all color themes for the bot */
	#[NCA\HandlesCommand("theme")]
	public function cmdThemePreview(
		CmdContext $context,
		#[NCA\Str("preview")]
		string $action,
	): void {
		$themes = $this->getThemeList();
		$blobs = [];
		foreach ($themes as $theme) {
			$link = $this->text->makeChatcmd(
				"apply",
				"/tell <myname> theme apply {$theme->name}"
			);
			$blobs []= "[{$link}] <highlight>{$theme->name}<end>: {$theme->description}\n".
				"<tab><grey>|<end>\n<tab><grey>|<end> " . implode("\n<tab><grey>|<end> ", explode("\n", $this->getThemePreview($theme)));
		}
		$blob = join("\n\n<pagebreak>", $blobs);
		$count = count($themes);
		$msg = $this->text->makeBlob("Themes ({$count})", $blob);
		$context->reply($msg);
	}

	/** Apply a color theme for the bot */
	#[NCA\HandlesCommand("theme change")]
	public function cmdApplyTheme(
		CmdContext $context,
		#[NCA\Str("apply")]
		string $action,
		PFilename $themeName
	): void {
		$paths = explode(":", $this->themePath);
		$files = new Collection();
		foreach ($paths as $path) {
			$fileList = $this->fs->listFiles(__DIR__ . "/" . $path);
			foreach ($fileList as $fileName) {
				if (!str_ends_with($fileName, ".json")) {
					continue;
				}
				$files->push(__DIR__ . "/{$path}/{$fileName}");
			}
		}
		$files = $files->filter(fn (string $path): bool => basename($path, ".json") === $themeName());
		try {
			$theme = $this->loadTheme($files->firstOrFail());
			if (!isset($theme)) {
				throw new Exception("Theme not found.");
			}
		} catch (Exception $e) {
			$context->reply("No theme <highlight>{$themeName}<end> found.");
			return;
		}
		$this->applyTheme($theme);
		$context->reply("Theme changed to <highlight>{$themeName}<end>.");
	}

	/** @return Theme[] */
	public function getThemeList(): array {
		$paths = explode(":", $this->themePath);
		$themes = new Collection();
		foreach ($paths as $path) {
			$fileList = $this->fs->listFiles(__DIR__ . "/" . $path);
			foreach ($fileList as $fileName) {
				if (!str_ends_with($fileName, ".json")) {
					continue;
				}
				$themes->push(
					$this->loadTheme(__DIR__ . "/{$path}/{$fileName}")
				);
			}
		}
		$themes = $themes->filter()->sortBy("name")->values();
		return $themes->toArray();
	}

	/** Load a theme from a file and return the parsed theme or null */
	public function loadTheme(string $filename): ?Theme {
		try {
			$json = $this->fs->read($filename);
			$data = json_decode($json, true);
		} catch (Exception) {
			return null;
		}
		$data["name"] = basename($filename, ".json");
		$mapper = new ObjectMapperUsingReflection();
		return $mapper->hydrateObject(Theme::class, $data);
	}

	/** Activate all colors of the given theme */
	public function applyTheme(Theme $theme): void {
		$attributes = $this->getColorAttributes();
		foreach ($attributes as $attr) {
			$value = $theme->{$attr};
			if (!isset($value)) {
				continue;
			}
			$setting = "default_{$attr}";
			if (preg_match("/^#([0-9a-f]{6})$/i", $value)) {
				$value = strtoupper($value);
				$value = "<font color='{$value}'>";
			}
			$this->settingManager->save($setting, $value);
		}
		$sysColor = $theme->routed_sys_color ?? null;
		if (isset($sysColor) && preg_match("/^#([0-9a-f]{6})$/i", $sysColor)) {
			$this->setRoutedSysColor(substr(strtoupper($sysColor), 1));
		}
	}

	/** Get a rendered preview of a theme */
	private function getThemePreview(Theme $theme): string {
		$blob = "<font color={$theme->window_color}>".
			"<header>Page header<end>\n".
			"\n".
			"<header2>Section<end>\n".
			"<tab>Some text explaining <highlight>things<end> regarding lorem ipsum\n".
			"<tab>or something else. It's <highlight>really<end> important.".
			"<end>";
		$blob = str_replace(
			[
				"<header>",
				"<header2>",
				"<highlight>",
			],
			[
				"<font color={$theme->header_color}>",
				"<font color={$theme->header2_color}>",
				"<font color={$theme->highlight_color}>",
			],
			$blob
		);
		return $blob;
	}

	/** @return string[] */
	private function getColorAttributes(): array {
		$attributes = [
			"window_color",
			"priv_color",
			"tell_color",
			"guild_color",
			"routed_sys_color",
			"header_color",
			"header2_color",
			"highlight_color",
			"clan_color",
			"omni_color",
			"neut_color",
			"unknown_color",
		];
		return $attributes;
	}

	private function setRoutedSysColor(string $color): bool {
		$colorDef = $this->msgHubCtrl->getHopColor("system", null, null);
		if (!isset($colorDef)) {
			$colorDef = new RouteHopColor();
			$colorDef->hop = "system";
		}
		$colorDef->text_color = $color;
		$table = $this->msgHub::DB_TABLE_COLORS;
		try {
			if (isset($colorDef->id)) {
				$success = $this->db->update($table, "id", $colorDef) > 0;
			} else {
				$colorDef->id = $this->db->insert($table, $colorDef);
				$success = $colorDef->id > 0;
			}
			$this->msgHub->loadTagColor();
		} catch (Exception) {
			$success = false;
		}
		return $success;
	}

	/** Check if the given theme is the same that's currently in use */
	private function isThemeActive(Theme $theme): bool {
		$attributes = $this->getColorAttributes();
		foreach ($attributes as $attr) {
			$value = $theme->{$attr};
			if (!isset($value)) {
				continue;
			}
			if (preg_match("/^#([0-9a-f]{6})$/i", $value)) {
				$value = "<font color='{$value}'>";
			}
			$setting = "default" . join("", array_map("ucfirst", explode("_", $attr)));
			$currValue = $this->{$setting} ?? null;
			if ($currValue !== $value) {
				return false;
			}
		}
		return true;
	}
}
