<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\MESSAGES;

use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\CommandReply;
use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\DBSchema\RouteModifier;
use Nadybot\Core\DBSchema\RouteModifierArgument;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageEmitter;
use Nadybot\Core\MessageHub;
use Nadybot\Core\MessageRoute;
use Nadybot\Core\Nadybot;
use Nadybot\Core\SettingManager;
use Nadybot\Core\Text;
use Nadybot\Core\Util;
use Throwable;

/**
 * @author Nadyita (RK5)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command       = 'route',
 *		accessLevel   = 'mod',
 *		description   = 'Set which message are routed from where to where',
 *		help          = 'route.txt',
 *		defaultStatus = '1'
 *	)
 */
class MessageHubController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public DB $db;

	/** @Logger */
	public LoggerWrapper $logger;

	/**
	 * @Event("connect")
	 * @Description("Load routing from database")
	 */
	public function loadRouting() {
		$arguments = $this->db->table($this->messageHub::DB_TABLE_ROUTE_MODIFIER_ARGUMENT)
			->orderBy("id")
			->asObj(RouteModifierArgument::class)
			->groupBy("route_modifier_id");
		$modifiers = $this->db->table($this->messageHub::DB_TABLE_ROUTE_MODIFIER)
			->orderBy("id")
			->asObj(RouteModifier::class)
			->each(function (RouteModifier $mod) use ($arguments): void {
				$mod->arguments = $arguments->get($mod->id, new Collection())->toArray();
			})
			->groupBy("route_id");
		$this->db->table($this->messageHub::DB_TABLE_ROUTES)
			->orderBy("id")
			->asObj(Route::class)
			->each(function(Route $route) use ($modifiers): void {
				$route->modifiers = $modifiers->get($route->id, new Collection())->toArray();
				try {
					$msgRoute = $this->messageHub->createMessageRoute($route);
					$this->messageHub->addRoute($msgRoute);
				} catch (Exception $e) {
					$this->logger->log('ERROR', $e->getMessage(), $e);
				}
			});
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route add (?:from )?(?<from>.+?) (?<direction>to|->|<->) (?<to>[^ ]+) (?<modifiers>.+)$/i")
	 * @Matches("/^route add (?:from )?(?<from>.+?) (?<direction>to|->|<->) (?<to>[^ ]+\(.*?\)) (?<modifiers>.+)$/i")
	 * @Matches("/^route add (?:from )?(?<from>.+?) (?<direction>to|->|<->) (?<to>.+)$/i")
	 */
	public function routeAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$receiver = $this->messageHub->getReceiver($args["to"]);
		if (!isset($receiver)) {
			$sendto->reply("Unknown target <highlight>{$args["to"]}<end>.");
			return;
		}
		$route = new Route();
		$route->source = $args["from"];
		$route->destination = $args["to"];
		if ($args["direction"] === "<->") {
			$route->two_way = true;
			$receiver = $this->messageHub->getReceiver($args["from"]);
			if (!isset($receiver)) {
				$sendto->reply("Unable to route to <highlight>{$args["from"]}<end>.");
				return;
			}
		}
		if (isset($args["modifiers"])) {
			$parser = new ModifierExpressionParser();
			try {
				$modifiers = $parser->parse($args["modifiers"]);
			} catch (ModifierParserException $e) {
				$sendto->reply($e->getMessage());
				return;
			}
		}
		$this->db->beginTransaction();
		try {
			$route->id = $this->db->insert($this->messageHub::DB_TABLE_ROUTES, $route);
			foreach ($modifiers as $modifier) {
				$modifier->route_id = $route->id;
				$modifier->id = $this->db->insert(
					$this->messageHub::DB_TABLE_ROUTE_MODIFIER,
					$modifier
				);
				foreach ($modifier->arguments as $argument) {
					$argument->route_modifier_id = $modifier->id;
					$argument->id = $this->db->insert(
						$this->messageHub::DB_TABLE_ROUTE_MODIFIER_ARGUMENT,
						$argument
					);
				}
				$route->modifiers []= $modifier;
			}
		} catch (Throwable $e) {
			$this->db->rollback();
			$sendto->reply("Error saving the route: " . $e->getMessage());
			return;
		}
		$modifiers = [];
		foreach ($route->modifiers as $modifier) {
			$modifiers []= $modifier->toString();
		}
		try {
			$msgRoute = $this->messageHub->createMessageRoute($route);
		} catch (Exception $e) {
			$this->db->rollback();
			$sendto->reply($e->getMessage());
			return;
		}
		$this->db->commit();
		$this->messageHub->addRoute($msgRoute);
		$sendto->reply(
			"Route added from <highlight>{$args["from"]}<end> ".
			"to <highlight>{$args["to"]}<end>".
			(count($modifiers) ? " using " : "").
			join(" ", $modifiers)  . "."
		);
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route list from$/i")
	 */
	public function routeListFromCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$emitters = $this->messageHub->getEmitters();
		$count = count($emitters);
		ksort($emitters);
		$emitters = new Collection($emitters);
		$blob = $emitters->groupBy([$this, "getEmitterType"])
			->map([$this, "renderEmitterGroup"])
			->join("\n\n");
		$msg = $this->text->makeBlob("Message sources ({$count})", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route list to$/i")
	 */
	public function routeListToCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$receivers = $this->messageHub->getReceivers();
		$count = count($receivers);
		ksort($receivers);
		$receivers = new Collection($receivers);
		$blob = $receivers->groupBy([$this, "getEmitterType"])
			->map([$this, "renderEmitterGroup"])
			->join("\n\n");
		$msg = $this->text->makeBlob("Message targets ({$count})", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route list modifiers?$/i")
	 */
	public function routeListModifiersCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$mods = $this->messageHub->modifiers;
		$count = count($mods);
		if (!$count) {
			$sendto->reply("No message modifiers available.");
			return;
		}
		$blobs = [];
		foreach ($mods as $mod) {
			$description = $mod->description ?? "Someone forgot to add a description";
			$entry = "<header2>{$mod->name}<end>\n".
				"<tab><i>".
				join("\n<tab>", explode("\n", trim($description))).
				"</i>\n".
				"<tab>[" . $this->text->makeChatcmd("details", "/tell <myname> route list modifier {$mod->name}") . "]";
			$blobs []= $entry;
		}
		$blob = join("\n\n", $blobs);
		$msg = $this->text->makeBlob("Message modifiers ({$count})", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route list modifier (.+)$/i")
	 */
	public function routeListModifierCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$mod = $this->messageHub->modifiers[$args[1]];
		if (!isset($mod)) {
			$sendto->reply("No message modifier <highlight>{$args[1]}<end> found.");
			return;
		}
		$description = $mod->description ?? "Someone forgot to add a description";
		$blob = "<header2>Description<end>\n".
			"<tab>" . join("\n<tab>", explode("\n", trim($description))).
			"\n\n".
			"<header2>Parameters<end>\n";
		foreach ($mod->params as $param) {
			$blob .= "<tab><highlight>{$param->type} {$param->name}<end>";
			if (!$param->required) {
				$blob .= " (optional)";
			}
			$blob .= "\n<tab><i>".
				join("</i>\n<tab><i>", explode("\n", $param->description ?? "No description")).
				"</i>\n\n";
		}
		$msg = $this->text->makeBlob("{$mod->name}", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route (?:del|rem) (\d+)$/i")
	 */
	public function routeDel(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];
		$route = $this->getRoute($id);
		if (!isset($route)) {
			$sendto->reply("No route <highlight>#{$id}<end> found.");
			return;
		}
		/** @var int[] List of modifier-ids for the route */
		$modifiers = array_column($route->modifiers, "id");
		$this->db->beginTransaction();
		try {
			if (count($modifiers)) {
				$this->db->table($this->messageHub::DB_TABLE_ROUTE_MODIFIER_ARGUMENT)
					->whereIn("route_modifier_id", $modifiers)
					->delete();
				$this->db->table($this->messageHub::DB_TABLE_ROUTE_MODIFIER)
					->where("route_id", $id)
					->delete();
			}
			$this->db->table($this->messageHub::DB_TABLE_ROUTES)
				->delete($id);
		} catch (Throwable $e) {
			$this->db->rollback();
			$sendto->reply("Error deleting the route: " . $e->getMessage());
			return;
		}
		$this->db->commit();
		$deleted = $this->messageHub->deleteRouteID($id);
		if (isset($deleted)) {
			$sendto->reply(
				"Route #{$id} (" . $this->renderRoute($deleted) . ") deleted."
			);
		} else {
			$sendto->reply("Route <highlight>#${id}<end> deleted.");
		}
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route$/i")
	 * @Matches("/^route list$/i")
	 */
	public function routeList(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$routes = $this->messageHub->getRoutes();
		if (empty($routes)) {
			$sendto->reply("There are no routes defined.");
			return;
		}
		$list = [];
		foreach ($routes as $route) {
			$delLink = $this->text->makeChatcmd("delete", "/tell <myname> route del " . $route->getID());
			$list []="[{$delLink}] " . $this->renderRoute($route);
		}
		$blob = "<header2>Active routes<end>\n<tab>";
		$blob .= join("\n<tab>", $list);
		$msg = $this->text->makeBlob("Message Routes (" . count($routes) . ")", $blob);
		$sendto->reply($msg);
	}

	public function getRoute(int $id): ?Route {
		/** @var Route|null */
		$route = $this->db->table($this->messageHub::DB_TABLE_ROUTES)
			->where("id", $id)
			->limit(1)
			->asObj(Route::class)
			->first();
		if (!isset($route)) {
			return null;
		}
		$route->modifiers = $this->db->table(
			$this->messageHub::DB_TABLE_ROUTE_MODIFIER
		)
		->where("route_id", $id)
		->orderBy("id")
		->asObj(RouteModifier::class)
		->toArray();
		foreach ($route->modifiers as $modifier) {
			$modifier->arguments = $this->db->table(
				$this->messageHub::DB_TABLE_ROUTE_MODIFIER_ARGUMENT
			)
			->where("route_modifier_id", $modifier->id)
			->orderBy("id")
			->asObj(RouteModifierArgument::class)
			->toArray();
		}
		return $route;
	}

	/** Render the route $route into a single line of text */
	public function renderRoute(MessageRoute $route): string {
		$from = $route->getSource();
		$to = $route->getDest();
		$direction = $route->getTwoWay() ? "<->" : "->";
		return "<highlight>{$from}<end> {$direction} <highlight>{$to}<end> ".
			join(" ", $route->renderModifiers());
	}

	/** Get the type (the part before the bracket) of an emitter */
	public function getEmitterType(MessageEmitter $emitter): string {
		$type = $emitter->getChannelName();
		$bracket = strpos($type, "(");
		if ($bracket === false) {
			return $type;
		}
		return substr($type, 0, $bracket);
	}

	/** Render a blob for an emitter group */
	public function renderEmitterGroup(Collection $values, string $group): string {
		return "<header2>{$group}<end>\n<tab>".
			$values->map(fn(MessageEmitter $emitter) => $emitter->getChannelName())
				->join("\n<tab>");
	}
}
