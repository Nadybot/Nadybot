<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{
	Attributes as NCA,
	DB,
	DBSchema\Route,
	DBSchema\Setting,
	MessageHub,
	Modules\DISCORD\DiscordAPIClient,
	Modules\DISCORD\DiscordChannel,
	Routing\Source,
	SchemaMigration,
	SettingManager,
};
use Nadybot\Modules\TIMERS_MODULE\TimerController;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

#[NCA\Migration(order: 20_210_908_074_258)]
class MigrateToRoutes implements SchemaMigration {
	#[NCA\Inject]
	private DiscordAPIClient $discordAPIClient;

	#[NCA\Inject]
	private MessageHub $messageHub;

	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = TimerController::DB_TABLE;
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->string('mode', 50)->nullable()->change();
			$table->string('origin', 100)->nullable();
		});
		$defaultChannel = $this->getSetting($db, 'timer_alert_location');
		if (!isset($defaultChannel)) {
			$defaultChannel = 3;
		} else {
			$defaultChannel = (int)$defaultChannel->value;
		}
		$defaultMode = [];
		if ($defaultChannel & 1) {
			$this->addRoute($db, Source::PRIV . '(' . $db->getMyname() . ')');
			$defaultMode []= 'priv';
		}
		if ($defaultChannel & 2) {
			$this->addRoute($db, Source::ORG);
			$defaultMode []= 'org';
			$defaultMode []= 'guild';
		}
		if ($defaultChannel & 4) {
			$defaultMode []= 'discord';
		}
		$discordChannel = $this->getSetting($db, 'discord_notify_channel') ?? null;
		if (isset($discordChannel, $discordChannel->value)   && $discordChannel->value !== 'off') {
			try {
				$channel = $this->discordAPIClient->getChannel($discordChannel->value);
				$this->migrateChannelToRoute($channel, $db, $table, $defaultMode);
			} catch (Throwable) {
			}
			return;
		}
		$this->rewriteTimerMode($db, $table, $defaultMode);
	}

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where('name', $name)
			->asObj(Setting::class)
			->first();
	}

	/** @param string[] $defaultMode */
	private function rewriteTimerMode(DB $db, string $table, array $defaultMode, ?string $discord=null): void {
		sort($defaultMode);
		$db->table($table)
			->get()
			->each(static function (stdClass $timer) use ($defaultMode, $table, $db, $discord): void {
				if (!isset($timer->mode) || !str_starts_with($timer->callback, 'timercontroller')) {
					return;
				}
				if ($timer->mode === 'msg') {
					return;
				}
				$timerMode = explode(',', $timer->mode);
				sort($timerMode);
				$modeDiff = array_values(array_diff($timerMode, $defaultMode));
				if (count($modeDiff) > 1) {
					return;
				}
				$update = ['mode' => null];
				if (count($modeDiff) === 1) {
					if ($modeDiff[0] === 'priv') {
						$update['origin'] = Source::PRIV . '(' . $db->getMyname() . ')';
					} elseif ($modeDiff[0] === 'org' || $modeDiff[0] === 'guild') {
						$update['origin'] = Source::ORG;
					} elseif ($modeDiff[0] === 'discord') {
						$update['origin'] = $discord;
					}
				}
				$db->table($table)
					->where('id', $timer->id)
					->update($update);
			});
	}

	/** @param string[] $defaultMode */
	private function migrateChannelToRoute(DiscordChannel $channel, DB $db, string $table, array $defaultMode): void {
		$this->rewriteTimerMode($db, $table, $defaultMode, Source::DISCORD_PRIV . "({$channel->name})");
		if (!in_array('discord', $defaultMode)) {
			return;
		}
		$route = $this->addRoute(
			$db,
			Source::DISCORD_PRIV . "({$channel->name})",
		);
		try {
			$msgRoute = $this->messageHub->createMessageRoute($route);
			$this->messageHub->addRoute($msgRoute);
		} catch (Throwable) {
			// Ain't nothing we can do, errors will be given on next restart
		}
	}

	private function addRoute(DB $db, string $to): Route {
		$route = new Route();
		$route->source = Source::SYSTEM . '(timers)';
		$route->destination = $to;
		$route->id = $db->insert(MessageHub::DB_TABLE_ROUTES, $route);
		return $route;
	}
}
