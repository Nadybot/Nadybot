<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Modules\DISCORD\DiscordAPIClient;
use Nadybot\Core\Modules\DISCORD\DiscordChannel;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SchemaMigration;
use Nadybot\Core\SettingManager;
use Nadybot\Modules\TIMERS_MODULE\TimerController;
use Throwable;

class MigrateToRoutes implements SchemaMigration {
	/** @Inject */
	public DiscordAPIClient $discordAPIClient;

	/** @Inject */
	public MessageHub $messageHub;

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = TimerController::DB_TABLE;
		$db->schema()->table($table, function(Blueprint $table) {
			$table->string("mode", 50)->nullable()->change();
		});
		$defaultChannel = $this->getSetting($db, 'timer_alert_location');
		if (!isset($defaultChannel)) {
			$defaultChannel = 3;
		} else {
			$defaultChannel = (int)$defaultChannel->value;
		}
		$defaultMode = [];
		if ($defaultChannel & 1) {
			$this->addRoute($db, Source::PRIV . "(" . $db->getMyname() . ")");
			$defaultMode []= "priv";
		}
		if ($defaultChannel & 2) {
			$this->addRoute($db, Source::ORG);
			$defaultMode []= "org";
		}
		if ($defaultChannel & 4) {
			$discordCannel = $this->getSetting($db, "discord_notify_channel") ?? null;
			if (isset($discordCannel) && $discordCannel->value !== 'off') {
				$this->discordAPIClient->getChannel(
					$discordCannel->value,
					[$this, "migrateChannelToRoute"],
					$db,
				);
				$defaultMode []= "discord";
			}
		}
		sort($defaultMode);
		$timerIds = $db->table($table)
			->asObj()
			->filter(function ($timer): bool {
				if (!isset($timer->mode) || !preg_match("/^timercontroller/", $timer->callback)) {
					return false;
				}
				if ($timer->mode === 'msg') {
					return false;
				}
				return true;
			})->pluck("id")
			->toArray();
		if (count($timerIds)) {
			$db->table($table)
				->whereIn("id", $timerIds)
				->update(["mode" => null]);
		}
	}

	public function migrateChannelToRoute(DiscordChannel $channel, DB $db): void {
		$route = $this->addRoute(
			$db,
			Source::DISCORD_PRIV . "({$channel->name})",
		);
		try {
			$msgRoute = $this->messageHub->createMessageRoute($route);
			$this->messageHub->addRoute($msgRoute);
		} catch (Throwable $e) {
			// Ain't nothing we can do, errors will be given on next restart
		}
	}

	protected function addRoute(DB $db, string $to): Route {
		$route = new Route();
		$route->source = Source::SYSTEM . "(timers)";
		$route->destination = $to;
		$route->id = $db->insert(MessageHub::DB_TABLE_ROUTES, $route);
		return $route;
	}
}
