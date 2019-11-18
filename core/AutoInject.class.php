<?php

namespace Budabot\Core;

/**
 * Provides all derived classes with "magic" properties
 *
 * If someone requests a property that actually matches
 * a registered instance-name, have that automagically
 * be returned. So $this->commandManager would automatically
 * return the instance of \Budabot\Core\CommandManager
 * without the need to declare it.
 *
 * @todo Get rid of this anti-pattern
 * @property \Budabot\Core\AccessManager $accessManager
 * @property \Budabot\Core\AdminManager $adminManager
 * @property \Budabot\Core\Budabot $chatBot
 * @property \Budabot\Core\BuddylistManager $buddylistManager
 * @property \Budabot\Core\CacheManager $cacheManager
 * @property \Budabot\Core\CommandAlias $commandAlias
 * @property \Budabot\Core\CommandManager $commandManager
 * @property \Budabot\Core\DB $db
 * @property \Budabot\Core\EventManager $eventManager
 * @property \Budabot\Core\HelpManager $helpManager
 * @property \Budabot\Core\Http $http
 * @property \Budabot\Core\SettingManager $settingManager
 * @property \Budabot\Core\SettingObject $setting
 * @property \Budabot\Core\SocketManager $socketManager
 * @property \Budabot\Core\SubcommandManager $subcommandManager
 * @property \Budabot\Core\Timer $timer
 * @property \Budabot\Core\Util $util
 * @property \Budabot\Core\Modules\AdminController $adminController
 * @property \Budabot\Core\Modules\AliasController $aliasController
 * @property \Budabot\Core\Modules\AltsController $altsController
 * @property \Budabot\Core\Modules\BanController $banController
 * @property \Budabot\Core\Modules\BuddylistController $buddylistController
 * @property \Budabot\Core\Modules\ColorsController $colorsController
 * @property \Budabot\Core\Modules\CommandlistController $commandlistController
 * @property \Budabot\Core\Modules\CommandSearchController $commandSearchController
 * @property \Budabot\Core\Modules\ConfigController $configController
 * @property \Budabot\Core\Modules\EventlistController $eventlistController
 * @property \Budabot\Core\Modules\GuildManager $guildManager
 * @property \Budabot\Core\Modules\HelpController $helpController
 * @property \Budabot\Core\Modules\LimitsController $limitsController
 * @property \Budabot\Core\Modules\LogsController $logsController
 * @property \Budabot\Core\Modules\PlayerHistoryManager $playerHistoryManager
 * @property \Budabot\Core\Modules\PlayerLookupController $playerLookupController
 * @property \Budabot\Core\Modules\PlayerManager $playerManager
 * @property \Budabot\Core\Modules\Preferences $preferences
 * @property \Budabot\Core\Modules\ProfileController $profileController
 * @property \Budabot\Core\Modules\RunAsController $runAsController
 * @property \Budabot\Core\Modules\SendTellController $sendTellController
 * @property \Budabot\Core\Modules\SettingsController $settingsController
 * @property \Budabot\Core\Modules\SQLController $sqlController
 * @property \Budabot\Core\Modules\SystemController $systemController
 * @property \Budabot\Core\Modules\UsageController $usageController
 * @property \Budabot\Core\Modules\WhitelistController $whitelistController
 */
class AutoInject {
	/**
	 * If there is a registered instance with the same name as the attribute, return it
	 *
	 * @param string $name
	 * @return mixed
	 */
	public function __get($name) {
		if ($name == 'logger') {
			$tag = Registry::formatName(get_class($this));
			$instance = new LoggerWrapper($tag);
		} else {
			$instance = Registry::getInstance($name);
		}
		if ($instance !== null) {
			$this->$name = $instance;
		}
		return $this->$name;
	}
}
