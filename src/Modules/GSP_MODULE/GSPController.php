<?php declare(strict_types=1);

namespace Nadybot\Modules\GSP_MODULE;

use Amp\Http\Client\{HttpClientBuilder, Request, Response};
use DateTimeZone;
use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	EventManager,
	Events\LogonEvent,
	Hydrator,
	MessageHub,
	ModuleInstance,
	MyOrg,
	Nadybot,
	Routing\RoutableMessage,
	Routing\Source,
	Safe,
	Text,
	Types\AccessLevel,
	Types\MessageEmitter,
};
use Nadybot\Core\Attributes\ExposeToAI;
use Safe\DateTimeImmutable;
use Throwable;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\HasTests,
	NCA\DefineCommand(
		command: 'radio',
		accessLevel: AccessLevel::Guest,
		description: 'List what is currently playing on GridStream',
		alias: 'gsp'
	),
]
class GSPController extends ModuleInstance implements MessageEmitter {
	public const GSP_URL = 'https://gsp.torontocast.stream/streaminfo/';

	/** Show on logon if there is a running GSP show */
	#[NCA\Setting\Boolean]
	public bool $gspShowLogon = true;

	/** 1 if a GSP show is currently running, otherwise 0 */
	protected int $showRunning = 0;

	/** The name of the currently running show or empty if none */
	protected string $showName = '';

	/** Location of the currently running show or empty if none */
	protected string $showLocation = '';
	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private MyOrg $myOrg;

	public function getChannelName(): string {
		return Source::SYSTEM . '(gsp)';
	}

	#[NCA\Setup]
	public function setup(): void {
		$this->messageHub->registerMessageEmitter($this);
	}

	/** Check if a GSP show is running */
	#[NCA\Timer(interval: '1min')]
	public function announceIfShowRunning(): void {
		try {
			$client = $this->builder->build();

			$response = $client->request(new Request(self::GSP_URL));
			$body = $response->getBody()->buffer();
			$this->checkAndAnnounceIfShowStarted($response, $body);
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

	/** Announce if a new show has just started */
	public function checkAndAnnounceIfShowStarted(Response $response, string $body): void {
		if ($response->getStatus() !== 200 || $body === '') {
			return;
		}
		try {
			$show = Hydrator::hydrateString(Show::class, $body);
		} catch (\Throwable) {
			return;
		}
		if (!$this->isAllShowInformationPresent($show)) {
			return;
		}
		if (!$show->live || !strlen($show->name) || !strlen($show->info)) {
			$show->live = 0;
		}
		if (!$this->hasShowInformationChanged($show)) {
			return;
		}

		$this->showRunning = $show->live;
		$this->showName = $show->name;
		$this->showLocation = $show->info;
		if (!$show->live) {
			$event = new GSPShowEndEvent(show: $show);
			$this->eventManager->dispatch($event);
			return;
		}
		$event = new GSPShowStartEvent(show: $show);
		$this->eventManager->dispatch($event);
		$specialDelimiter = '<yellow>-----------------------------<end>';
		$msg = "\n".
			$specialDelimiter . "\n".
			$this->getNotificationMessage(). "\n".
			$specialDelimiter;
		$r = new RoutableMessage($msg);
		$r->prependPath(new Source(Source::SYSTEM, 'gsp'));
		$this->messageHub->handle($r);
	}

	/** Announce running shows on logon */
	#[NCA\HandlesEvent]
	public function gspShowLogonEvent(LogonEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (
			!$this->chatBot->isReady()
			|| !$this->myOrg->isMember($sender)
			|| !$this->gspShowLogon
			|| !$this->showRunning
			|| $eventObj->wasOnline !== false
		) {
			return;
		}
		$msg = $this->getNotificationMessage();
		$this->chatBot->sendMassTell($msg, $sender);
	}

	/** Show what GridStream Productions is currently playing */
	#[NCA\HandlesCommand('radio')]
	public function radioCommand(CmdContext $context): void {
		$client = $this->builder->build();

		$response = $client->request(new Request(self::GSP_URL));
		$body = $response->getBody()->buffer();
		$msg = $this->renderPlaylist($response, $body);
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
		if ($milliSecs < 3_600_000) {
			return Safe::pregReplace('/^0/', '', gmdate('i:s', (int)round($milliSecs/1_000)));
		}
		return gmdate('G:i:s', (int)round($milliSecs/1_000));
	}

	/** Render a blob how players can tune into GSP */
	public function renderTuneIn(Show $show): string {
		if (!count($show->stream)) {
			return '';
		}

		/** @var list<string> */
		$streams = [];
		$blob = "<header2>Available qualities<end>\n<tab>";
		foreach ($show->stream as $stream) {
			$streams []= sprintf(
				'%s: %s',
				$stream->id,
				Text::makeChatcmd('tune in', '/start '.$stream->url)
			);
		}
		$blob .= implode("\n<tab>", $streams);
		return ' - ' . Text::makeBlob('tune in', $blob, 'Choose your stream quality');
	}

	/** Get a line describing what GSP is currently playing */
	public function getCurrentlyPlaying(Show $show, Song $song): string {
		$msg = sprintf(
			'Currently playing on %s: <highlight>%s<end> - <highlight>%s<end>',
			($show->live === 1 && $show->name) ? '<yellow>'.$show->name.'<end>' : 'GSP',
			$song->artist ?? '<unknown artist>',
			$song->title ?? '<unknown song>'
		);
		if (isset($song->duration) && $song->duration > 0) {
			$duration = $this->msToTime($song->duration);
			$startTime = DateTimeImmutable::createFromFormat('Y-m-d*H:i:sT', $song->date)->setTimezone(new DateTimeZone('UTC'));
			$time = DateTimeImmutable::createFromFormat('Y-m-d*H:i:sT', $show->date)->setTimezone(new DateTimeZone('UTC'));
			$diff = $time->diff($startTime, true);
			$msg .= ' ['.$diff->format('%i:%S').'/'.$duration.']';
		}
		return $msg;
	}

	/** Create a message with information about what's currently playing on GSP */
	public function renderPlaylist(Response $response, string $body): string {
		if ($body === '' || $response->getStatus() !== 200) {
			return 'GSP seems to have problems with their service. Please try again later.';
		}
		try {
			$show = Hydrator::hydrateString(Show::class, $body);
		} catch (\Throwable $e) {
			return 'GSP seems to have problems with their service. Please try again later.';
		}
		if (!count($show->history)) {
			return 'GSP is currently not playing any music.';
		}
		$song = array_shift($show->history);
		$currentlyPlaying = $this->getCurrentlyPlaying($show, $song);

		$songs = $this->getPlaylistInfos($show->history);
		$showInfos = $this->getShowInfos($show);
		$lastSongsPage = Text::makeBlob(
			'last songs',
			$showInfos."<header2><u>Time         Song                                                                     </u><end>\n".implode("\n", $songs),
			'Last played songs (all times in UTC)',
		);
		$msg = $currentlyPlaying.' - '.$lastSongsPage.$this->renderTuneIn($show);
		return $msg;
	}

	#[
		NCA\NewsTile(
			name: 'gsp-show',
			description: 'Show the currently running GSP show and location - if any',
			example: "<header2>GSP<end>\n".
				"<tab>GSP is now running <highlight>Shigy's odd end<end>. Location: <highlight>Borealis at the whompahs<end>."
		)
	]
	public function gspShowTile(string $sender): ?string {
		if (!$this->showRunning) {
			return null;
		}
		$msg = "<header2>GSP<end>\n".
			'<tab>' . $this->getNotificationMessage();
		return $msg;
	}

	#[
		NCA\NewsTile(
			name: 'gsp',
			description: "Show what's currently playing on GSP.\n".
				"If there's a show, it also shows which one and its location.",
			example: "<header2>GSP<end>\n".
				"<tab>Currently playing on <yellow>The Odd End /w DJ Shigy<end>: <highlight>Molly Hatchet<end> - <highlight>Whiskey Man<end> [2:50/3:41]\n".
				"<tab>Current show: <highlight>The Odd End /w DJ Shigy<end>\n".
				'<tab>Location: <highlight>Borealis west of the wompahs (AO)<end>'
		)
	]
	public function gspTile(string $sender): ?string {
		try {
			$client = $this->builder->build();

			$response = $client->request(new Request(self::GSP_URL));
			$body = $response->getBody()->buffer();
			$msg = $this->renderForGspTile($response, $body);
		} catch (Throwable $e) {
			$msg = null;
		}
		return $msg;
	}

	public function renderForGspTile(Response $response, string $body): string {
		if ($body === '') {
			throw new Exception('No content received');
		}
		if ($response->getStatus() !== 200) {
			throw new Exception('Recdeiced a ' . $response->getStatus() . '.');
		}
		try {
			$show = Hydrator::hydrateString(Show::class, $body);
		} catch (\Throwable) {
			return 'GSP seems to have problems with their service. Please try again later.';
		}
		$blob = "<header2>GSP<end>\n<tab>";
		if (!count($show->history)) {
			return $blob . 'GSP is currently not playing any music.';
		}
		$song = array_shift($show->history);
		$currentlyPlaying = $this->getCurrentlyPlaying($show, $song);
		$showInfos = $this->getShowInfos($show);
		if (strlen($showInfos)) {
			$showInfos = "\n<tab>" . implode("\n<tab>", explode("\n", $showInfos));
		}
		return "{$blob}{$currentlyPlaying}{$showInfos}";
	}

	/**
	 * Get what's currently playing on GridStreamProductions (GSP), the Anarchy Online radio
	 *
	 * @return string|Show Either an error message, or detail information about the current show
	 */
	#[ExposeToAI('get_gsp_show')]
	public function getShow(): string|Show {
		$client = $this->builder->build();

		$response = $client->request(new Request(self::GSP_URL));
		if ($response->getStatus() !== 200) {
			return 'GSP seems to have problems with their service. Please try again later.';
		}
		$body = $response->getBody()->buffer();
		if ($body === '') {
			return 'GSP seems to have problems with their service. Please try again later.';
		}
		try {
			$show = Hydrator::hydrateString(Show::class, $body);
		} catch (\Throwable $e) {
			return 'GSP seems to have problems with their service. Please try again later.';
		}
		if (!count($show->history)) {
			return 'GSP is currently not playing any music.';
		}

		return $show;
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
	 * @param iterable<Song> $history The history (playlist) as an array of songs
	 *
	 * @return list<string> Rendered song information about the playlist
	 */
	protected function getPlaylistInfos(iterable $history): array {
		/** @var list<string> */
		$songs = [];
		foreach ($history as $song) {
			$time = DateTimeImmutable::createFromFormat(
				'Y-m-d*H:i:sT',
				$song->date,
				new DateTimeZone('UTC')
			);
			$info = sprintf(
				'%s   <highlight>%s<end> - %s',
				$time->format('H:i:s'),
				$song->artist ?? 'Unknown Artist',
				$song->title ?? 'Unknown Song',
			);
			if (isset($song->duration) && $song->duration > 0) {
				$info .= ' ['.$this->msToTime($song->duration).']';
			}
			$songs []= $info;
		}
		return $songs;
	}

	/** Get information about the currently running GSP show */
	protected function getShowInfos(Show $show): string {
		if ($show->live !== 1 || !strlen($show->name)) {
			return '';
		}
		$showInfos = 'Current show: <highlight>'.$show->name."<end>\n";
		if (strlen($show->info)) {
			$showInfos .= 'Location: <highlight>'.$show->info."<end>\n";
		}
		$showInfos .= "\n";
		return $showInfos;
	}
}
