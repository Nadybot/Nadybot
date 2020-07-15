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
 * @property \Budabot\Core\AMQP $amqp
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
 * @property \Budabot\Core\Modules\ADMIN\AdminController $adminController
 * @property \Budabot\Core\Modules\ALTS\AltsController $altsController
 * @property \Budabot\Core\Modules\BAN\BanController $banController
 * @property \Budabot\Core\Modules\BUDDYLIST\BuddylistController $buddylistController
 * @property \Budabot\Core\Modules\COLORS\ColorsController $colorsController
 * @property \Budabot\Core\Modules\CONFIG\AliasController $aliasController
 * @property \Budabot\Core\Modules\CONFIG\CommandlistController $commandlistController
 * @property \Budabot\Core\Modules\CONFIG\CommandSearchController $commandSearchController
 * @property \Budabot\Core\Modules\CONFIG\ConfigController $configController
 * @property \Budabot\Core\Modules\CONFIG\EventlistController $eventlistController
 * @property \Budabot\Core\Modules\CONFIG\SettingsController $settingsController
 * @property \Budabot\Core\Modules\DISCORD\DiscordController $discordController
 * @property \Budabot\Core\Modules\HELP\HelpController $helpController
 * @property \Budabot\Core\Modules\LIMITS\LimitsController $limitsController
 * @property \Budabot\Core\Modules\LIMITS\RateIgnoreController $rateIgnoreController
 * @property \Budabot\Core\Modules\PLAYER_LOOKUP\GuildManager $guildManager
 * @property \Budabot\Core\Modules\PLAYER_LOOKUP\PlayerHistoryManager $playerHistoryManager
 * @property \Budabot\Core\Modules\PLAYER_LOOKUP\PlayerLookupController $playerLookupController
 * @property \Budabot\Core\Modules\PLAYER_LOOKUP\PlayerManager $playerManager
 * @property \Budabot\Core\Modules\PREFERENCES\Preferences $preferences
 * @property \Budabot\Core\Modules\PROFILE\ProfileController $profileController
 * @property \Budabot\Core\Modules\SYSTEM\LogsController $logsController
 * @property \Budabot\Core\Modules\SYSTEM\RunAsController $runAsController
 * @property \Budabot\Core\Modules\SYSTEM\SendTellController $sendTellController
 * @property \Budabot\Core\Modules\SYSTEM\SQLController $sqlController
 * @property \Budabot\Core\Modules\SYSTEM\SystemController $systemController
 * @property \Budabot\Core\Modules\SYSTEM\UsageController $usageController
 * @property \Budabot\Modules\ALIEN_MODULE\AlienArmorController $alienArmorController
 * @property \Budabot\Modules\ALIEN_MODULE\AlienBioController $alienBioController
 * @property \Budabot\Modules\ALIEN_MODULE\AlienMiscController $alienMiscController
 * @property \Budabot\Modules\BANK_MODULE\BankController $bankController
 * @property \Budabot\Modules\BASIC_CHAT_MODULE\ChatAssistController $chatAssistController
 * @property \Budabot\Modules\BASIC_CHAT_MODULE\ChatCheckController $chatCheckController
 * @property \Budabot\Modules\BASIC_CHAT_MODULE\ChatLeaderController $chatLeaderController
 * @property \Budabot\Modules\BASIC_CHAT_MODULE\ChatRallyController $chatRallyController
 * @property \Budabot\Modules\BASIC_CHAT_MODULE\ChatSayController $chatSayController
 * @property \Budabot\Modules\BASIC_CHAT_MODULE\ChatTopicController $chatTopicController
 * @property \Budabot\Modules\BROADCAST_MODULE\BroadcastController $broadcastController
 * @property \Budabot\Modules\CITY_MODULE\CityWaveController $cityWaveController
 * @property \Budabot\Modules\CITY_MODULE\CloakController $cloakController
 * @property \Budabot\Modules\CITY_MODULE\OSController $osController
 * @property \Budabot\Modules\DEV_MODULE\CacheController $cacheController
 * @property \Budabot\Modules\DEV_MODULE\DevController $devController
 * @property \Budabot\Modules\DEV_MODULE\HtmlDecodeController $htmlDecodeController
 * @property \Budabot\Modules\DEV_MODULE\MdbController $mdbController
 * @property \Budabot\Modules\DEV_MODULE\SameChannelResponseController $sameChannelResponseController
 * @property \Budabot\Modules\DEV_MODULE\SilenceController $silenceController
 * @property \Budabot\Modules\DEV_MODULE\TestController $testController
 * @property \Budabot\Modules\DEV_MODULE\TimezoneController $timezoneController
 * @property \Budabot\Modules\DEV_MODULE\UnixtimeController $unixtimeController
 * @property \Budabot\Modules\EVENTS_MODULE\EventsController $eventsController
 * @property \Budabot\Modules\FUN_MODULE\DingController $dingController
 * @property \Budabot\Modules\FUN_MODULE\FightController $fightController
 * @property \Budabot\Modules\FUN_MODULE\FunController $funController
 * @property \Budabot\Modules\GIT_MODULE\GitController $gitController
 * @property \Budabot\Modules\GUIDE_MODULE\AOUController $aouController
 * @property \Budabot\Modules\GUIDE_MODULE\GuideController $guideController
 * @property \Budabot\Modules\GUILD_MODULE\GuildController $guildController
 * @property \Budabot\Modules\GUILD_MODULE\InactiveMemberController $inactiveMemberController
 * @property \Budabot\Modules\GUILD_MODULE\OrgHistoryController $orgHistoryController
 * @property \Budabot\Modules\HELPBOT_MODULE\HelpbotController $helpbotController
 * @property \Budabot\Modules\HELPBOT_MODULE\PlayfieldController $playfieldController
 * @property \Budabot\Modules\HELPBOT_MODULE\RandomController $randomController
 * @property \Budabot\Modules\HELPBOT_MODULE\ResearchController $researchController
 * @property \Budabot\Modules\HELPBOT_MODULE\TimeController $timeController
 * @property \Budabot\Modules\IMPLANT_MODULE\ClusterController $clusterController
 * @property \Budabot\Modules\IMPLANT_MODULE\ImplantController $implantController
 * @property \Budabot\Modules\IMPLANT_MODULE\ImplantDesignerController $implantDesignerController
 * @property \Budabot\Modules\IMPLANT_MODULE\PocketbossController $pocketbossController
 * @property \Budabot\Modules\IMPLANT_MODULE\PremadeImplantController $premadeImplantController
 * @property \Budabot\Modules\ITEMS_MODULE\BosslootController $bosslootController
 * @property \Budabot\Modules\ITEMS_MODULE\ItemsController $itemsController
 * @property \Budabot\Modules\ITEMS_MODULE\WhatBuffsController $whatBuffsController
 * @property \Budabot\Modules\LEVEL_MODULE\AXPController $axpController
 * @property \Budabot\Modules\LEVEL_MODULE\LevelController $levelController
 * @property \Budabot\Modules\NANO_MODULE\NanoController $nanoController
 * @property \Budabot\Modules\NEWS_MODULE\NewsController $newsController
 * @property \Budabot\Modules\NOTES_MODULE\LinksController $linksController
 * @property \Budabot\Modules\NOTES_MODULE\NotesController $notesController
 * @property \Budabot\Modules\ONLINE_MODULE\OnlineController $onlineController
 * @property \Budabot\Modules\ORGLIST_MODULE\FindOrgController $findOrgController
 * @property \Budabot\Modules\ORGLIST_MODULE\OrglistController $orglistController
 * @property \Budabot\Modules\ORGLIST_MODULE\OrgMembersController $orgMembersController
 * @property \Budabot\Modules\ORGLIST_MODULE\WhoisOrgController $whoisOrgController
 * @property \Budabot\Modules\PRIVATE_CHANNEL_MODULE\PrivateChannelController $privateChannelController
 * @property \Budabot\Modules\QUOTE_MODULE\QuoteController $quoteController
 * @property \Budabot\Modules\RAFFLE_MODULE\RaffleController $raffleController
 * @property \Budabot\Modules\RAID_MODULE\LootListsController $lootListsController
 * @property \Budabot\Modules\RAID_MODULE\RaidController $raidController
 * @property \Budabot\Modules\RECIPE_MODULE\RecipeController $recipeController
 * @property \Budabot\Modules\RELAY_MODULE\RelayController $relayController
 * @property \Budabot\Modules\REPUTATION_MODULE\KillOnSightController $killOnSightController
 * @property \Budabot\Modules\REPUTATION_MODULE\ReputationController $reputationController
 * @property \Budabot\Modules\SKILLS_MODULE\BuffPerksController $buffPerksController
 * @property \Budabot\Modules\SKILLS_MODULE\SkillsController $skillsController
 * @property \Budabot\Modules\SPIRITS_MODULE\SpiritsController $spiritsController
 * @property \Budabot\Modules\TEAMSPEAK3_MODULE\AOSpeakController $aoSpeakController
 * @property \Budabot\Modules\TEAMSPEAK3_MODULE\TeamspeakController $teamspeakController
 * @property \Budabot\Modules\TIMERS_MODULE\CountdownController $countdownController
 * @property \Budabot\Modules\TIMERS_MODULE\StopwatchController $stopwatchController
 * @property \Budabot\Modules\TIMERS_MODULE\TimerController $timerController
 * @property \Budabot\Modules\TOWER_MODULE\TowerController $towerController
 * @property \Budabot\Modules\TRACKER_MODULE\TrackerController $trackerController
 * @property \Budabot\Modules\TRICKLE_MODULE\TrickleController $trickleController
 * @property \Budabot\Modules\VOTE_MODULE\VoteController $voteController
 * @property \Budabot\Modules\WEATHER_MODULE\WeatherController $weatherController
 * @property \Budabot\Modules\WHEREIS_MODULE\WhereisController $whereisController
 * @property \Budabot\Modules\WHOIS_MODULE\FindPlayerController $findPlayerController
 * @property \Budabot\Modules\WHOIS_MODULE\PlayerHistoryController $playerHistoryController
 * @property \Budabot\Modules\WHOIS_MODULE\WhoisController $whoisController
 * @property \Budabot\Modules\WHOMPAH_MODULE\WhompahController $whompahController
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
