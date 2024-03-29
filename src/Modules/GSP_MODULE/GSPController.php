<?php declare(strict_types=1);

namespace Nadybot\Modules\GSP_MODULE;

use function Amp\asyncCall;
use function Safe\json_decode;

use Amp\Http\Client\{HttpClientBuilder, Request, Response};
use Amp\{Failure, Promise, Success};
use DateTimeZone;
use Exception;
use Generator;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	EventManager,
	MessageEmitter,
	MessageHub,
	ModuleInstance,
	Modules\DISCORD\DiscordController,
	Nadybot,
	Routing\RoutableMessage,
	Routing\Source,
	SettingManager,
	Text,
	UserStateEvent,
};
use Safe\DateTime;
use Safe\Exceptions\JsonException;
use Throwable;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "radio",
		accessLevel: "guest",
		description: "List what is currently playing on GridStream",
		alias: "gsp"
	),
	NCA\ProvidesEvent("gsp(show_start)"),
	NCA\ProvidesEvent("gsp(show_end)")
]
class GSPController extends ModuleInstance implements MessageEmitter {
	public const GSP_URL = 'https://gsp.torontocast.stream/streaminfo/';
	#[NCA\Inject]
	public HttpClientBuilder $builder;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public DiscordController $discordController;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public SettingManager $settingManager;

	/** Show on logon if there is a running GSP show */
	#[NCA\Setting\Boolean]
	public bool $gspShowLogon = true;

	/** 1 if a GSP show is currently running, otherwise 0 */
	protected int $showRunning = 0;

	/** The name of the currently running show or empty if none */
	protected string $showName = "";

	/** Location of the currently running show or empty if none */
	protected string $showLocation = "";

	public function getChannelName(): string {
		return Source::SYSTEM . "(gsp)";
	}

	#[NCA\Setup]
	public function setup(): void {
		$this->messageHub->registerMessageEmitter($this);
	}

	#[NCA\Event(
		name: "timer(1min)",
		description: "Check if a GSP show is running"
	)]
	public function announceIfShowRunning(): Generator {
		try {
			$client = $this->builder->build();

			/** @var Response */
			$response = yield $client->request(new Request(self::GSP_URL));
			$body = yield $response->getBody()->buffer();
			yield $this->checkAndAnnounceIfShowStarted($response, $body);
		} catch (Throwable) {
		}
	}

	/** Create a message about the currently running GSP show */
	public function getNotificationMessage(): string {
		$msg = sprintf(
			"GSP is now running <highlight>%s<end>.\nLocation: <highlight>%s<end>.",
			$this->showName,
			$this->showLocation
		);
		return $msg;
	}

	/**
	 * Announce if a new show has just started
	 *
	 * @return Promise<null>
	 */
	public function checkAndAnnounceIfShowStarted(Response $response, string $body): Promise {
		if ($response->getStatus() !== 200 || $body === '') {
			return new Success();
		}
		$show = new Show();
		try {
			$show->fromJSON(json_decode($body));
		} catch (JsonException) {
			return new Success();
		}
		if (!$this->isAllShowInformationPresent($show)) {
			return new Success();
		}
		if (!$show->live || !strlen($show->name) || !strlen($show->info)) {
			$show->live = 0;
		}
		if (!$this->hasShowInformationChanged($show)) {
			return new Success();
		}

		$event = new GSPEvent();
		$event->show = $show;
		$this->showRunning = $show->live;
		$this->showName = $show->name;
		$this->showLocation = $show->info;
		if (!$show->live) {
			$event->type = "gsp(show_end)";
			$this->eventManager->fireEvent($event);
			return new Success();
		}
		$event->type = "gsp(show_start)";
		$this->eventManager->fireEvent($event);
		$specialDelimiter = "<yellow>-----------------------------<end>";
		$msg = "\n".
			$specialDelimiter . "\n".
			$this->getNotificationMessage(). "\n".
			$specialDelimiter;
		$r = new RoutableMessage($msg);
		$r->prependPath(new Source(Source::SYSTEM, "gsp"));
		$this->messageHub->handle($r);
		return new Success();
	}

	#[NCA\Event(
		name: "logOn",
		description: "Announce running shows on logon"
	)]
	public function gspShowLogonEvent(UserStateEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (
			!$this->chatBot->isReady()
			|| !isset($this->chatBot->guildmembers[$sender])
			|| !$this->gspShowLogon
			|| !$this->showRunning
			|| !is_string($sender)
			|| $eventObj->wasOnline !== false
		) {
			return;
		}
		$msg = $this->getNotificationMessage();
		$this->chatBot->sendMassTell($msg, $sender);
	}

	/** Show what GridStream Productions is currently playing */
	#[NCA\HandlesCommand("radio")]
	public function radioCommand(CmdContext $context): Generator {
		$client = $this->builder->build();

		/** @var Response */
		$response = yield $client->request(new Request(self::GSP_URL));
		$body = yield $response->getBody()->buffer();
		$msg = yield $this->renderPlaylist($response, $body);
		$context->reply($msg);
	}

	/**
	 * Convert GSP milliseconds into a human readable time like 6:53
	 *
	 * @param int $milliSecs The duration in milliseconds
	 *
	 * @return string The time in a format 6:53 or 0:05
	 */
	public function msToTime(int $milliSecs): string {
		if ($milliSecs < 3600000) {
			return preg_replace('/^0/', '', gmdate("i:s", (int)round($milliSecs/1000)));
		}
		return gmdate("G:i:s", (int)round($milliSecs/1000));
	}

	/** Render a blob how players can tune into GSP */
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
		return " - " . ((array)$this->text->makeBlob("tune in", $blob, "Choose your stream quality"))[0];
	}

	/** Get a line describing what GSP is currently playing */
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
	 *
	 * @return Promise<string>
	 */
	public function renderPlaylist(Response $response, string $body): Promise {
		if ($body === '' || $response->getStatus() !== 200) {
			return new Success("GSP seems to have problems with their service. Please try again later.");
		}
		$show = new Show();
		try {
			$show->fromJSON(json_decode($body));
		} catch (JsonException $e) {
			return new Success("GSP seems to have problems with their service. Please try again later.");
		}
		if (empty($show->history)) {
			return new Success("GSP is currently not playing any music.");
		}
		$song = array_shift($show->history);
		$currentlyPlaying = $this->getCurrentlyPlaying($show, $song);

		$songs = $this->getPlaylistInfos($show->history);
		$showInfos = $this->getShowInfos($show);
		$lastSongsPage = ((array)$this->text->makeBlob(
			"last songs",
			$showInfos."<header2><u>Time         Song                                                                     </u><end>\n".join("\n", $songs),
			"Last played songs (all times in UTC)",
		))[0];
		$msg = $currentlyPlaying." - ".$lastSongsPage.$this->renderTuneIn($show);
		return new Success($msg);
	}

	#[
		NCA\NewsTile(
			name: "gsp-show",
			description: "Show the currently running GSP show and location - if any",
			example: "<header2>GSP<end>\n".
				"<tab>GSP is now running <highlight>Shigy's odd end<end>. Location: <highlight>Borealis at the whompahs<end>."
		)
	]
	public function gspShowTile(string $sender, callable $callback): void {
		if (!$this->showRunning) {
			$callback(null);
			return;
		}
		$msg = "<header2>GSP<end>\n".
			"<tab>" . $this->getNotificationMessage();
		$callback($msg);
	}

	#[
		NCA\NewsTile(
			name: "gsp",
			description: "Show what's currently playing on GSP.\n".
				"If there's a show, it also shows which one and its location.",
			example: "<header2>GSP<end>\n".
				"<tab>Currently playing on <yellow>The Odd End /w DJ Shigy<end>: <highlight>Molly Hatchet<end> - <highlight>Whiskey Man<end> [2:50/3:41]\n".
				"<tab>Current show: <highlight>The Odd End /w DJ Shigy<end>\n".
				"<tab>Location: <highlight>Borealis west of the wompahs (AO)<end>"
		)
	]
	public function gspTile(string $sender, callable $callback): void {
		asyncCall(function () use ($callback): Generator {
			try {
				$client = $this->builder->build();

				/** @var Response */
				$response = yield $client->request(new Request(self::GSP_URL));
				$body = yield $response->getBody()->buffer();
				$msg = yield $this->renderForGspTile($response, $body);
			} catch (Throwable $e) {
				$msg = null;
			}
			$callback($msg);
		});
	}

	/** @return Promise<string> */
	public function renderForGspTile(Response $response, string $body): Promise {
		if ($body === '') {
			return new Failure(new Exception("No content received"));
		}
		if ($response->getStatus() !== 200) {
			return new Failure(new Exception("Recdeiced a " . $response->getStatus() . "."));
		}
		$show = new Show();
		try {
			$show->fromJSON(json_decode($body));
		} catch (JsonException $e) {
			return new Failure($e);
		}
		$blob = "<header2>GSP<end>\n<tab>";
		if (empty($show->history)) {
			return new Success($blob . "GSP is currently not playing any music.");
		}
		$song = array_shift($show->history);
		$currentlyPlaying = $this->getCurrentlyPlaying($show, $song);
		$showInfos = $this->getShowInfos($show);
		if (strlen($showInfos)) {
			$showInfos = "\n<tab>" . join("\n<tab>", explode("\n", $showInfos));
		}
		return new Success("{$blob}{$currentlyPlaying}{$showInfos}");
	}

	/** Test if all needed data for the current show is present and valid */
	protected function isAllShowInformationPresent(Show $show): bool {
		return isset($show->live, $show->name, $show->info);
	}

	/** Check if the GSP changed to live, changed name or location */
	protected function hasShowInformationChanged(Show $show): bool {
		$informationChanged = $show->live !== $this->showRunning
			|| $show->name !== $this->showName
			|| $show->info !== $this->showLocation;
		return $informationChanged;
	}

	/**
	 * Get an array of song descriptions
	 *
	 * @param Song[] $history The history (playlist) as an array of songs
	 *
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

	/** Get information about the currently running GSP show */
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
}
