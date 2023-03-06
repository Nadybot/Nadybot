<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

// pf, site

use Illuminate\Support\Collection;
use Nadybot\Core\ParamClass\PRemove;
use Nadybot\Core\{Attributes as NCA, CmdContext, DB, MessageHub, ModuleInstance, Text, UserException, Util};
use Nadybot\Modules\HELPBOT_MODULE\PlayfieldController;
use Nadybot\Modules\PVP_MODULE\Attributes\Argument;
use Nadybot\Modules\PVP_MODULE\FeedMessage\SiteUpdate;
use Nadybot\Modules\PVP_MODULE\Handlers\Base;
use ReflectionClass;
use Throwable;

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "nw track",
		description: "Track tower sites",
		accessLevel: "member"
	),
]
class SiteTrackerController extends ModuleInstance {
	public const DB_TRACKER = "nw_tracker_<myname>";

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public PlayfieldController $pfCtrl;

	#[NCA\Inject]
	public NotumWarsController $nwCtrl;

	#[NCA\Inject]
	public MessageHub $msgHub;

	/** @var array<int,TrackerEntry> */
	private array $trackers = [];

	/**
	 * @var array<string,string>
	 * @psalm-var array<string,class-string>
	 */
	private array $handlers = [];

	/**
	 * Register an argument handler
	 *
	 * @psalm-param class-string $className
	 */
	public function registerHandler(string $className, string ...$names): void {
		foreach ($names as $name) {
			$this->handlers[$name] = $className;
		}
	}

	/** Check if a given site is currently tracked */
	public function isTracked(SiteUpdate $site, string $event): bool {
		foreach ($this->trackers as $tracker) {
			if ($tracker->matches($site, $event)) {
				return true;
			}
		}
		return false;
	}

	#[NCA\Setup]
	public function setup(): void {
		$handlerFile = \Safe\glob(__DIR__ . "/Handlers/*.php");
		foreach ($handlerFile as $file) {
			require_once $file;
			$className = basename($file, '.php');
			$fullClass = __NAMESPACE__ . "\\Handlers\\{$className}";
			if (!class_exists($fullClass)) {
				continue;
			}
			$refClass = new ReflectionClass($fullClass);
			foreach ($refClass->getAttributes(Argument::class) as $attr) {
				$handler = $attr->newInstance();
				$this->registerHandler($fullClass, ...$handler->names);
			}
		}
		$this->trackers = $this->db->table(self::DB_TRACKER)
			->asObj(TrackerEntry::class)
			->reduce(
				function (array $result, TrackerEntry $entry): array {
					try {
						$parsed = $this->parseExpression($entry->expression);
					} catch (Throwable) {
						return $result;
					}
					$entry->handlers = $parsed->handlers;
					$result[$entry->id] = $entry;
					return $result;
				},
				[]
			);
	}

	/**
	 * Track sites based on one or more criteria
	 * &lt;expression&gt; is a combination of 1 or more patterns and 0 or more events.
	 *
	 * Patterns:
	 * <tab>faction=omni|clan|neutral|!omni|!clan|!neutral
	 * <tab>org=name or org="name with spaces"
	 * <tab>pf=PW|MORT|...
	 * <tab>site=PW12 or site="PW 12"
	 * <tab>ql=55 or ql=25-53
	 * <tab>max_towers=1
	 * Events:
	 * <tab>Everything listed in brackets at '<highlight><symbol>route list src<end>' under "site-tracker", or wildcards,
	 * <tab>e.g. "site-hot", "gas-change", "site-*", or "*"
	 *
	 * If you give more than 1 pattern, then every pattern has to match for the
	 * tracking to work. In order to track multiple orgs, use multiple trackers
	 *
	 * All tracking events will generate messages in the corresponding site-tracker(*)-route,
	 * so make sure to install a route to actually see your tracking.
	 */
	#[NCA\HandlesCommand("nw track")]
	#[NCA\Help\Example(
		command: "<symbol>nw track add faction=omni max_towers=1",
		description: "Track all sites owned by omni-orgs with only a CT"
	)]
	#[NCA\Help\Example(
		command: "<symbol>nw track add pf=MORT faction=neutral ql=32-52",
		description: "Track all sites in Mort owned by neutral orgs, where ".
			"the CT has a QL between 32 and 52"
	)]
	public function addTowerTracker(
		CmdContext $context,
		#[NCA\Str("track", "tracker")] string $action,
		#[NCA\Str("add")] string $subAction,
		string $expression
	): void {
		$entry = $this->parseExpression($expression);
		$entry->created_by = $context->char->name;
		$entry->created_on = time();
		$entry->id = $this->db->insert(self::DB_TRACKER, $entry);
		$this->trackers [$entry->id] = $entry;
		$numMatches = $this->countMatches($entry);
		$context->reply("Tracker installed successfully, matching {$numMatches} sites.");
	}

	/** Delete a site trackers */
	#[NCA\HandlesCommand("nw track")]
	public function delTowerTracker(
		CmdContext $context,
		#[NCA\Str("track", "tracker")] string $action,
		PRemove $subAction,
		int $id,
	): void {
		if (!isset($this->trackers[$id])) {
			$context->reply("No tracker <highlight>#{$id}<end> found.");
			return;
		}
		$this->db->table(self::DB_TRACKER)->delete($id);
		unset($this->trackers[$id]);
		$context->reply("Tracker <highlight>#{$id}<end> successfully removed.");
	}

	/** Show all currently setup site trackers */
	#[NCA\HandlesCommand("nw track")]
	public function listTowerTracker(
		CmdContext $context,
		#[NCA\Str("track", "tracker")] string $action,
		#[NCA\Str("list")] ?string $subAction,
	): void {
		if (empty($this->trackers)) {
			$context->reply("No registered trackers.");
			return;
		}
		$blocks = [];
		foreach ($this->trackers as $tracker) {
			$blocks []= $this->renderTracker($tracker);
		}
		$msg = $this->text->makeBlob(
			"Registered trackers (" . count($this->trackers) . ")",
			join("\n\n", $blocks)
		);
		$context->reply($msg);
	}

	/** Show all sites matched by a site tracker */
	#[NCA\HandlesCommand("nw track")]
	public function showTowerTrackerMatches(
		CmdContext $context,
		#[NCA\Str("track", "tracker")] string $action,
		#[NCA\Str("show")] string $subAction,
		int $id,
	): void {
		$tracker = $this->trackers[$id] ?? null;
		if (!isset($tracker)) {
			$context->reply("No tracker <highlight>#{$id}<end> found.");
			return;
		}

		/** @var Collection<SiteUpdate> */
		$sites = $this->nwCtrl->getEnabledSites()
			->filter(fn (SiteUpdate $site): bool => $tracker->matches($site))
			->sortBy("site_id")
			->sortBy("playfield_id");
		$blob = $this->nwCtrl->renderHotSites(...$sites->toArray());
		$expression = preg_replace('/\s+'.join('\s+', array_map('preg_quote', $tracker->events)).'$/', '', $tracker->expression);
		$msg = $this->text->makeBlob(
			"Sites matching tracker '{$expression}' (" . $sites->count() . ")",
			$blob
		);
		$context->reply($msg);
	}

	private function renderTracker(TrackerEntry $tracker): string {
		$expression = preg_replace('/\s+'.join('\s+', array_map('preg_quote', $tracker->events)).'$/', '', $tracker->expression);
		return "<header2>{$expression}<end>\n".
			"<tab>ID: <highlight>{$tracker->id}<end>\n".
			"<tab>Created: <highlight>" . $this->util->date($tracker->created_on) . "<end> ".
			"by <highlight>{$tracker->created_by}<end>\n".
			"<tab>Events: <highlight>" . join("<end>, <highlight>", $tracker->events) . "<end>\n".
			"<tab>Matches: <highlight>" . $this->countMatches($tracker) . "<end> entries.\n".
			"<tab>Links: [" . $this->text->makeChatcmd(
				"show sites",
				"/tell <myname> <symbol>nw track show {$tracker->id}"
			) . "] [" . $this->text->makeChatcmd(
				"delete",
				"/tell <myname> <symbol>nw track rm {$tracker->id}"
			) . "]";
	}

	private function countMatches(TrackerEntry $entry): int {
		return $this->nwCtrl->getEnabledSites()
			->filter(fn (SiteUpdate $site): bool => $entry->matches($site))
			->count();
	}

	private function parseExpression(string $expression): TrackerEntry {
		$parser = new TrackerArgumentParser();
		$config = $parser->parse($expression);
		$entry = new TrackerEntry();
		$entry->expression = $expression;
		if (empty($config->events)) {
			$config->events = ["*"];
		}
		foreach ($config->events as $event) {
			if ($this->msgHub->getEmitter("site-tracker({$event})") === null) {
				throw new UserException("There is no event '<highlight>{$event}<end>'.");
			}
		}
		$entry->events = $config->events;
		foreach ($config->arguments as $argument) {
			$argument->name = strtolower($argument->name);
			$className = $this->handlers[$argument->name] ?? null;
			if (!isset($className)) {
				throw new UserException("There is no filter for '<highlight>{$argument->name}<end>'.");
			}
			if (is_subclass_of($className, Base::class)) {
				try {
					/** @psalm-suppress UnsafeInstantiation */
					$entry->handlers []= new $className($argument->value);
				} catch (UserException $e) {
					throw $e;
				} catch (Throwable) {
					throw new UserException("'<highlight>{$argument->value}<end>' is not a valid value for {$argument->name}.");
				}
			}
		}
		return $entry;
	}
}
