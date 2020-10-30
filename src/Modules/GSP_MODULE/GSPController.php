<?php declare(strict_types=1);

namespace Nadybot\Modules\GSP_MODULE;

use DateTime;
use DateTimeZone;
use JsonException;
use Nadybot\Core\{
	CommandReply,
	Event,
	EventManager,
	Http,
	HttpResponse,
	Modules\DISCORD\DiscordController,
	Nadybot,
	SettingManager,
	Text,
};

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'radio',
 *		accessLevel = 'all',
 *		description = 'List what is currently playing on GridStream',
 *		alias       = 'gsp',
 *		help        = 'radio.txt'
 *	)
 * @ProvidesEvent("gsp(show_start)")
 * @ProvidesEvent("gsp(show_end)")
 */
class GSPController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Http $http;

	/** @Inject */
	public DiscordController $discordController;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public SettingManager $settingManager;

	/**
	 * 1 if a GSP show is currently running, otherwise 0
	 */
	protected int $showRunning = 0;

	/**
	 * The name of the currently running show or empty if none
	 */
	protected string $showName = "";

	/**
	 * Location of the currently running show or empty if none
	 */
	protected string $showLocation = "";

	public const GSP_URL = 'https://gsp.torontocast.stream/streaminfo/';

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			'gsp_channels',
			'Where to announce if a show starts',
			'edit',
			'options',
			'3',
			'Off;priv;org;priv+org;discord;discord+priv;discord+org;discord+priv+org',
			'0;1;2;3;4;5;6;7',
			'mod',
			'radio.txt'
		);
		$this->settingManager->add(
			$this->moduleName,
			"gsp_show_logon",
			"Show on logon if there is a running GSP show",
			"edit",
			"options",
			"1",
			"true;false",
			"1;0"
		);
	}

	/**
	 * @Event("timer(1min)")
	 * @Description("Check if a GSP show is running")
	 */
	public function announceIfShowRunning(): void {
		$this->http
				->get(static::GSP_URL)
				->withTimeout(10)
				->withCallback([$this, "checkAndAnnounceIfShowStarted"]);
	}

	/**
	 * Create a message about the currently running GSP show
	 */
	public function getNotificationMessage(): string {
		$msg = sprintf(
			"GSP is now running <highlight>%s<end>. Location: <highlight>%s<end>.",
			$this->showName,
			$this->showLocation
		);
		return $msg;
	}

	/**
	 * Test if all needed data for the current show is present and valid
	 */
	protected function isAllShowInformationPresent(Show $show): bool {
		return isset($show->live)
			&& is_integer($show->live)
			&& isset($show->name)
			&& isset($show->info);
	}

	/**
	 * Check if the GSP changed to live, changed name or location
	 */
	protected function hasShowInformationChanged(Show $show): bool {
		$informationChanged = $show->live !== $this->showRunning
			|| $show->name !== $this->showName
			|| $show->info !== $this->showLocation;
		return $informationChanged;
	}

	/**
	 * Announce if a new show has just started
	 */
	public function checkAndAnnounceIfShowStarted(HttpResponse $response): void {
		if ($response->body === null || $response->error) {
			return;
		}
		$show = new Show();
		try {
			$show->fromJSON(json_decode($response->body, false, 512, JSON_THROW_ON_ERROR));
		} catch (JsonException $e) {
			return;
		}
		if ( !$this->isAllShowInformationPresent($show) ) {
			return;
		}
		if (!$show->live || !strlen($show->name) || !strlen($show->info)) {
			$show->live = 0;
		}
		if (!$this->hasShowInformationChanged($show)) {
			return;
		}

		$event = new GSPEvent();
		$event->show = $show;
		$this->showRunning = $show->live;
		$this->showName = $show->name;
		$this->showLocation = $show->info;
		if (!$show->live) {
			$event->type = "gsp(show_end)";
			$this->eventManager->fireEvent($event);
			return;
		}
		$event->type = "gsp(show_start)";
		$this->eventManager->fireEvent($event);
		$specialDelimiter = "<yellow>-----------------------------<end>";
		$msg = "\n".
			$specialDelimiter . "\n".
			$this->getNotificationMessage(). "\n".
			$specialDelimiter;
		$this->announceShow($msg);
	}

	/**
	 * Announce a show on all configured channels
	 *
	 * @param string $msg The message to announce
	 * @return void
	 */
	protected function announceShow(string $msg) {
		if ($this->settingManager->getInt('gsp_channels') & 1 ) {
			$this->chatBot->sendPrivate($msg, true);
		}
		if ($this->settingManager->getInt('gsp_channels') & 2) {
			$this->chatBot->sendGuild($msg, true);
		}
		if ($this->settingManager->getInt('gsp_channels') & 4) {
			$this->discordController->sendDiscord($msg);
		}
	}

	/**
	 * @Event("logOn")
	 * @Description("Announce running shows on logon")
	 */
	public function gspShowLogonEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		if (
			!$this->chatBot->isReady()
			|| !isset($this->chatBot->guildmembers[$sender])
			|| !$this->settingManager->getBool('gsp_show_logon')
			|| !$this->showRunning
		) {
			return;
		}
		$msg = $this->getNotificationMessage();
		$this->chatBot->sendTell($msg, $sender);
	}

	/**
	 * @HandlesCommand("radio")
	 * @Matches("/^radio$/i")
	 */
	public function radioCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->http
				->get(static::GSP_URL)
				->withTimeout(5)
				->withCallback(function(HttpResponse $response) use ($sendto) {
					$msg = $this->renderPlaylist($response);
					$sendto->reply($msg);
				});
	}

	/**
	 * Convert GSP milliseconds into a human readable time like 6:53
	 *
	 * @param int $milliSecs The duration in milliseconds
	 * @return string The time in a format 6:53 or 0:05
	 */
	public function msToTime(int $milliSecs): string {
		if ($milliSecs < 3600000) {
			return preg_replace('/^0/', '', gmdate("i:s", (int)round($milliSecs/1000)));
		}
		return gmdate("G:i:s", (int)round($milliSecs/1000));
	}

	/**
	 * Render a blob how players can tune into GSP
	 */
	public function renderTuneIn(Show $show): string {
		if (!count($show->stream)) {
			return '';
		}
		$streams = [];
		$blob = "<header2>Available qualities<end>\n<tab>";
		foreach ($show->stream as $stream) {
			$streams[] = sprintf(
				"%s: %s",
				$stream->id,
				$this->text->makeChatcmd("tune in", "/start ".$stream->url)
			);
		}
		$blob .= join("\n<tab>", $streams);
		return " - ".$this->text->makeBlob("tune in", $blob, "Choose your stream quality");
	}

	/**
	 * Get an array of song descriptions
	 *
	 * @param Song[] $history The history (playlist) as an aray of songs
	 * @return string[] Rendered song information about the playlist
	 */
	protected function getPlaylistInfos(array $history): array {
		$songs = [];
		foreach ($history as $song) {
			$time = DateTime::createFromFormat("Y-m-d*H:i:sT", $song->date)->setTimezone(new DateTimeZone("UTC"));
			$info = sprintf(
				"%s   <highlight>%s<end> - %s",
				$time->format("H:i:s"),
				$song->artist ?? "Unknown Artist",
				$song->title ?? "Unknown Song",
			);
			if (isset($song->duration) && $song->duration > 0) {
				$info .= " [".$this->msToTime($song->duration)."]";
			}
			$songs[] = $info;
		}
		return $songs;
	}

	/**
	 * Get information about the currently running GSP show
	 */
	protected function getShowInfos(Show $show): string {
		if ($show->live !== 1 || !strlen($show->name)) {
			return "";
		}
		$showInfos = "Current show: <highlight>".$show->name."<end>\n";
		if (strlen($show->info)) {
			$showInfos .= "Location: <highlight>".$show->info."<end>\n";
		}
		$showInfos .= "\n";
		return $showInfos;
	}

	/**
	 * Get a line describing what GSP is currently playing
	 */
	public function getCurrentlyPlaying(Show $show, Song $song): string {
		$msg = sprintf(
			"Currently playing on %s: <highlight>%s<end> - <highlight>%s<end>",
			($show->live === 1 && $show->name) ? "<yellow>".$show->name."<end>" : "GSP",
			$song->artist ?? '<unknown artist>',
			$song->title ?? '<unknown song>'
		);
		if (isset($song->duration) && $song->duration > 0) {
			$startTime = DateTime::createFromFormat("Y-m-d*H:i:sT", $song->date)->setTimezone(new DateTimeZone("UTC"));
			$time = DateTime::createFromFormat("Y-m-d*H:i:sT", $show->date)->setTimezone(new DateTimeZone("UTC"));
			$diff = $time->diff($startTime, true);
			$msg .= " [".$diff->format("%i:%S")."/".$this->msToTime($song->duration)."]";
		}
		return $msg;
	}

	/**
	 * Create a message with information about what's currently playing on GSP
	 */
	public function renderPlaylist(HttpResponse $response): string {
		$show = new Show();
		try {
			$show->fromJSON(json_decode($response->body));
		} catch (JsonException $e) {
			return "GSP seems to have problems with their service. Please try again later.";
		}
		if (empty($show->history)) {
			return "GSP is currently not playing any music.";
		}
		$song = array_shift($show->history);
		$currentlyPlaying = $this->getCurrentlyPlaying($show, $song);

		$songs = $this->getPlaylistInfos($show->history);
		$showInfos = $this->getShowInfos($show);
		$lastSongsPage = $this->text->makeBlob(
			"last songs",
			$showInfos."<header2><u>Time         Song                                                                     </u><end>\n".join("\n", $songs),
			"Last played songs (all times in UTC)",
		);
		$msg = $currentlyPlaying." - ".$lastSongsPage.$this->renderTuneIn($show);
		return $msg;
	}
}
