<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\COLORS;

use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	Attributes\Setting\Color,
	CmdContext,
	ModuleInstance,
	SettingManager,
	Text,
};
use Nadybot\Core\ParamClass\PFilename;

use function Safe\file_get_contents;
use function Safe\glob;
use function Safe\json_decode;

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "theme",
		description: "Change the bot's theme",
		alias: "themes",
	)
]
class ColorsController extends ModuleInstance {
	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Text $text;

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

	/** Get a list of color themes for the bot */
	#[NCA\HandlesCommand("theme")]
	public function cmdThemeList(CmdContext $context): void {
		$themes = $this->getThemeList();
		$blobs = [];
		foreach ($themes as $theme) {
			$link = $this->text->makeChatcmd(
				"apply",
				"/tell <myname> theme apply {$theme->name}"
			);
			$blobs []= "[{$link}] <highlight>{$theme->name}<end>: {$theme->description}";
		}
		$blob = join("\n", $blobs);
		$count = count($themes);
		$msg = $this->text->makeBlob("Themes ({$count})", $blob);
		$context->reply($msg);
	}

	/** Get a preview of all color themes for the bot */
	#[NCA\HandlesCommand("theme")]
	public function cmdThemePreview(
		CmdContext $context,
		#[NCA\Str("preview")] string $action,
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
		$blob = join("\n\n", $blobs);
		$count = count($themes);
		$msg = $this->text->makeBlob("Themes ({$count})", $blob);
		$context->reply($msg);
	}

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

	/** Apply a color theme for the bot */
	#[NCA\HandlesCommand("theme")]
	public function cmdApplyTheme(
		CmdContext $context,
		#[NCA\Str("apply")] string $action,
		PFilename $themeName
	): void {
		$theme = $this->loadTheme(__DIR__ . "/Themes/{$themeName}.json");
		if (!isset($theme)) {
			$context->reply("No theme <highlight>{$themeName}<end> found.");
			return;
		}
		$this->applyTheme($theme);
		$context->reply("Theme changed to <highlight>{$themeName}<end>.");
	}

	/** @return Theme[] */
	public function getThemeList(): array {
		$files = new Collection(glob(__DIR__ . "/Themes/*.json"));
		$themes = $files->map(function (string $file): ?Theme {
			return $this->loadTheme($file);
		})->filter()->values();
		return $themes->toArray();
	}

	public function loadTheme(string $filename): ?Theme {
		try {
			$json = file_get_contents($filename);
			$data = json_decode($json, true);
		} catch (Exception) {
			return null;
		}
		$data["name"] = basename($filename, ".json");
		return new Theme($data);
	}

	public function applyTheme(Theme $theme): void {
		$attributes = [
			"window_color",
			"priv_color",
			"tell_color",
			"guild_color",
			"header_color",
			"header2_color",
			"highlight_color",
			"clan_color",
			"omni_color",
			"neut_color",
			"unknown_color",
		];
		foreach ($attributes as $attr) {
			$value = $theme->{$attr};
			if (!isset($value)) {
				continue;
			}
			$setting = "default_{$attr}";
			if (preg_match("/^#([0-9a-f]{6})$/i", $value)) {
				$value = "<font color='$value'>";
			}
			$this->settingManager->save($setting, $value);
		}
	}
}
