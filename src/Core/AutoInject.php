<?php

namespace Nadybot\Core;

/**
 * Provides all derived classes with "magic" properties
 *
 * If someone requests a property that actually matches
 * a registered instance-name, have that automagically
 * be returned. So $this->commandManager would automatically
 * return the instance of \Nadybot\Core\CommandManager
 * without the need to declare it.
 *
 * @todo Get rid of this anti-pattern
 * @property \Nadybot\Core\AccessManager $accessManager
 * @property \Nadybot\Core\AMQP $amqp
 * @property \Nadybot\Core\AdminManager $adminManager
 * @property \Nadybot\Core\Nadybot $chatBot
 * @property \Nadybot\Core\BuddylistManager $buddylistManager
 * @property \Nadybot\Core\CacheManager $cacheManager
 * @property \Nadybot\Core\CommandAlias $commandAlias
 * @property \Nadybot\Core\CommandManager $commandManager
 * @property \Nadybot\Core\DB $db
 * @property \Nadybot\Core\EventManager $eventManager
 * @property \Nadybot\Core\HelpManager $helpManager
 * @property \Nadybot\Core\Http $http
 * @property \Nadybot\Core\SettingManager $settingManager
 * @property \Nadybot\Core\SettingObject $setting
 * @property \Nadybot\Core\SocketManager $socketManager
 * @property \Nadybot\Core\SubcommandManager $subcommandManager
 * @property \Nadybot\Core\Timer $timer
 * @property \Nadybot\Core\Util $util
 * @property \Nadybot\Core\Modules\ADMIN\AdminController $adminController
 * @property \Nadybot\Core\Modules\ALTS\AltsController $altsController
 * @property \Nadybot\Core\Modules\BAN\BanController $banController
 * @property \Nadybot\Core\Modules\BUDDYLIST\BuddylistController $buddylistController
 * @property \Nadybot\Core\Modules\COLORS\ColorsController $colorsController
 * @property \Nadybot\Core\Modules\CONFIG\AliasController $aliasController
 * @property \Nadybot\Core\Modules\CONFIG\CommandlistController $commandlistController
 * @property \Nadybot\Core\Modules\CONFIG\CommandSearchController $commandSearchController
 * @property \Nadybot\Core\Modules\CONFIG\ConfigController $configController
 * @property \Nadybot\Core\Modules\CONFIG\EventlistController $eventlistController
 * @property \Nadybot\Core\Modules\CONFIG\SettingsController $settingsController
 * @property \Nadybot\Core\Modules\DISCORD\DiscordController $discordController
 * @property \Nadybot\Core\Modules\HELP\HelpController $helpController
 * @property \Nadybot\Core\Modules\LIMITS\LimitsController $limitsController
 * @property \Nadybot\Core\Modules\LIMITS\RateIgnoreController $rateIgnoreController
 * @property \Nadybot\Core\Modules\PLAYER_LOOKUP\GuildManager $guildManager
 * @property \Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerHistoryManager $playerHistoryManager
 * @property \Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerLookupController $playerLookupController
 * @property \Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager $playerManager
 * @property \Nadybot\Core\Modules\PREFERENCES\Preferences $preferences
 * @property \Nadybot\Core\Modules\PROFILE\ProfileController $profileController
 * @property \Nadybot\Core\Modules\SYSTEM\LogsController $logsController
 * @property \Nadybot\Core\Modules\SYSTEM\RunAsController $runAsController
 * @property \Nadybot\Core\Modules\SYSTEM\SendTellController $sendTellController
 * @property \Nadybot\Core\Modules\SYSTEM\SQLController $sqlController
 * @property \Nadybot\Core\Modules\SYSTEM\SystemController $systemController
 * @property \Nadybot\Core\Modules\SYSTEM\UsageController $usageController
 * @property \Nadybot\Modules\ALIEN_MODULE\AlienArmorController $alienArmorController
 * @property \Nadybot\Modules\ALIEN_MODULE\AlienBioController $alienBioController
 * @property \Nadybot\Modules\ALIEN_MODULE\AlienMiscController $alienMiscController
 * @property \Nadybot\Modules\BANK_MODULE\BankController $bankController
 * @property \Nadybot\Modules\BASIC_CHAT_MODULE\ChatAssistController $chatAssistController
 * @property \Nadybot\Modules\BASIC_CHAT_MODULE\ChatCheckController $chatCheckController
 * @property \Nadybot\Modules\BASIC_CHAT_MODULE\ChatLeaderController $chatLeaderController
 * @property \Nadybot\Modules\BASIC_CHAT_MODULE\ChatRallyController $chatRallyController
 * @property \Nadybot\Modules\BASIC_CHAT_MODULE\ChatSayController $chatSayController
 * @property \Nadybot\Modules\BASIC_CHAT_MODULE\ChatTopicController $chatTopicController
 * @property \Nadybot\Modules\BROADCAST_MODULE\BroadcastController $broadcastController
 * @property \Nadybot\Modules\CITY_MODULE\CityWaveController $cityWaveController
 * @property \Nadybot\Modules\CITY_MODULE\CloakController $cloakController
 * @property \Nadybot\Modules\CITY_MODULE\OSController $osController
 * @property \Nadybot\Modules\DEV_MODULE\CacheController $cacheController
 * @property \Nadybot\Modules\DEV_MODULE\DevController $devController
 * @property \Nadybot\Modules\DEV_MODULE\HtmlDecodeController $htmlDecodeController
 * @property \Nadybot\Modules\DEV_MODULE\MdbController $mdbController
 * @property \Nadybot\Modules\DEV_MODULE\SameChannelResponseController $sameChannelResponseController
 * @property \Nadybot\Modules\DEV_MODULE\SilenceController $silenceController
 * @property \Nadybot\Modules\DEV_MODULE\TestController $testController
 * @property \Nadybot\Modules\DEV_MODULE\TimezoneController $timezoneController
 * @property \Nadybot\Modules\DEV_MODULE\UnixtimeController $unixtimeController
 * @property \Nadybot\Modules\EVENTS_MODULE\EventsController $eventsController
 * @property \Nadybot\Modules\FUN_MODULE\DingController $dingController
 * @property \Nadybot\Modules\FUN_MODULE\FightController $fightController
 * @property \Nadybot\Modules\FUN_MODULE\FunController $funController
 * @property \Nadybot\Modules\GIT_MODULE\GitController $gitController
 * @property \Nadybot\Modules\GUIDE_MODULE\AOUController $aouController
 * @property \Nadybot\Modules\GUIDE_MODULE\GuideController $guideController
 * @property \Nadybot\Modules\GUILD_MODULE\GuildController $guildController
 * @property \Nadybot\Modules\GUILD_MODULE\InactiveMemberController $inactiveMemberController
 * @property \Nadybot\Modules\GUILD_MODULE\OrgHistoryController $orgHistoryController
 * @property \Nadybot\Modules\HELPBOT_MODULE\HelpbotController $helpbotController
 * @property \Nadybot\Modules\HELPBOT_MODULE\PlayfieldController $playfieldController
 * @property \Nadybot\Modules\HELPBOT_MODULE\RandomController $randomController
 * @property \Nadybot\Modules\HELPBOT_MODULE\ResearchController $researchController
 * @property \Nadybot\Modules\HELPBOT_MODULE\TimeController $timeController
 * @property \Nadybot\Modules\IMPLANT_MODULE\ClusterController $clusterController
 * @property \Nadybot\Modules\IMPLANT_MODULE\ImplantController $implantController
 * @property \Nadybot\Modules\IMPLANT_MODULE\ImplantDesignerController $implantDesignerController
 * @property \Nadybot\Modules\IMPLANT_MODULE\PocketbossController $pocketbossController
 * @property \Nadybot\Modules\IMPLANT_MODULE\PremadeImplantController $premadeImplantController
 * @property \Nadybot\Modules\ITEMS_MODULE\BosslootController $bosslootController
 * @property \Nadybot\Modules\ITEMS_MODULE\ItemsController $itemsController
 * @property \Nadybot\Modules\ITEMS_MODULE\WhatBuffsController $whatBuffsController
 * @property \Nadybot\Modules\LEVEL_MODULE\AXPController $axpController
 * @property \Nadybot\Modules\LEVEL_MODULE\LevelController $levelController
 * @property \Nadybot\Modules\NANO_MODULE\NanoController $nanoController
 * @property \Nadybot\Modules\NEWS_MODULE\NewsController $newsController
 * @property \Nadybot\Modules\NOTES_MODULE\LinksController $linksController
 * @property \Nadybot\Modules\NOTES_MODULE\NotesController $notesController
 * @property \Nadybot\Modules\ONLINE_MODULE\OnlineController $onlineController
 * @property \Nadybot\Modules\ORGLIST_MODULE\FindOrgController $findOrgController
 * @property \Nadybot\Modules\ORGLIST_MODULE\OrglistController $orglistController
 * @property \Nadybot\Modules\ORGLIST_MODULE\OrgMembersController $orgMembersController
 * @property \Nadybot\Modules\ORGLIST_MODULE\WhoisOrgController $whoisOrgController
 * @property \Nadybot\Modules\PRIVATE_CHANNEL_MODULE\PrivateChannelController $privateChannelController
 * @property \Nadybot\Modules\QUOTE_MODULE\QuoteController $quoteController
 * @property \Nadybot\Modules\RAFFLE_MODULE\RaffleController $raffleController
 * @property \Nadybot\Modules\RAID_MODULE\LootListsController $lootListsController
 * @property \Nadybot\Modules\RAID_MODULE\RaidController $raidController
 * @property \Nadybot\Modules\RECIPE_MODULE\RecipeController $recipeController
 * @property \Nadybot\Modules\RELAY_MODULE\RelayController $relayController
 * @property \Nadybot\Modules\REPUTATION_MODULE\KillOnSightController $killOnSightController
 * @property \Nadybot\Modules\REPUTATION_MODULE\ReputationController $reputationController
 * @property \Nadybot\Modules\SKILLS_MODULE\BuffPerksController $buffPerksController
 * @property \Nadybot\Modules\SKILLS_MODULE\SkillsController $skillsController
 * @property \Nadybot\Modules\SPIRITS_MODULE\SpiritsController $spiritsController
 * @property \Nadybot\Modules\TEAMSPEAK3_MODULE\AOSpeakController $aoSpeakController
 * @property \Nadybot\Modules\TEAMSPEAK3_MODULE\TeamspeakController $teamspeakController
 * @property \Nadybot\Modules\TIMERS_MODULE\CountdownController $countdownController
 * @property \Nadybot\Modules\TIMERS_MODULE\StopwatchController $stopwatchController
 * @property \Nadybot\Modules\TIMERS_MODULE\TimerController $timerController
 * @property \Nadybot\Modules\TOWER_MODULE\TowerController $towerController
 * @property \Nadybot\Modules\TRACKER_MODULE\TrackerController $trackerController
 * @property \Nadybot\Modules\TRICKLE_MODULE\TrickleController $trickleController
 * @property \Nadybot\Modules\VOTE_MODULE\VoteController $voteController
 * @property \Nadybot\Modules\WEATHER_MODULE\WeatherController $weatherController
 * @property \Nadybot\Modules\WHEREIS_MODULE\WhereisController $whereisController
 * @property \Nadybot\Modules\WHOIS_MODULE\FindPlayerController $findPlayerController
 * @property \Nadybot\Modules\WHOIS_MODULE\PlayerHistoryController $playerHistoryController
 * @property \Nadybot\Modules\WHOIS_MODULE\WhoisController $whoisController
 * @property \Nadybot\Modules\WHOMPAH_MODULE\WhompahController $whompahController
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
