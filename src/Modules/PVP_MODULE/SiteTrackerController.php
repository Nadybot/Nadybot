<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

// pf, site

use Illuminate\Support\Collection;
use Nadybot\Core\Modules\MESSAGES\MessageHubController;
use Nadybot\Core\ParamClass\PRemove;
use Nadybot\Core\Routing\{RoutableMessage, Source};
use Nadybot\Core\{Attributes as NCA, CmdContext, Config\BotConfig, DB, MessageHub, ModuleInstance, Text, UserException, Util};
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

	public const EVENTS = [
		'gas-change',
		'site-hot',
		'site-cold',
		'site-planted',
		'site-destroyed',
		'tower-attack',
		'tower-outcome',
		'tower-planted',
		'tower-destroyed',
	];

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

	#[NCA\Inject]
	public BotConfig $config;

	#[NCA\Inject]
	public MessageHubController $msgHubCtrl;

	/** @var array<int,TrackerEntry> */
	private array $trackers = [];

	/**
	 * @var array<string,string>
	 *
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

	/** Fire the $event for all matching trackers */
	public function fireEvent(RoutableMessage $msg, SiteUpdate $site, string $event): void {
		foreach ($this->trackers as $tracker) {
			if (!$tracker->matches($site, $event)) {
				continue;
			}
			$ignoreEvent = (new Collection($tracker->events))->filter(
				fn (string $eventPattern): bool => fnmatch($eventPattern, $event, FNM_CASEFOLD)
			)->isEmpty();
			if ($ignoreEvent) {
				continue;
			}
			$msg->prependPath(Source::fromChannel($tracker->getChannelName()));
			$this->msgHub->handle($msg);
		}
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
					$this->msgHub->registerMessageEmitter($entry);
					return $result;
				},
				[]
			);
	}

	/**
	 * Track sites based on one or more criteria
	 * &lt;expression&gt; is a combination of 1 or more patterns and 0 or more events.
	 * Check the <a href='chatcmd:///tell <myname> <symbol>nw track patterns'>list of patterns</a> and the <a href='chatcmd:///tell <myname> <symbol>nw track events'>list of events</a> for details.
	 *
	 * Note: All given patterns have to match, so using the same pattern twice
	 * <tab>will most likely give an empty result.
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
		#[NCA\Str("track", "tracker")]
		string $action,
		#[NCA\Str("add")]
		string $subAction,
		string $expression
	): void {
		$entry = $this->parseExpression($expression);
		$entry->created_by = $context->char->name;
		$entry->created_on = time();
		$entry->id = $this->db->insert(self::DB_TRACKER, $entry);
		$this->msgHub->registerMessageEmitter($entry);
		$this->trackers [$entry->id] = $entry;
		$numMatches = $this->countMatches($entry);
		$channel = $entry->getChannelName();
		$details = "";
		if (!$this->msgHub->hasRouteFor($channel)) {
			$privCmd = "<symbol>route add {$channel} -> aopriv";
			$orgCmd = "<symbol>route add {$channel} -> aoorg";
			$privLink = $this->text->makeChatcmd("do it", "/tell <myname> {$privCmd}");
			$orgLink = $this->text->makeChatcmd("do it", "/tell <myname> {$orgCmd}");

			$blob = "To be able to see the events that your tracker generates,\n".
				"you need to create a route from <highlight>{$channel}<end> to where you'd\n".
				"like to see the notifications:\n\n".
				"<tab><highlight>{$privCmd}<end> [{$privLink}]\n".
				"<tab><i>To display them in the bot's private channel</i>\n\n";
			if (isset($this->config->orgId)) {
				$blob .=
					"<tab><highlight>{$orgCmd}<end> [{$orgLink}]\n".
					"<tab><i>To display them in the guild channel</i>\n\n";
			}
			$blob .=
				"<tab><highlight><symbol>route add {$channel} -> discordpriv(foo)<end>\n".
				"<tab><i>To display them in the Discord-channel 'foo'.";
			$details = " You need to add a route in order to see the events ".
				"this tracker generates [" . ((array)$this->text->makeBlob(
					"see how",
					$blob,
					"How to configure routing for a tower tracker"
				))[0] . "]";
		}
		$context->reply("Tracker #{$entry->id} installed successfully, matching {$numMatches} sites.{$details}");
	}

	/** Delete a site trackers */
	#[NCA\HandlesCommand("nw track")]
	public function delTowerTracker(
		CmdContext $context,
		#[NCA\Str("track", "tracker")]
		string $action,
		PRemove $subAction,
		int $id,
	): void {
		$tracker = $this->trackers[$id] ?? null;
		if (!isset($tracker)) {
			$context->reply("No tracker <highlight>#{$id}<end> found.");
			return;
		}
		$this->db->table(self::DB_TRACKER)->delete($id);
		$this->msgHub->unregisterMessageEmitter($tracker->getChannelName());
		$routes = $this->msgHub->getRoutes();
		foreach ($routes as $route) {
			if ($route->getSource() === $tracker->getChannelName()) {
				$this->msgHubCtrl->routeDel($context, $subAction, $route->getID());
			}
		}
		unset($this->trackers[$id]);
		$context->reply("Tracker <highlight>#{$id}<end> successfully removed.");
	}

	/** Show all currently setup site trackers */
	#[NCA\HandlesCommand("nw track")]
	public function listTowerTracker(
		CmdContext $context,
		#[NCA\Str("track", "tracker")]
		string $action,
		#[NCA\Str("list")]
		?string $subAction,
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
		#[NCA\Str("track", "tracker")]
		string $action,
		#[NCA\Str("show", "view")]
		string $subAction,
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

	/** Show all sites matched by a site tracker */
	#[NCA\HandlesCommand("nw track")]
	public function showTowerTrackerPatterns(
		CmdContext $context,
		#[NCA\Str("track", "tracker")]
		string $action,
		#[NCA\Str("pattern", "patterns")]
		string $subAction,
	): void {
		/** @psalm-var class-string[] */
		$classes = array_unique(array_values($this->handlers));
		$blocks = [];
		foreach ($classes as $class) {
			$refClass = new ReflectionClass($class);
			foreach ($refClass->getAttributes(Argument::class) as $attr) {
				$spec = $attr->newInstance();
				$block = "<header2>{$spec->names[0]}<end>\n";
				if (count($spec->names) > 1) {
					$block .= "<tab>Aliases: <highlight>".
						join("<end>, <highlight>", array_values(array_slice($spec->names, 1))).
						"<end>\n";
				}
				if (count($spec->examples)) {
					$block .= "<tab>Examples: <highlight>{$spec->names[0]}=".
						join("<end>, <highlight>{$spec->names[0]}=", $spec->examples).
						"<end>\n";
				}
				$block .= "<tab>Type: <highlight>{$spec->type}<end>\n";
				$block .= "\n<tab><i>" . join("</i>\n<tab><i>", explode("\n", $spec->description)).
					"</i>";
				$blocks []= $block;
			}
		}
		$blob = "The following is a list of patterns you can use to limit the\n".
			"scope of your site trackers. Your trackers can use any number\n".
			"of patterns, separated by space.\n".
			"Unless you specify the events you want to receive, you\n".
			"will receive <i>all</i> events for the matching tower sites.\n".
			"See " . $this->text->makeChatcmd(
				'<symbol>nw track events',
				'/tell <myname> <symbol>nw track events'
			) . " for a list of events to use in '<highlight><symbol>nw track add<end>'.\n\n".
			join("\n\n", $blocks);
		$msg = $this->text->makeBlob(
			"Available patterns (" . count($blocks) . ")",
			$blob
		);
		$context->reply($msg);
	}

	/** Show all available site tracker events */
	#[NCA\HandlesCommand("nw track")]
	public function showTowerTrackerEvents(
		CmdContext $context,
		#[NCA\Str("track", "tracker")]
		string $action,
		#[NCA\Str("event", "events")]
		string $subAction,
	): void {
		$blocks = [];
		foreach (self::EVENTS as $event) {
			$blocks []= "<tab>{$event}";
		}
		$blob = "If you're setting up a site tracker, you can limit the\n".
			"type of events you want to receive for the matching sites.\n".
			"For example, you might be interested in all events for your\n".
			"own org's sites, but only in attacks for the sites of other\n".
			"orgs in your alliance.\n".
			"Events can always use wildcard-operators, so '<highlight>site-*<end>'\n".
			"will match site-planted, site-destroyed, site-hot, and site-cold.\n".
			"You can also give multiple events, just as you need it.\n\n".
			"<header2>Available site tracker events<end>\n".
			join("\n", $blocks);
		$msg = $this->text->makeBlob(
			"Available events (" . count($blocks) . ")",
			$blob
		);
		$context->reply($msg);
	}

	private function renderTracker(TrackerEntry $tracker): string {
		$expression = preg_replace('/\s+'.join('\s+', array_map('preg_quote', $tracker->events)).'$/', '', $tracker->expression);
		$showSitesLink = $this->text->makeChatcmd(
			"show",
			"/tell <myname> <symbol>nw track show {$tracker->id}"
		);
		$deleteLink = $this->text->makeChatcmd(
			"delete",
			"/tell <myname> <symbol>nw track rm {$tracker->id}"
		);
		$block = "<header2>{$expression}<end>\n".
			"<tab>ID: <highlight>{$tracker->id}<end> [{$deleteLink}]\n".
			"<tab>Created: <highlight>" . $this->util->date($tracker->created_on) . "<end> ".
			"by <highlight>{$tracker->created_by}<end>\n".
			"<tab>Events: <highlight>" . join("<end>, <highlight>", $tracker->events) . "<end>\n".
			"<tab>Matches: <highlight>" . $this->countMatches($tracker) . "<end> sites [".
			$showSitesLink . "]";
		if (!$this->msgHub->hasRouteFor($tracker->getChannelName())) {
			$block .= "\n<tab><red>No message route for this tracker<end>";
		} else {
			$receivers = $this->msgHub->getReceiversFor($tracker->getChannelName());
			if (count($receivers) > 0) {
				$block .= "\n<tab>Routed to: " . $this->text->enumerate(
					...$this->text->arraySprintf("<highlight>%s<end>", ...$receivers)
				);
			}
		}
		return $block;
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
		foreach ($config->events as $eventPattern) {
			$unknownEvent = (new Collection(self::EVENTS))->filter(
				fn (string $event): bool => fnmatch($eventPattern, $event, FNM_CASEFOLD)
			)->isEmpty();
			if ($unknownEvent) {
				throw new UserException("There is no event '<highlight>{$eventPattern}<end>'.");
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
