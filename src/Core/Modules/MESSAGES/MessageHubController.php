<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\MESSAGES;

use Illuminate\Support\Collection;
use Nadybot\Core\CommandReply;
use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageEmitter;
use Nadybot\Core\MessageHub;
use Nadybot\Core\MessageRoute;
use Nadybot\Core\Nadybot;
use Nadybot\Core\SettingManager;
use Nadybot\Core\Text;
use Nadybot\Core\Util;

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
		$this->db->table($this->messageHub::DB_TABLE_ROUTES)
			->orderBy("id")
			->asObj(Route::class)
			->each(function(Route $route): void {
				$route = new MessageRoute($route);
				$this->messageHub->addRoute($route);
			});
	}

	/**
	 * @HandlesCommand("route")
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
		$route->id = $this->db->insert($this->messageHub::DB_TABLE_ROUTES, $route);
		$route = new MessageRoute($route);
		$this->messageHub->addRoute($route);
		$sendto->reply(
			"Route added from <highlight>{$args["from"]}<end> ".
			"to <highlight>{$args["to"]}<end>."
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
	 * @Matches("/^route del (\d+)$/i")
	 */
	public function routeDel(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];
		$table = $this->messageHub::DB_TABLE_ROUTES;
		if (!$this->db->table($table)->where("id", $id)->exists()) {
			$sendto->reply("No route <highlight>#{$id}<end> found.");
			return;
		}
		$this->db->table($table)->delete($id);
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
			$list []= $this->renderRoute($route);
		}
		$blob = "<header2>Active routes<end>\n<tab>";
		$blob .= join("\n<tab>", $list);
		$msg = $this->text->makeBlob("Message Routes (" . count($routes) . ")", $blob);
		$sendto->reply($msg);
	}

	/** Render the route $route into a single line of text */
	public function renderRoute(MessageRoute $route): string {
		$from = $route->getSource();
		$to = $route->getDest();
		$direction = $route->getTwoWay() ? "<->" : "->";
		return "<highlight>{$from}<end> {$direction} <highlight>{$to}<end>";
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
