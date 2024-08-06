# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The AOChatProxy is obsolete, because Nadybot finally supports multiple connections all by itself. The connection now consists of a main character and 0 or more workers.
- The playfields supported by the bot for the `!waypoint`- and `!whereis`-command has increased drastically.
- Use our own server (with the old one being a fallback) for the `!history`-command, making it a lot faster.
- Support hyperlinks in the console on terminals supporting this feature.

### Changed

- Results of `!flatroll` are now using standard color theme and the text to show on items where no one added is configurable, defaulting to `-` in order to easier spot actual winners.
- The default file format of the configuration file has changed from php to toml.
- Don't show links to `!config` if you cannot run it anyway.
- The `!verify`-command will now show the results of the last 3 rolls, because it would be impossible to guess the previous UUID

### Coding

- The whole Amphp2-subsystem was updated to Amphp3.
- The webserver now uses the Amphp webserver.
- The websocket server now uses the Amphp webserver.
- All filesystem-calls are now async.
- Don't block on orglist updates anymore
- Apply stricter checks and rules for everything
- All exceptions will now be logged including the previous exceptions leading to them.
- Importer and exporter don't use a schema-checker anymore, but rather import into proper classes.
- Remove all redundant libraries and only keep a single one for each type
- Profession, Faction, and Playfield are now Enums
- No uninitialized properties anymore. All non-injected properties without default are now part of the constructor
- Switched from auto incrementing columns to UUID7 IDs

### Fixed

- Don't cache chars from other dimensions, overwriting our own id

## [6.2.8] - 2024-02-21

### Added

- Added 2 http-endpoints at `/livez` and `/readyz` for Kubernetes probes.
- Replace `leaderecho_color` with new setting `leader_echo_format`, which accepts a template-string, so you can make leaderecho look any way you want to.
- Support for Highway 0.2-protocol alongside 0.1
- Add settings to globally turn off colors for org-channel, private channels, and tells, except for messages relayed into these.

### Changed

- The `!greeting`-system has been revamped to take a `key=value`-pair as the first greeting word. `key` can be name, main, prof, gender, breed or faction. This allows custom greetings for individual chars, players, or professions. `!greeting add prof=doc What's up doooc?` is finally possible.
- The http-client will now automatically retry 429 codes (too many requests) with various delay between the intervals.
- `!raid add` now allows to give more than one character name to add to the raid.
- The `!raid notin`-command will now have a link next to each player's name to add them to the raid, as well as an "add all to the raid"-link.

### Fixed

- The Discord command endpoint now also honors banned players.

## [6.2.7] - 2023-12-02

### Added

- Added new docker-option `CONFIG_ORG_ID` to specify the org id of the `CONFIG_ORG`. This can be used to make members of the given org members of the bot, without the bot actually being in the org.
- New command `!raffle add <raffle string>` to add items to a running raffle (or start a new one if none is currently running). If the same item is already being raffled, the amount will be increased.
- Added raffle-links to the output of `LOOT_MODULE`. All 3 links (`!loot add`, `!bid start`, and `!raffle add`) can now be disabled in settings for a more compact output.
- Added option to `NANO_MODULE` to add the nano id to the output list.
- Added option to choose between aoitems.com and auno.org as link for items/nanos on Discord.

### Fixed

- `!nw hot` was interpreting every number as seconds into the future. Now you always have to give "s", or "secs" to achieve this, while a sheer number will be the level of your character and search for towers in range accordingly.
- In case of the outgoing Discord-connection to send messages being interrupted, the queue was stuck forever and didn't move forward.

## [6.2.6] - 2023-11-05

### Added

- Add new command `!lastonline <char>` as non-org equivalent for `!lastseen`
- Add new setting `orglist_pork_url` to allow downloading orglists from our reverse proxy. This solves PORK being down very often lately.
- Add new settings to allow automatically sending random greetings to players joining the private channel.
- Add option to set a directory for dynamic commands. These are merely text files or directories with text files.
- Wishes can now have an expiration. Per wish and a global maximum.
- Added S42 loot with `!42 west|north|east|boss` and `!apf 42 west|north|east|boss`
- Add new command `!group` to group people into equally-sized groups.
- New command `!symbbuffs <skill>` to search for symbiants buffing a given skill.
- Add new setting to only give timer-based raid-points if the raid is locked.
- Allow configuring the auction-message when no one bid on the item.
- Add the total required nanoskill to cast all those in `!bestnanos`
- New command `!implantshoppinglist` to get a list which implants and clusters to buy at which QL.
- Add new option `pause` and `resume` to `!raid ticker`. This allows to pause and resume the generation of time-based points. The default state can also be configured with a new setting.
- Add new setting `bid_presets` to allow setting pre-defined bid-values that are being shown in the auction information window.
- It's now possible to see the trickle when simultaneously increasing all attributes by a given value with `!trickle all <value>`.
- The `!count`-commands now take an optional `raid` parameter to only consider characters currently in the raid.

### Changed

- Adding an org to the banlist will now automatically kick any members from the bot's private channel.
- `!roll` can now also roll items, not only player names.
- `!cluster` now uses the same skill aliases as `!whatbuffs`.
- Add TOTW, IS, and TOTW-Raid symbiants to the symbiant DB.
- Make all worldboss-messages customizable via templates.
- Also show current nano casting time for `!nanoinit`-command.
- The `!leaders`-command won't show SuperAdmins by default. A new option `leaders_include_super_admins` needs to be set for this.
- The `!nw hot`-command now accepts an arbitrary duration to check into the future.
- Superadmins are now a group of their own in `!admins` and `!leaders`.
- Increased Docker default PHP-memory from 128MB to 192MB.

### Fixed

- The `!points add <points> <char> <reason>` syntax works again.
- `!points spp` did not set the timer, now it does.
- Fix auction links in the LOOT_MODULE.
- Banlist manipulation on older bots was slow and used too much memory
- Fix rare occasion of Highway-connection going stale.
- Use interface instead of object, so restarting the bot throws no errors when accessing the chat queue.

## [6.2.5] - 2023-08-16

### Added

- Add 2 new options to customize error-messages when a command isn't executed due to missing access rights.

### Fixed

- Worldboss-spawn messages don't spam anymore.

## [6.2.4] - 2023-08-06

### Changed

- Switch to Alpine Linux 3.18 as base for the Docker images.
- Use there whereis-database for MOB_MODULE coordinates.
- If the Discord bot-user doesn't have the rights to manage emojis, fail gracefully and give a meaningful error.

### Added

- Add new protocol "Drill" for the Nadybot webserver. This allows exposing the webserver of the bot to an external host (`<botname>.nadybotter.eu` or `<botname>.nadybotter.org`), without exposing the whole server.
  This also makes the bot HTTP-traffic fully encrypted with a valid certificate.
- Added Highnet, a cross-bot chat-platform similar to Darknet, but with full local control.
- Allow automatic untracking of characters who haven't logged in for a configurable amount of time.
- Add support for continuously updated WHOIS-information via Highway, so newly created characters are not unknown and the information available faster.
- Allow `!whois` on UIDs
- Add new `!raidrules` command to allow having separate bot- and raid-rules.

### Fixed

- Reconnecting from JSON-parse errors did not close the Highway-connection.

## [6.2.3] - 2023-05-20

### Added

- Add new optional parameter `<dimension>` to `!whois`.
- When creating a poll, adding `||` At the end of the options will now disallow the usage of custom options.
- Support for Discord scheduled events via `!discord events` or the new `discord(*)` routing source.

### Changed

- The async online-checker now has a timeout of 30s after which it will consider the searched char to be offline.
- Instead of <http://people.anarchy-online.com>, the bot now uses <https://bork.aobots.org> by default, to retrieve user and org data. This can be changed in `!config PLAYER_LOOKUP` to any URL now.

### Fixed

- Calculation of out-of-range status fixed when restarting the bot.
- Polls with numeric choices would not display at all.
- When viewing a poll, it would always say that you haven't voted yet.
- Fix `{c-nick}` being empty on private channel join/leave messages.
- Fix package upgrades on newer Ubuntu versions wiping the bot's database.

## [6.2.2] - 2023-04-21

### Fixed

- Calculation of cloak reminder was occasionally off and logged twice a minute.
- Members of orgs in the orgbanlist were still receiving `!massmsg`-messages.
- `!orglist` was broken and would just fill the buddylist. Fixed now.
- `!system` will now show the correct Filesystem driver.

### Changed

- Every non-Linux OS will now default to the Blocking Filesystem driver, causing less problems and less load.

## [6.2.1] - 2023-04-15

### Added

- Add option `discord_custom_emojis` to enable our own Discord emojis for factions and professions. Enabled by default.
- Added `!jack` / `!legchopper` command to track Jack Legchopper and his clones.
- Added `!orgstats` command for some statistics about your organization.

### Changed

- Turning on a connect event after startup will now automatically trigger it.
- The hags are now "Clan Hag X" and "Omni Hag X", so the spawn messages make sense now.

## [6.2.0] - 2023-04-06

### Added

- New option `auto_guild_name` to allow automatic update of the org name if a change is detected.
- Add new command `!altsadmin` to manage the alts of someone else. Default is restricted to admin access level.
- New option `raffle_allow_multi_join` to forbid joining a raffle for more than one item at the same time - also counting alts.
- `aotell()` now allows messaging access levels by prepending them with a `@`. So `aotell(@mod)` as a receiver will send a message to all mods online.
- Add new module `PVP_MODULE` that provides a completely different way to interact with a new Tower-API. It can be used alongside the old `TOWER_MODULE`, because all `PVP-MODULE`-commands are subcommands of `!nw`. To get a list of all hot sites, use `!nw hot`, and so on. See <https://github.com/Nadybot/Nadybot/wiki/PVP> for details.
- Add new module `MOB_MODULE` that will automatically track some heavily-farmed NPCs on RK. It gives you their current state and HP and you can even have the module send messages for spawns, kills and people starting to attack it. Curreently supported are:
  - Milky Way prisoners
  - Clan and Omni Dreadloch camps
  - Clan and Omni Biodome hags
  - Ljotur and his placeholders
  - Otacustes and his placeholders

### Changed

- Using new unfreezing method on <https://account.anarchy-online.com> since the old website now redirects here.
- It is no longer possible to join a raffle for the same item with an alt.
- `!updateorg` will now force a re-download from PORK and never use a cached JSON-file.
- If an org roster update would remove over 30% of the org's members, that update is postponed until it happened 3 times in a row.
- Show the GMI-Link only in the popup of the `!nano`-command

## [6.1.2] - 2023-02-12

### Added

- Add a new field `in_game` to the item database, so items that are not in the game, available to ARK/GM only, or are generally not applicable to the results (like tower wrist items) can be excluded from the `!items`- and `!whatbuffs` search. Also add a new setting `only_items_in_game` to turn the default exclusion on/off and do not exclude these, when the search is prefixed with a `*`
- Add new `!ffa`-command that announces the items after a `!flatroll` as free for all.
- Support for the long-existing update_notifications feed to inform about security updates and scheduled downtimes.
- Allow selecting the filesystem driver by setting `AMP_FS_DRIVER` to `\Amp\File\Driver\BlockingDriver` or any of its alternatives.
- Add a new config-file options `auto_unfreeze`. If set, the bot will try to automatically unfreeze the account in case we get a frozen-message at the login. To work around a limitation of Funcom that you cannot unfreeze more than 5 accounts per IP-address within 24h, the whole process uses a special Nadybot proxy server until it succeeds. use `CONFIG_AUTO_UNFREEZE=true` to enable this feature via Docker.
- Add links to a character's history to the `!whois <name>` output.
- Add a new option `nano_add_gmi` (on by default) that will add a GMI-link to all results of the `!nano`- or `!nanoline`-commands.

### Changed

- Global event feed is no longer implemented as a hidden relay, but completely from scratch to allow any number of feed subscribers and doesn't require the `RELAY_MODULE` to be enabled.
- When grouping the online list by player/main, show the actual unique number of main characters of all online providers in brackets. If one character is online in the private channel and the org channel, this means it won't be counted twice anymore.

## [6.1.1] - 2023-01-07

### Added

- Add new commands `!bestnanos` and `!bestnanosfroob` to get a list of the best nanos for your current or a given profession/level. Use `!bn`/`!bnf` or `!bnl`/`!bnfl` for the long version if you are lazy.
- Support multi-platform Docker images, currently arm64 and amd64
- The `!icc`-command can now also be used to say that the arbiter is currently not here, but will be here on Sunday. Use `!icc set bs next` to specify that the upcoming arbiter week will be PvP-week.
- Allow to fully customize join/leave and logon/logoff messages with a little template-like language that supports `{?if:}` and `{!ifnot:}` syntax
- Instead of limiting logon/logoff and join/leave messages to the first and last logon/logoff join/leave of a player, you can now define an interval in which only one of those is shown. Settings this to 5 minutes means: During a moving 5 minute interval, only display the first and last logon/logoff join/leave message. This makes it easier to suppress multiboxer spam without affecting the regular messages too much.
- `!whois <name>` and `!checkaccess <name>` / `!checkaccess` now show when someone is banned.
- You can now search in the banlist with `!banlist <pattern>`, where `<pattern>` can contain wildcards like `*`. So `!banlist nad*` will search for all player whose name starts with `nad`.
- Support multi-platform docker images, starting with amd64 and arm64, so they also work on the Raspberry Pi.
- Introduce nicknames. Nicknames can be set freely and will be displayed at various locations instead of the character's or the main's name (e.g. online list). There is also a new configuration knob (`!settings change routed_sender_format`) that allows displaying the nickname when displaying routed messages (e.g. from guild channel to/from private channel)
- Add support for JSON and YAML configuration files
- Add new `debug()`-modifier to send the internal package representation to a routing destination for debugging purposes.
- Add new `!gmi`-command to check prices on GMI. Works with names, IDs and dragged items.

### Changed

- Repeating timers now reuse their timer id, so when their repetition interval is very short, you can always use the same link to remove them.
- The handling of AO packets has been changed so that only 1 packet is processed at the same time. Prevents choking processing other streams like downloads or Discord relays in case of a lot of packages arriving at the same time.
- Drastically speed up `!track addorg <id>` by pre-populating all entries with a `<name> logged off` event instead of doing this when the logoff-event hits the bot.
- Only cache org-data in the file cache for 24h after the last org update at Funcom.
- In the item search, the non-existing Small Ebony Figurines will now be marked accordingly.

### Fix

- The implant designer can once again set the QL of a slot even if it's currently empty.
- Fix for Discord relay when messages contain empty categories

### Coding

- Started moving from DTOs to hydrators

## [6.1.0] - 2022-08-24

### Added

- Commands and events can now be declared as Generators, which automatically makes them execute async and allows to `yield` results from promises.
- Add a management-interface as an addition to the console. The management interface does not appear in the routes and currently supports tcp- and unix domain sockets.
- `!track info <name>` now also shows who added a character to the tracker.
- Allow muting (temporarily disabling) of routes
- Add the new RK19 worldbosses that spawn on the birthday event as non-repeating worldboss-timers, because they only have a chance to spawn, so we never know if a spawn was skipped or it just took longer to kill the last spawn.
  Also added waypoints and links to AOU-articles to each worldboss.
- Add a new news-time "all-boss-timers" that will also show seasonal non-guaranteed spawns, like Desert Rider, Zaal, etc.
- The SPAWNTIME_MODULE is now integrated into the WHEREIS_MODULE, giving everyone access to `!spawn <name>` to query a mob's respawn-timer.
- Add a new command `!updatewb` to update the world bosses from the API. Usually, this should not be necessary, because the global timer events should update these automatically, but this aids in debugging.
- New event modifier "route-silently()" will make the message hub treat routed messages as if they hadn't been routed. This will allow you to route aotell() and still have the bot react to commands in the message.
- Add `!wish`-command to manage wishlists
- Add 2 new options for raid rewards: `max_raid_reward_height` to limit the amount of points `!raid reward` and `!raid punish` can give/take (does not apply to pre-defined raid-rewards) and `raid_reward_predefined_only` to limit `!raid reward` and `!raid punish` to names of raid rewards instead of arbitrary points.
- Add `!sites ql <ql>` to search for unplanted sites able to hold towers of a given QL.
- Sharing online-lists via nadynative will now also share each character's main. This allows two new grouping options for relay online lists: by main (player) and by org, then main (org/player). This only works properly if all Nadybots are running the same version.
- Add `!fact`-command to show a random useless fact.
- Add an option to automatically remove members from the bot if they haven't raided on any of their alts for a set period.

### Changed

- Replaced the EventLoop with an Amp (amphp) event loop. Migrated the following functionality to Amp:
  - The AO-connection
  - Cron-events
  - Timer->callLater()
  - SocketManager
  - Relay websockets
  - Discord
  - EventLoop::add()
  - Http-client is now being replaced with HttpClientBuilder, while the old Http/AsyncHttp still functions, but is now deprecated.

  This leads to even lower delay when processing Discord/Web/Console/AO packages, and the bot's CPU usage dropping to 0 in idle, compared to ~1%-2% before.
  A whole lot of functions are now deprecated and will be removed or replaced in 7.0, while none of the core function signatures has changed.
- Upgraded Docker images to alpine 3.16
- Detection of when to display a QL for an item will now automatically detect symbiants and spirits and print their QL, even if they only exist in 1 QL.
- The worldboss timers now ignore any updates for different dimensions.
- Add new attributes to track when people became members of the bot and who added them. This is in addition to the audit functionality. Upgrading to this version will try to determine the date by using the audit tables, or assume "since now".
- Use Discord's `resume_gateway_url` property instead of querying the Gateway each time, when resuming connections.

### Removed

- The `!updatecsv`-command was removed, because it hasn't been any use yet, and given how easy it is to upgrade the bot, there's no need to keep it and its complex mechanism.

### Fix

- The console history works 100% now, only ctrl+r-search is now broken.
- The bot now correctly sets the name of the character who added someone to the tracker.
- The timer you can enable when a tower field goes down sometimes couldn't be created, because a timer with the same name was already there. Any timer with the same name will now be deleted before creating a new one.
- Not grouping relay online lists gives a proper "Alliance"-group again

## [6.0.5] - 2022-06-17

### Added

- You can now sync bans and unbans via nadynative
- When changing the message that should be send to Discord whenever your own towers are being attacked, the bot will now warn if there is no route in place to actually make use of this message.
- Add a link to bank browse- and search-results to ask the bank character to give you a specific item, including its location.

### Changed

- The default discord notification for own towers being attack has been change to off.
- You can no longer create Discord invites with `!discord join` if your account is already linked.

### Fix

- Add back the "loot" and "auction" links to loot lists
- Automatically ignore if someone managed to set themselves as their own alt. This allows them to run `!alts setmain` on any of their alts again.

## [6.0.4] - 2022-06-05

### Added

- Allow specifying the ql or ql-range to search for when using `!bank search`

### Fix

- The `TrackerFormatHandler` was moved to its correct namespace.
- The nadybot-big image works again as expected.
- Fix `!orglist` for RK19

## [6.0.3] - 2022-05-27

### Added

- New setting `add_header_ranges`. If enabled, in addition to Page X/Y, it will print the (sub-)header-ranges in that page (ADMIN -> TOWER_MODULE) as well, if the page is streuctured like that
- Add support for Discord Slash-commands. The setting `discord_slash_commands` determines if they are disabled, only visible to the person sending them, or treated like a regular command and routed from/to the bot-channels.
  Because Discord allows a maximum of 100 global slash-commands, you have to use `!discord slash add|rem|pick` to configure which commands will be exposed. By default, some are exposed already that most people will probably want to, but this won't apply to everyone.
- The name of every access-level-rank can now be changed freely.
- The `!leaders`-command can now be configured to also show the admins and mods.
- The colors that `!online` displays for ranks raid_leader and upwards, are now customizable.
- Make "on"- and "off"-colors a configurable setting in the `COLORS` module
- Add settings to configure the colors of `!tell`, `!cmd` and `!topic`.
- Allow banning orgs without giving a reason

### Changed

- The `!track online`-command got a real parser now and supports filtering by level (ranges), title level (ranges), faction(s) and profession(s).
- The message that's displayed when a tracked character goes on/offline is now completely configurable with {placeholders}. To display this properly, a new setting type `tracker_format` has been introduced that will display rendered and unrendered versions of the setting. There is also logic to remove `{org}` from the message is the character is not member of an org.
- Gracefully support URLs for bank-CSV location. Download will be async with proper error handling.
- Raid ranks in online-list are also shown for access-level "guild".
- Joining and leaving voice chats will now display the linked AO character, if available.
- Change the default "disabled/off"-color to a slightly lighter shade of red.

### Fix

- Due to a logic error, once a websocket connection timed out, chances were, it would constantly timeout again.
- `!events setdate <id> <date>` now understands a lot more date-formats.
- Browsing bank backpacks accidentally showed each backpack as often as items were in the backpack.
- Fix a non-critical error-message when running `!lc <tower site>` which would have turned into a hard error in PHP 8.2

## [6.0.2] - 2022-05-10

### Added

- Add a new property to raids, that allows to limit the maximum number of raiders. Can be set with either `!raid start <description> limit <max members>` or `!raid limit <max members>`.
- New command `!orgnote` to manage org-wide notes that can also be shared via Nadynative protocol.
- New Docker image `nadybot-big` which includes the AOChatProxy, so only 1 container is needed to run bots with more than 1000 members.
- New command `members inactive` to list members who haven't logged in for a given amount of time.
- `!adminlist` now shows the last time the bot has seen each admin and on which alt.
- Add new option `--strict` to make SQLite checks more strict. This is mainly for development purpose.
- Add new prometheus metric `states{type="raid_lock"}`
- The `LOOT_MODULE` now keeps a full history of what was rolled when, and who won what on which roll. You can search this history by using `!loot history`, `!loot history <number>|last`, `!loot search winner=Nady` and `!loot search item=leg`.
- All Docker images now support setting fixed settings via setting environment variables `CONFIG_SETTING_<setting>=<value>`, e.g. `CONFIG_SETTING_CONSOLE_COLOR=1`
- New commands `!config setting <name>` and `!config setting <name> admin <access level>` to change the required access level to change a setting's value.
- Add new setting `raid_reward_requires_lock` to control if giving points via `!raid reward`/`!raid punish` requires the raid to be lock with `!raid lock`.
- The Docker image now supports setting multiple superadmins, either separated by comma, space or both. so `CONFIG_SUPERADMIN=Nady,Nadyita` and `CONFIG_SUPERADMIN="Nady Nadyita, Nadyo"` both work.

### Changed

- The `!discord`-command got completely changed. It now acts as the central command to manage the discord connection, manage Discord invites, see invites and leave Discord servers. To get people a Discord link, just have them `!discord join` and click the link, the bot will automatically rename the Discord user to match the main AO character and optionally also assign one or more Discord roles.
- Add a new command `!assist random <number>` to pick `<number>` random callers from the currently running raid. You can exclude professions from this random pick by changing the `never_auto_callers` setting, default excludes docs and crats.
- `!raid punish` now also accepts the name of a pre-defined reward, analogue to `!raid reward`.
- The loglevel of handlers used to always be ignored and scaled with the configuration option of channels. This has been changed so that the new log level "default" will now automatically scale, while explicitly given ones like "error" will always stay on error. This allows you to log error output into separate files.
- Retries for 502 Http results are now delayed by 5s, in order not to hammer the webserver
- The `!member`-command is now a sub-command of `!members`, so `!members add <who>` now works the same as `!member add`. Access levels are migrated.
- If audits are enabled (`!settings save audit_enabled 1`), `!whois <name>` will now show information from the audit when and by whom the person was added to the bot.
- `!auction` is now an alias of `!bid`. The former was removed, because the command `!auction` was originally only added to have separate access levels for auctioneers and bidders and I didn't expect anyone to use `!auction start` over the alias `!bid start` and so on.
- If no log files are available (Docker), don't show an empty popup.
- Logging in Docker is now the same format as logfiles - not like console.
- Locking the private channel is now persistent across bot restarts.
- Location of the `text.mdb`-file was changed from `data/` to `res/`, so it doesn't collide with user data and makes it easier for containers to just mount a generic data-folder into `/nadybot/data`.
- When configured to use a proxy, don't exit when the proxy isn't reachable, but retry until it is. This fixes cases when the chat proxy has lots of workers and takes longer to accept connection than Nadybot to start to connect.

### Fixed

- Fix `/api/access_levels` endpoint and make settings webfrontend work again.
- Fix for `!raid reward <points> <reason>` and `!raid punish <points> <reason>`. They both work again as expected.
- Detect if the Discord Cloudflare server restart and also do an automatic reconnect in that case. In fact, make automatic reconnect the default, unless manually disconnected.
- Websocket timeout detection works properly now and Discord should automatically reconnect after connection is lost.

### Security

- Add new settings `webserver_min_al` to specify the overall required min access level for interacting with the bot via API or WebUI. Before this, using aoauth authentication would allow you to read the chat via the WebUI, because this target did not have a defined minimum access level.

## [6.0.1] - 2022-04-24

### Added

- Allow hiding arbitrary characters from the online list, so you don't see other orgs' bots or can even hide their whole guest channel.
- Support openSUSE tumbleweed packages
- Warn when the buddylist is full
- Add `system(mass-message)` and `system(mass-invite)` as route destinations, so you can finally route your tara/reaper spawns directly to mass invites.

### Fixed

- Fix database creation from scratch

### Security

- Fix `GHSA-x7cr-6qr6-2hh6` / `CVE-2022-24828` vulnerability

## [6.0.0] - 2022-04-19

### Changed

- Updated .deb and .rpm build instructions

## [6.0.0-rc.2] - 2022-04-12

### Added

- Nadybot now supports color-themes and ships with 16 of these to make customizing your bot even easier. Just try `!themes`

### Changed

- Failure to initiate an SSL-connection will now be retried automatically.
- Bots with AO Chat Proxies will now wait longer before they mark themselves ready. This solves some issues with long buddylists.
- Add retries to the worldboss and Gauntlet buff APIs
- Querying the buddylist if a buddy is online will not trigger a UID-lookup anymore. If you are tracking whole orgs with lots of inactive characters, then the `!track online` could hang and even crash the bot.

## [6.0.0-rc.1] - 2022-04-07

### Added

- Defining the colors for relays is now possible directly in the `!relay`-window, including examples what the current config looks like.
- Use the GMP module (if installed) for a faster login

### Changed

- Replaced the 'discord_notify_voice_changes'-setting with routes. All Discord voice-channels now appear as routing sources with a `<` before their name. Routing `discordpriv(<*)` will now route the online/offline-events of all discord-channels, or only a selected few.

## [6.0.0-beta.2] - 2022-04-01

### Added

- Allow adding settings via Attributes
- New 'timestamp' setting type

### Changed

- Database table versions are displayed with a date and time (if possible)
- Changed all module settings to use attributes (if possible)
- Text settings with empty strings are now marked as &lt;empty&gt;
- Mass-messages and -invites are now a system route source. By default, `system(mass-message)` and `system(mass-invite)` will be routed to `aoorg` and `aopriv`, but you can also route them to Discord.
- All messages from the RAID_MODULE are no longer hardcoded to being sent to the bot's private channel. Instead, there are now a bunch of new routing sources `raid(*)` and `auction(*)` which are routed to `aopriv` by default. This allows for routing of `raid(start)` or `raid(points-modified)` to Discord channels.

### Removed

- Removed unused setting "Show icons for the nanolines"

### Fixed

- The deduplication-handler for logging should no longer run out of memory
- The "Show test results" setting once again has an effect
- The "Confirmed news count for all alts" setting now works again
- Routing `spawn(gauntlet-*)`-messages works again

## [6.0.0-beta.1] - 2022-03-22

### Added

- Add `!buddylist rebalance` to rebalance your buddies across your proxies

## [6.0.0-alpha.2] - 2022-03-12

### Added

- Allow grouping of `!track online` lists by faction, org, gender and breed

### Changed

- Automatically retry timed out get-requests up to 5 times, without logging an error.
- Download the orglists via the cache-module, so we speed them up and remove some needless strain from the Funcom servers.

### Fixed

- Make the cache work on docker again
- The default setting was ignored and the last option was always chosen

## [6.0.0-alpha.1] - 2022-03-08

### Changed

- Check extra modules for compile errors and compatibility with this bot version before loading. Treat `<X.0.0` as `<X.0.0-0` to work around a bug.
- Adding settings now requires "options" to be an array, not a string, plus there are no more "intoptions". If you need "intoptions", use an associative array for "options".
- Moved from PHPDoc annotations to PHP 8 attributes, greatly increasing parsing speed
- Change the start-up banner to be more concise
- Change a lot of the default permissions that were using "all" or "member" to "guest"
- Remove all global variables
- Sub-commands are no longer regular expressions, but rather user-friendly names.
  Sub-commands are only used to group permissions now and can be chosen freely.
  All existing sub-command-rights are being migrated automatically
- Replace the huge banner with a smaller one for N6
- Help for commands is now created from the source file and no longer from separate help files. A new `!help syntax` explains the syntax and is linked to from every help page, unless turned off.
- The `!help` command is now a central landing page for getting help and no longer a list of commands
- Moved `!adminhelp` from guides to help, so it's part of core
- Instead of having 3 fixed "channels", there is now an unlimited number of permission sets. By default, they have the same names as the old channels and the same short letter symbols (T|G|P). These permission sets can be managed with the `!permset` command and mapped to a command source via the `!cmdmap` command. The command prefix is now also part of this mapping, so you can have different prefixes for org, private chat, discord and so on.
- After authenticating, the socket to the AO chat server is now non-blocking.
  That also means that AO-packets aren't prioritized higher than others anymore.
  In the past, if one packet was ready to be read, *all* of he packets would be read in one go. This is no longer the case.
- Migrations are no longer executed manually during setup, but automatically when registered with #[HasMigrations]. The order in which they are executed is now predictable and strictly timed.
- No module accesses another module's database tables directly anymore, only via exposed functions from the other module
- The configuration is now parsed into an instanced object (ConfigFile), that can be injected, instead of using a global variable `$vars`
- Where it makes sense, function calls have been converted from positional to by-name
- The #[Inject]/@Inject annotation used to determine the name of the instance to inject by the variable name. This has been changed to the class name. Thus, it is no longer possible to inject into untyped properties.
  Example: `DB $database` will now inject the "db" instance, not the "database".
- The way commands have to be declared has completely changed
- Everything is now constantly checked against psalm and phpstan

### Added

- Allow having more than 1 superadmin
- Add a Prometheus-compatible metrics-endpoint to /metrics
- New command `!showconfig` to get your current configuration, minus sensitive data
- The console is now reporting in as a source, so you can actually use it for chatting. In order to do that, you should not make the command prefix optional.
- Introduce a new access level "guest" for people in the private chat or Discord chat
- Modules can now register themselves as AccessLevelProvider, so modules can manage their own access levels. The highest one (lowest numeric) will always be chosen.
- The (sub-)command declaration can now define multiple aliases at once
- Officially support Windows
- Allow increased logging with -v and -v -v

### Removed

- Sync name-lookups from PORK are gone
- Remove all deprecated DB calls (query, queryRow, etc.)
- You can no longer inject into untyped properties
- Command help files are no more
- The old `/** @ */` annotations are no more, please use attributes
- The old command-syntax with a fixed amount of parameters is gone
- PHP 7 is no longer supported

## [5.3.3] - 2022-02-20

### Fixed

- Number of unplanted sites is now correct when manually scouting
- Fix `!online <prof>`
- Fix penalty time

### Changed

- Error properly when running an unsupported SQLite version

### Added

- Allow syncing news via nadynative
- Handle the LOGIN_ERROR packet and display meaningful error messages
- Add a configurable cool-down to mass-messages/-invites
- Add a setting to limit command execution to a single discord channel

## [5.3.2] - 2021-12-23

### Fixed

- Websocket-based relays will once again reconnect if the websocket server restarts

### Changed

- If a member of the bot accidentally attacks a control tower, don't put them on the automatic track list

### Added

- New command `!towerqty` to show how many towers your level allows you to plant
- New command `!towertype` to show the tower types by QL
- Allow queuing of relay messages if the relay is down, so restarting a relay-bot won't lead to lost messages.

## [5.3.1] - 2021-12-19

### Fixed

- Fix a race condition in database migrations which would throw an error that the timers table didn't exist
- Fix migration errors for some MySQL versions that did not allow creating an index on the organizations table

### Added

- Support new Father Time worldboss

## [5.3.0] - 2021-12-11

### Deprecated

- 5.3.x This is the last version support PHP7.

### Fixed

- Fix discord relay sometimes not showing the name of the person saying something on Discord
- Fix a race condition where quickly banning and unbanning an org resulted in an error.
- Build `composer.lock` with PHP 7.4 again, so the provided release bundle (ZIP-file) will run on 7.4

### Added

- Add an option to group the online list by faction

## [5.3.0-RC.2] - 2021-12-09

### Fixed

- The logline deduplicator threw exceptions when exceptions were logged
- Race condition for the shared online list fixed, which lead to players shown as online when they already logged off
- Fixed tyrbot() protocol errors when relaying only with a prefix
- Fix `genRandomString()` from sometimes returning fewer chars than requested This also fixes Sec-WebSocket-Key from being non-standard sometimes
- If there are rules defined, send them to the players when they join the bot
- PHP 8.1 fix for AMQP relays
- Fix building the PHP 7.4 docker image
- Make websockets more robust, testing against autobahn test-suite
- Don't try to relay messages to uninitialized arrays

### Changed

- Upgraded all required packages to the latest version and resolved issues

## [5.3.0-RC.1] - 2021-12-07

### Added

- New command `!loglevel` to temporary change the loglevel
- New command `!debug` to debug a single command and upload the logs
- Lots of logging added for better debugging

### Fixed

- Highway didn't properly deinit its relay stack, leading to lingering websocket connections.
- Fix the arbiter tile when there is no arbiter.
- PHP8 fix when `!orglist` had an unused org rank

### Changed

- Replaced the logging framework from log4php with monolog
- Added missing table indices
- Switched to the new history API
- Reduce tracker SQL queries to speed up `!orglist` for large orgs
- Sort the `!track online` characters by name in their group

## [5.3.0-beta.3] - 2021-12-01

### Changed

- Removed `!lc import`
- Users tracked via `!track addorg` don't show on `!track` anymore

## [5.3.0-beta.2] - 2021-12-01

### Added

- Nadybot will now automatically receive events like world bosses spawning or Gauntlet buffs being popped and set the timers accordingly.
  This is done by connecting to the public highway server and joining a room that sends these events. Option can be turned off in the RELAY_MODULE
- Automatically fetch the current worldboss and gauntlet timers from our API when connecting. No more need for manual timers.
- Add option to automatically kick players not in the raid on `!raid lock`
- Speed up the `!orglist` command by skipping the UID lookup and instead using the UID the org roster contains
- Show channel class in `!system`
- Add `!db3` command to show DB3 loot
- Merge the IMPQL_MODULE into the IMPLANT_MODULE
- Make the rank required to cancel another player's raffle a setting

### Fixed

- Tower attacks should now always be assigned to the correct tower field by using boundary boxes from Tyrbot
- Commands longer than 20 chars won't throw errors in the usage module anymore
- A fake attacker will now show up properly in `!attacks`
- `!sites` now considers local scout data to be newer than API data
- Don't treat mute/unmute and deafen/undeafen as joining a Discord channel
- When sharing database with other bots, don't share their online lists via relays
- Discord reconnects won't forget the channels anymore. This will prevent the bot from stopping to relay from discord after reconnecting.
- Fix a rare condition where the event loop would just hang and throw errors
- To help 32bit systems, convert some Discord data types from integer to string
- Fix setting the bot's timezone with `!timezone`
- Now all syntax variants of the SPIRITS_MODULE work as expected
- Running `!whatbuffsfroob <slot>` no longer throws an exception
- Running `!arulsaba <type> 6 left` no longer throws an exception
- Running `!lookup <uid>` will now run an actual lookup and display the data

### Changed

- The whole code base is now PHP 8.1 compatible
- Converted all commands to new command parser
- Also explain that PostgreSQL is a valid database type
- Fix all possible undefined and type errors by utilizing 2 static code analyzers phpstan and psalm
- Upgrade to latest illuminate/database version
- Convert all text files from DOS to UNIX line ending
- Reword the SKILL_MODULE help files
- Add loads of parameter classes for things like implant slots, items or faction
- More consistent `!config` and `!settings` layout
- Make running tests non-blocking
- `!trickle <skill>` output changed, so it's easier to understand
- Remove PvP rank from `!whois` as it's a fixed value

## [5.3.0-beta] - 2021-11-05

### Added

- Add ability to define immutable values for any setting in the config file
- Add separate kill message when using worldboss kill or kill sync

### Fixed

- `!hot`-command honors first argument again
- `!test`-commands work again as expected
- Fixed race condition of the orglist test
- `sync-online=false` now behaves as expected for nadynative()
  It will still route online events when you route org- and private channels to the relay. Filter these events if you don't want that.
- The same Worldboss message won't be displayed twice in 30s

## [5.3.0-alpha.3] - 2021-11-03

### Added

- Add raid statistics to `!leaders` command
- Display event descriptions with `!relay config` command

### Changed

- Support relay events properly via the API
- Allow dynamically added events to have a description

## [5.3.0-alpha.2] - 2021-11-01

### Fixed

- Converting Gauntlet timer fixed
- `!boss` command renamed to `!wb`

## [5.3.0-alpha] - 2021-11-01

### Added

- New WORLDBOSS_MODULE to track Tarasque, Vizaresh, etc. as well as the gauntlet buff.
  This obsoletes the old external GAUNTLET_MODULE and BIGBOSS_MODULE
- Non-routable events can now be relayed via supporting protocols (currently only nadynative).
  Non-routable events are a special type of event that are shared via nadynative relays. These include tower scouting, tara spawn, timers, countdown, etc.
- New command `!raid notinkick` to kick every character on the private channel who's not in the raid.
- New event modifier `change-message()` that allows you to modify messages with prefixes (like @here) and search & replace
- New customizable "news" page that can be shown on login. This one can be made up from individual "tiles", so you have a single start/welcome message displaying multiple tiles, like gauntlet buff, current arbiter, actual unread news, tara spawn, etc.
  The content of this page can be customized with `!startpage`

### Fixed

- Convert all Aria tables to InnoDB as it doesn't support transactions.
- Fix displaying of route modifiers with `<`, `>` or `"`
- Fix the `if-matches()` modifier
- Allow the use of @here for people allowed to use mentions and messages from the bot itself
- Fix for old SQLite versions that do not support adding new non-null columns to the database without default
- Fix for discord roles with too many permissions
- Fixed some errors in the tracker and orglist module
- Fix migration of tower data from pre-5.2.2 release

### Changed

- Support custom setting handlers with new `@SettingHandler()` annotation. This allows modules to have their own custom setting types.
- Group sub-commands below their commands in `!config`
- The alignment in tower pop-up messages is now colorized
- New syntax for writing commands
  The old way was to write a function and declare the command it was for and a regular expression that needs to match for this function to be used. Then the function was given 5 well-defined arguments, so you could not easily add new parameters to those.
  The new way just gives one parameter, a CmdContext instance that contains everything the old parameters had, but is more extensible.
  You also no longer define a regular expression that needs to match, but give the parameters your command needs as additional parameters to your function.
  See the WIKI for details.

## [5.2.2] - 2021-10-20

### Added

- Rewritten tower module to support Tyrence's tower API as well as manual scouting and mixed mode.
- New commands `!lock`/`!unlock` to lock/unlock the private channel to a certain rank and upwards, so you can perform maintenance work
- Welcome messages: When a file `data/welcome.txt` exists, new members will get a tell with this in a pop-up. Used to welcome them, explain basic things like netiquette, rules, etc.
- Route colors now support the "via" clause. Colors can now be defined depending on which hop (e.g. relay) they pass
- The track module now allows tracking whole orgs with `!track addorg`
  Since this will also add their bots, you can now hide and unhide individual chars from the `!track online` list.
- Timers are now channels and can be routed.
- Orglist will now be available immediately after bot start.
- Profiles now save and restore routes, relays and their colors.
- New option for `!online` allows sending 2 tells with online lists (local and alliance)
- More compatibility options for `agcr()`
- Add a new setting that allows to spin up one or more threads that continually lookup missing or outdated player data.

### Security

- Fix `GHSA-frqg-7g38-6gcf` vulnerability

### Fixed

- Support unsupported playfields in `!rally`
- Fix bug in `!route color rem`
- Fix possible sleep-bug with websockets
- Fix relayed online list error when player lookup failed
- Convert events to messages for Tyrbot
- Fix `gcr()` message send/receive
- Fix some tyrbot protocol issues

### Changed

- Changing settings now generates a SettingEvent
- Add more backtraces to errors and errors better searchable.
- Use libsodium for encryption if present
- Return extended help for settings via API

## [5.2.1] - 2021-09-17

### Added

- Added aoauth support to the webserver
- `!logs` command with search will now include backtraces

### Fixed

- The converter for `agcs()` created an invalid route modifier
- Errors in route modifiers affected things like online/offline messages
- Routable messages with null message threw exceptions

## [5.2.0] - 2021-09-12

### Added

- Complete relay stack rewrite:
  - Allow any number of relays, each with its own prefix, protocol, transport, etc. You can use any protocol with any transport (Tyrbot via AMQP, BeBot via Websocket, etc.)
  - Support BeBot's relay protocol
  - Support Tyrbot's relay protocol
  - Integrate `agcr()` protocol from ALLIANCE_RELAY_MODULE
  - Add new native relay protocol for Nadybot
  - Support custom relay-stack layers. Built-in are:
    - encryption, relaying over websockets and sharing online lists
- Complete messaging rewrite, introducing the message hub:
  - Allows routing messages from any supported channel to another channel
  - Supports wildcards as channel source
  - Supports custom modifiers. Built-in are:
    - `if-has-prefix()`
    - `remove-popups()`
    - `if-matches()`
    - `if-not-by()`
    - and many more
  - Allow setting if and how a tag is displayed for each of the hops a message passes
  - Allow configuring the color of each of the tags and texts rendered, depending on source and target hop. This allows for custom colors for private channel, web chat, console...
- New security module that lets you configure to log security-relevant events and makes them available via !audit command and API calls
- API endpoint `/org/history` to complement the audit API
- Support for signed API calls, so pure API-calls don't need to re-authenticate once per hour.
- Thanks to the message hub, you can now route messages into the console and into the webchat. Yes, even Darknet, relays and discord... everything
- API spec is now tagged for better overview

### Fixed

- The `!logs` command now works if you have them somewhere else than `./logs/`
- Rendering certain popups in Discord looked wrong (e.g. `!alts`)

### Changed

- When updating the bot via git, you also have to run `composer install` to see if all required modules are present. Bot will now check for this and give you a meaningful error-message
- Since routing is done by name now, changing your discord channel name can break your routing.

## [5.1.3] - 2021-08-26

### Added

- Allow manual adjusting which arbiter week it is

## [5.1.2] - 2021-07-11

### Added

- Add setting to prevent joining the raid multiple times with alts
- Tower module overhaul:
  - Now supports static and legacy timing
  - New command `!needsscout` to list fields without information
- New command `!hot` to list all sites currently hot
  - Remove old sanity check from the `!scout` command - they didn't apply anymore

### Fixed

- Fix Windows running on SQLite
- HTML-escaped colors will now display properly in the console
- Repeating timers work again

### Changed

- The docker images will now handle `!restart` commands internally
- Better error messages if the bot cannot create the SQLite database
- Support multiple embeds for Discord

## [5.1.1] - 2021-06-29

### Added

- Allow banning whole orgs with `!orgban`
- Add an option to automatically add players attacking towers to the tracking list
- Add an option to prevent people from inviting banned players

### Fixed

- Changing access level for commands didn't work
- Some (sub)commands would show up twice in the `!cmdlist` and module config

### Changed

- Added location hints for world bosses

## [5.1.0] - 2021-06-24

### Added

- Moved to Illuminate database abstraction layer, adding support for PostgreSQL and partly MSSQL
- Updated items to 18.08.58 patch
- Support upper and lower limit for raid point reimbursement
- Add an option to limit raffles to raid members
- raid history now supports showing when people joined and left the raid, regardless of whether they got points or not.

### Fixed

- Minus is showing in `!calc` again
- People who left no longer show in `!raid dual`
- Online count by org displays percentages correctly again

### Changed

- Stopping a raid can now automatically clear the callers
- `!raid` will now give the control panel in a tell
- Automatically kick banned people from the bot
- Use Discord's API v9
- Add all missing totw loot
- The old database interface is now deprecated and will be removed in 5.2

## [5.0.2] - 2021-04-24

### Added

- New `!rules` command
- parameter order for `!ofabarmor` doesn't matter any more
- Rewards can be edited without changing the reason
- Callers reworked, now supporting history and other fancy stuff
- Minimum refund tax can now be defined
- Introduce raid rank promotion/demotion distance
- Sync player data update with PORK, so we are only lagging less than 1h behind
- New command: `!arulsaba`
- Allow to set the autoinvite default setting for new members
- Allow automatic banning of players from or not from a specific faction
- Add an option whether to show the full altlist on joining/logging in
- New command: `!leaderlist` / `!leaders`
- `!alts setmain` will now move the rights and raid points to the new main
- New rights for starting/ending raffles
- Allow configuration of `!cd` command and where it should output to when started from tells
- Allow customization of auction layout
- `!points log all` to see the raid points of this char and all their alts

### Fixed

- Package now works with CentOS/RHEL
- Timers with apostrophe can now be deleted
- Allow arbitrary length auction items
- Never invite banned players
- Fixed Race condition for duplicate data in the players table
- Multi-line parameters are accepted again for commands
- Raid points are no longer attributes to the main, but to the alt now
- No mass-invites/-messages for banned characters anymore
- Turning off the auto-invites does not remove you from the buddylist anymore
- Allow endlessly long raid point reasons
- Fix the `!symb` command when searching for artillery, infantry, etc.
- Chased the new arbiter times in `!icc` command

### Security

- Update dependencies to get rid of a security issue

### Changed

- Reword `!help alts` page
- Don't say "private channel", unless we are an org bot
- If PORK shows an attacker without org as well, don't presume it's a pet with fake name
- Try to detect the amount in raffles better and introduce unlimited raffle slots with 0x
- When changing a setting, display its new display value
- `!raid list` now separates between common and individual gains/losses
- The news now have an API and are handled in NadyUI
- The Docker image has the option CONFIG_ENABLE_PACKAGE_MODULE to allow the package module

## [5.0.1] - 2021-03-25

### Added

- Support configuration of base paths
- Support packaging as RPM and DEB

## [5.0.0] - 2021-03-20

### Added

- Add possibility to set default values for alias parameters
- Allow customization of the colors of tradebot channels
- Completely rework the `!perk` command
  - Add the AI perks
  - Add actions given
  - Add grouping
- Allow `!whatbuffs` to mark one-slotted items, nodrops and uniques
- Support listing of all comments of a type
- Symbiant revamp and fuzzy finding symbiant types with `!symb` command
- Introduce reminders for `!notes`
- Mark "Self Illumination" and "The Rihwen" as tradeskillable nanos
- Ensure we don't have duplicate entries in players table
- Integrate an exporter/importer for backups and bot transfers
- Add configuration for raid add/raid kick messages
- Configurable min length for points manipulation (`!points add`/`!point rem`) reason

### Fixed

- `!raid kick` is now case-insensitive regarding the name to be kicked
- Fix searching for non-existing skill in pre-made imps
- Fix `!orghistory 0`
- Fix DB format for waypoints in `!rally`, they didn't work after restarts
- Fix for MySQL 5.5 and 5.6
- Fix Auction links for `!loot` command
- Fix `!raid refund` help to properly show how it's used
- Fixed "Soothing Herbs" proc classification
- Reclassify some nanos to different locations
- Fix Windows installer

## [5.0.0-RC.5] - 2021-02-06

### Added

- Allow disabling mass tells in the bot
- Support rate-limited proxy for when we send mass tells via more than 1 worker
- Added guide for inferno key pocket bosses
- Merge WBF_MODULE into standard modules. You can now use `!wbf` like `!whatbuffs`, but will only see items usable by froobs
- Introduce `!icc` to inform about current or upcoming arbiter events
- Support the new NadyUI web chat
- Add new raffle features
  - Raffle admin menu
  - Allow turning off raffle timeout in config options
  - Support raffle re-announcements
- Allow mapping org ranks to bot access levels
- Add new `!package` command for dynamic installation and update of optional modules from [https://pkg.aobots.org/](https://pkg.aobots.org/)
- Colorize item matches if only a certain QL(range) matches the search term
- Generic COMMENT_MODULE
  - Replaces the REPUTATION_MODULE.
  - Comments can be configured to be shareable
  - Comments can be bulk queried for everyone in the raid with `!raid comments`
- Have `!online` list show who's in the raid if configured. Multiple formats available.
- Add a setting to always ban all alts of a player
- Console enhancements:
  - Support nano-links in the console
  - Support colors in the console

### Fixed

- Fix the dynadb and display
- Fix alignment of `!updateorg` timer to always be at 10 mins after last update
- Fix bots relaying their own Discord messages
- Fix nanos:
  - Fixed and added multiple locations.
    A lot of inferno sanctuary nanos were also buyable in Pandemonium garden.
  - Make overview better to read
  - Reclassified Vehicle nanos
  - Added legacy Adventurer nanos and Playful Cub (Other)
- Fix crash in Discord output when more than 10 parts were sent
- Support SQLite < 3.23.0
- Fix all known PHP8 issues and always build a PHP8 docker image for testing purpose
- Support database reconnects. Bot can now start without DB and will detect if the DB was restarted
- Adhere to Discord rate-limits when sending messages via PMs or channels

### Changed

- Always allow `!alts confirm` / `!alts decline`
- Add "Inert Reaper of Time" to `!bossloot`
- Only show the loot searched for in `!bossloot` and not everything the bosses drop
- Added "Go LFT"-link to raid information window
- Support worker pong and command negotiation
- Move "Ring of Nucleus Basalis" into ring category
- Allow searching for tower sites without space after zone (`!lc PW8` as well as `!lc PW 8`)
- Reduce and check the required PHP extensions on start-up. No more OpenSSL module needed

## [5.0-RC4] - 2020-12-27

### Fixed

- Fix crash in `!bio` command
- Fix for dynamic settings not showing values
- Final fix for column exist in MySQL

### Added

- Support the new AOChatProxy and its mass tell features (see [https://github.com/Nadybot/Nadybot/wiki/AOChatProxy](https://github.com/Nadybot/Nadybot/wiki/AOChatProxy) for details)
- Allow configuring which tradebot channels to relay
- Add subway and totw loot to `!boss` command
- Add documentation to modules to show in NadyUI and the `!config <module>`

### Coding

- Convert buddylist-entries to objects
- Track if orglist is ready, so you get proper "please wait" messages
- Use a different algorithm to send out mass messages and invites
- Allow elegant overwriting of base instances

## [5.0-RC2] - 2020-12-19

### Fixed

- Do not send alt validation request from multiple bots
- Prevent a rare hanging scenario when reading past the end of file of the MDB file
- Fix Discord for some 32bit systems when the bot was member in a lot of servers
- Never exit due to PHP 7.4 type errors
- Lots of crash scenarios fixed

### Added

- Allow `!alts main <name>` again and allow confirmation of alt and/or main
- Speed up orglist by roughly 30% by not sending UID lookup packets twice
- Try to align guild roster updates with the Funcom export time, so we're always updating 10 mins after them
- Handle custom emojis in Discord, delete unsupported chars from Discord names and properly support backticks
- Add `!assist <name>` for a quick alternative to `!caller add <name>`
- Add more buttons to callers
- Use cache for [AO-Universe](https://ao-universe.com) guides
- Introduce the ability to execute commands via the API, fully supported by NadyUI which now has a command line

### Removed

- Remove SSL-support from the webserver as it's untested

### Changed

- Switch default neutral color to old one
- Remove old and outdated guides and spice up the remaining ones

## [5.0-RC1] - 2020-12-06

### Added

- Move last bits of sync HTTP calls to callbacks. This should finally fix all outstanding Windows bugs and speed up `!orglist <org>` by large.
- Move more tells to "spam messages" to allow load-balancing them
- Tower attacks warn about fake names now
- Pre-made imps are now (hopefully) displayed better and also fixed 2 wrong ones. 1HE shiny head would be too odd…
- Allow searching via skill aliases in the pre-made imps now, so `!premade mc` really works now
- Any emoji that's send on discord will now be converted to `:<emoji name>:` if it cannot be displayed in AO
- Add new command `!reward <add|rem|change>` to manage pre-defined rewards which can then be used with `!raid reward`, so you can use `!raid reward beast` if you defined it before. This will now also allow logging reasons for why a raid reward was given with `!raid reward <amount> <reason>`.
- The console now also allows the use of the symbol prefix, so you can easily copy & paste commands
- Introduce the ability to set a separate prefix for Discord and allow turning off messages when a command is unknown.
- Disable the console on Windows as it doesn't work without blocking everything
- Enable the WebUI per default now, only listening on localhost

### Fixed

- Fix more 32bit issues
- Fix links in discord headers not working
- Fix a MySQL crash when a fake attacker charmed a pet with a too long name
- Fix a rare Discord crash when someone joins an unknown voice channel
- Fix a crash when GSP's provider had erroneous JSON
- Fix min-level requirement check for commands in tells
- Fix tower attacks not recording defending org
- Break too long messages into chunks for Discord, so messages aren't dropped anymore

## [5.0-beta3] - 2020-11-27

- Support Windows and include an installer for PHP
- Support 32bit
- Support older MySQL alongside MariaDB
- Switch from MyISAM to aria as default if available
- Support PHP8
- `!bossloot <name>` will now just log an error instead of crashing when an item cannot be found
- Add rate-limit functionality to the LIMITS module, so you can auto-kick/ban/ignore players that are sending commands at a too high rate
- Serialize outgoing Discord messages, so the order is always guaranteed to be correct. This slows sending messages a bit down as we're not sending multiple messages in parallel anymore, but at least they arrive in the correct order.
- Support Discord mentions
- Allow the use of `!extauth request` outside of Discord DMs
- Fix SQL error in `!config cmd disable/enable` command
- Fix the LEVEL_MODULE ranges
- Switch even more HTTP lookups to asynchronous, so they don't slow down the bot, greatly increasing responsiveness when the ORGLIST_MODULE is enabled
- Reduced bot start-up by adding some long overdue indexes to some core tables and not always adding all recipes yet again
- Add a `!track online` command alongside a more customizable tracker output
- Move the console into its own core module and fall back to buffered stdin for platforms without readline

## [5.0-beta] - 2020-11-14

### Deprecated

- This is the first version to one to only support PHP7.4+

### Added

- Integrate a lightweight web server (yes, no kidding) and a REST API for some basic endpoints which will lead to a full-fledged web interface for the bot in the future. More on the wiki. Right now, the web UI only allows changing the configuration easily.
- Roll commands now allow sending result to team/raid
- Add a proper raid module for point raids. If activated, this will give you 6 more ranks to assign rights to (3 raid leaders, 3 raid admins). The raid and bid system is modelled after what Hodorraid is using. More on the wiki.
- Proper Discord relay integration with newest v8 API. More on the wiki.
- Bot relay now support grc protocol v2, allowing total customizability of all colors.
- Support for notifications to Discord for all major commands
- Raffle module allows raffling multiple items, item groups and also introduces configurable raffle bonus points so that people not winning anything get better chances on next raffle
- Added guide for tradeskilling a sauna
- `!weapon` command now has a built-in search function for names, so you no longer need the AOID.
- Add module to check which nano a disc turns into
- Add module to have bot relay darknet/lightnet messages from their private channels
- Add module to search for items locking a certain skill
- Add module to search for breakpoints or highest usable QL of items
- Add module to notify about GSP shows and helping you tune in

### Changed

- Bot is now called Nadybot and no longer Budabot
- Add **all** Symbiants to the implant module
- Add **all** Spirits to the spirit module
- Removed unneeded guides (map navigation anyone?)
- News can now be marked as read, so they won't get shown again.
- Votes are now stored in a different format
- Documentation is slowly moving into a GitHub wiki

### Fixed

- Orglist module can now once again download and search for orgs

### Coding

- Everything is now typed (where possible) and requires PHP 7.4 or higher
- AsyncHttp is now truly asynchronous, even for SSL/TLS
- Pushed back synchronous blocking calls with HTTP
- Introduced a Websocket client to allow Discord integration

## [4.2] - 2020-07-30

### Added

- Improved stopwatch module with laps
- Introduced an AMQP link for bot relaying without AO's message penalty
- Introduced skill aliases to make it easier to search for skills. `!whatbuffs <cl|pm||si|agi>` will now just work
- Introduced a DISCORD module to send notifications to Discord channels
- Added new options to sort and format the alt list
- Allowed configuring where to announce city waves
- Fixed, unified and prettified the `!weapon` command and the FA calculator
- Overhauled `!nano` and `!nanolines` with fresh data and grouping, also showing froob-friendly and general nanos
- Added support for filtering outgoing and incoming messages in relays
- Added switchable timer to notify when to plant after a notum wars win
- Added possibility to turn off pictures in `!loot` and the loot lists
- Added new totw 201+ loot with `!totw` and grouped all other

### Changed

- Do not announce when someone just brbs, just silently set them afk
- Renamed `!raffle join` to `!raffle enter`
- Made sending usage stats opt-in instead of opt-out
- Updated items-database to 18.08.53 patch
- Sort `!timers` output by remaining time ascending
- Added a huge load of new item groups
- Added a bunch of new locations to the whereis database
- Reformatted `!axp` output

### Fixed

- Fixed and added tons of nano locations
- Fixed the `!weather` command by switching to Nominatim plus the Norwegian weather service
- Fixed the `!vote` command
- Fixed `!whatbuffs weapon smt`
- Fixed Fast Attack calculations
- Fixed configuration option where to show people adding or removing themselves from loot

### Coding

- Restructured the file-system layout to make PSR-4 autoloading work properly
- Replaced all bundled versions of software for the bot with composer modules
- SQL files now support multi-line commands
  - Better readability for create commands
  - Faster insert speed due to extended inserts
- Added lots and lots of PHPDoc information

## [4.1] - 2020-01-09

### Coding

- Introduce a global PHP coding style for the project and fix all files, so they adhere to it
- Remove the API documentation for now as the tool used to build it currently doesn't work anymore
- Add type-hints to variables where possible and document functions and their return value, while still sticking to PHP5 compatibility

### Added

- Colorize the whompah-cities according to factions
- Group search results for items
- Better alignment for `!history`
- `!whatbuffs` now works with nanos and not nano crystals and can show what provides the buff
- `!whatbuffs` now supports towers, contracts and use-items (tutoring devices, extruder nutrition bar)
- `!whatbuffs` caps contracts and filigree rings at QL250
- `!whatbuffs` now works for nano resist
- `!whois` now supports and shows inactive players, so you can better check if a name is free
- Upgrade item databases to the latest version
- The items-database now supports updating the item_buffs and item_types as well
- `!aggdef` now shows a visual slider and makes it easier to set the calculated value
- Add a bunch of new NPCs to `!whereis`
- Remove "Blackmane's Stat Buffer" as it's annoying as hell when you try to find buffs
- Provide a compact and useful docker image to run BudaBot
- Allow gracefully shutting down the bot by listening to SIGINT and SIGTERM, thus allowing the bot to be run as a service
- More concise tower messages
- Add profession symbol to online list
- Color the whois org-name according to the org's faction
- Make mistyped command suggestions more reasonable
- updated `!nano` (thanks to Saavick)

## [4] - 2018-06-14

### Added

- Now includes DEV_MODULE which includes
  - `!cache`
  - `!cmdhandlers`
  - `!createblob`
  - `!demo`
  - `!htmldecode`
  - `!makeitem`
  - `!mdb`
  - `!msginfo`
  - `!silence`
  - `!unsilence`
  - `!timezone`
  - and several commands for testing different things on the bot
- Updated items-database to 18.08.26
- `!sendtell` is now included in releases, to allow for scripts to configure the bots for spam relay
- Added chat_commands, rollable_items, special_attacks, deflect, chatfilter, spamfilter to `!guides` (thanks longsdale)
- Added several default aliases that were in wide use:
  - `!i` for `!items`
  - `!w` for `!whois` and
  - `!o` for `!online`
- Added `!poh` to show loot from Pyramid of Home for the RAID_MODULE (thanks Illork)
- `!trickle` can now take a skill as a parameter and tell how much of each ability is needed to trickle that skill one whole point
- improved support for AOChatProxy 2.x versions
- `!whois` now shows head_id, pvp_rating (legacy), and pvp_title (legacy) in 'More Info' link

### Changed

- All references to gmp module have been removed, and bcmath module is now required
- `alts_inherit_admin` functionality is now always on, and removed the setting
- `!notes` now show all notes for all alts, and which alt they belong to
- `!multiloot` is now `!loot addmulti`
- `!items` now always uses the local items-database
- to add loot, now use `!loot add <item>` instead of `!loot <item>`
- `!whatbuffs` has been improved
- `!online` now has a new format
- `!mafist` and `!trickle` values updated (thanks Daniel Grim and Lucier)

### Removed

- Removed `guild_admin_access_level` and `guild_admin_rank` settings along with the corresponding functionality
- Removed spam_protection
- Removed IRC_MODULE
- Removed `!banorg` and `!unbanorg`
- Removed `!sm`
- Removed `!citems`
- Removed `!whatbuffs1` and `!whatbuffs2`
- Removed `extjoinpriv`, `extleavepriv`, `extkickpriv`, `sendguild`, and `sendpriv` event types

### Fixed

- fix for `!aou` (and `!title`) not working
- various fixes and improvements to `!perks`, `!loot`, pagination, `!dyna`, `!whereis`, `!recentseen`, `!inactivemem`, `!leprocs`, `!recipe`, `!dimach`, `!init`, `!lag`, `!items`, and `!nanoloc`

### Coding

- API docs are now included in `/docs/api`
- timer events have a new format: `timer(time)`
- added `packet(id)` event type and removed `allpackets` event type

## [3.5_GA] - 2017-10-07

### Added

- Command aliases now show in `!help` output
- `!nl` is now a default alias for `!nanolines`
- Added setting `num_news_shown` to control number of news items shown
- Added setting `num_events_shown` to control number of events shown
- `!light` is now a default alias for `!guides light`

### Changed

- Updated `!whatbuffs` to use data from [aoitems.com](https://aoitems.com)
- Updated items-database to 18.08.24
- Changed rights commands:
  - `!addadmin` to `!admin add`
  - `!remadmin` to `!admin rem`
  - `!addmod` to `!mod add`
  - `!remmod` to `!mod rem`
- Fix for `!aimshot` aliases: `!as` and `!aimedshot`
- Changed `http_timeout` default value changed from 5s to 10s
- Updated NANO_MODULE (thanks Saavick and Lucier)
- Updated `!whereis`
- Updated `!implantdesigner`
- Updated `!boss` and `!bossloot` with Abandoned Hope loot (thanks Nadyita)
- Updated `!about`

### Removed

- Removed WEBUI_MODULE, HTTP_SERVER_MODULE, and VENTRILO_MODULE
- Removed `!feedback`

### Fixed

- Fixes for running Budabot with PHP 7
- Fixed issue with caching lookup results
- Various bug fixes and code cleanups
- Fix for `!penalty` (thanks equi)
- Minimum cluster QL in `!implant` should now be more correct (thanks MDK)

### Coding

- Renamed many core methods from snake-case to camel-case
- Dependencies now managed by composer

## [3.4_GA] - 2016-01-05

### Added

- `!waypoint` can now optionally take coordinates pasted from F9 (thanks Javier Cornejo)
- Added support for `type=`, `slot=`, and `ql=` parameters to `!items` when using [aoitems.com](https://aoitems.com)
- Added `!runas`
- Added `!gmi`
- Added `!litems` to force items lookup using local items-database
- Added `!citems` to force items lookup using remote items-database (aoitems.com by default)
- Added enhanced search capabilities to several commands including `!findplayer`, `!cluster`, `!premade`, `!bossloot`, `!boss`, and `!playfields`

### Changed

- items-database updated to 18.08.10
- Updated `!about`, logon-banner, and Budabot "system ready" message
- `!items` now defaults to using [aoitems.com](https://aoitems.com) for search results

### Removed

- Removed `!inspect`
- Removed `!heal`, `!check prof`, and `!check org` due to `/assist` changes
- Removed `!altsadmin`, `!logonadmin`, and `!logoffadmin`
- Removed `!quote stats`
- Removed `!onlineirc`
- Removed some settings from VOTE_MODULE

### Fixed

- Fixed `!bio` (thanks Daniel Grim)
- Fix for AOChatProxy not restarting when Budabot restarts
- Fix for using Budabot with MySQL 5.1 or earlier (thanks d0ttd0tt)
- Improved checks when upgrading Budabot versions to prevent database mangling

### Coding

- Overhauled TIMERS_MODULE to allow custom callbacks (thanks equi for the request)

## [3.3_GA] - 2015-07-05

### Added

- Added `!feedback`
- Added `!kos`
- `!track` can now optionally show tracker events in org and/or private channels
- Added Arete to `!playfields` (thanks Eli)
- Added `!perks`
- Added OFAB shoulder wear to `!ofabarmor`
- Added `!guides adminhelp` to help new Budabot admins
- Bot now warns when org name is set incorrectly
- Improved `!impdesign`, can now add symbiants

### Changed

- Some minor changes to `!reputation`
- `!whompah` routes have been updated for 18.7 changes
- Updated items-database to 18.08.01
- `!recipe` now uses local database to address issues with Recipebook going down
- References to [xyphos.com](xyphos.com) replaced with references to [aoitems.com](https://aoitems.com) (thanks Zyamada)
- `!quote rem` will now re-number quotes when a quote is deleted, so there are no "holes"
- Various changes relating to 18.7 patch

### Removed

- Removed `!quote stats`
- Removed `!lock`
- Removed `!unlock`
- Removed `!guide smileys`
- Removed `!guide buffs`
- Removed botmanager

### Fixed

- `!orglist` should now work when using AO chat proxy
- `!history` should now work again, for RK1, RK2, and RK5
- Fixed issue causing bot to take a long time to start
- Other various fixes and changes
- `!findorg` and `!orglist` should now work again
- `!weapon` should now work again
- Added more changes to prevent bot from hanging when AO servers go down
- Updated to work with MySQL 5.5+

## [3.2_GA] - 2014-06-07

### Added

- `!scout` and `!forcescout` can now accept pasting the control tower blob info
- `!cmdlist` can now optionally show commands based on specified access level
- `!whatbuffs` has been rewritten to provide many more items
- Core classes can now be overridden
- Limit checks can now notify private and guild channels of failed attempts
- Added `!usage` command to see usage for a particular command
- Added `!implantdesigner` (alias `!impdesign`) for building an implant set (based on iMatrix database)
- Enhanced WEBUI_MODULE (can now execute commands and see command output; can see up to 2000 lines of console history; up and down arrows for previous and next commands)
- `!ladder` now shows implant requirements
- `!adminlist` now shows online alts and includes guild admins (`!adminlist all` to see all admins and their alts regardless of online status)
- `!aou all` allows you to search the body of guides for matches
- Added `!findplayer`
- `!roll` can now accept strings as possible roll values
- Added `!heal clear` and `!assist clear`
- Added setting `add_member_on_join` to control whether the bot automatically adds players who `!join` the bot as members
- Saving profiles now includes command aliases

### Changed

- News items are marked as deleted instead of actually being deleted from the database
- Merged `!is` into `!whois`
- New installations will have a slightly updated color scheme
- Merged BOSSLOOT_MODULE into ITEMS_MODULE
- Condensed some command output to be more succinct
- Merged `!lastseen` into `!is`
- Updated to items-database to 18.06.11
- Users in `!ts` output now sorted by channels
- Combined all alias commands into `!alias`

### Removed

- Removed `!credz`
- Removed `!doh`
- Removed `!capxp` due to low usage
- Removed `!mobloot` due to low usage
- Removed `!orgranks` due to low usage
- Removed `!spiritssen` and `!spiritsagi` due to low usage
- Removed `!server` since Funcom hasn't provided server info since merge
- Removed `!orgcities` since all cities are now instanced
- Removed `!buffitem`
- Removed `!reloadconfig`

### Fixed

- Bot should now better detect when an HTTP request fails prematurely (such as when downloading the org roster)
- Bot will now parse "defence shield disabled" messages properly
- Fixed various typos and bugs
- `!rally`, `!assist`, and `!heal` will no longer spam 3x in private channel
- `!history` should now work again and also show when a character is deleted
- `!weather` should now work again
- Updated `text.mdb` so that bot can correctly interpret org messages
- Fix so that cron events don't execute multiple times on start-up

### Coding

- Various changes to allow better integration and customization by third party modules
- Bot core rewritten to use namespaces (old modules will need to add 'use' declarations at the top of class files)

## [3.0_GA] - 2013-03-25

### Added

- Chatbot.sh now accepts an optional bot name as a parameter
- Added `!ladder`
- Added WEBUI_MODULE for viewing the bot console from a web browser
- Added core COLORS module for configuring bot colors (`!config COLORS`)
- Added `!guides light` and `!guides gos` (thanks DrUrban (RK1))
- Added `!shop` for searching for items posted to the shopping channels
- Added `!gauntlet` (available for RK2 only - thanks Macross (RK2))
- `!nano` and `!pb` now have "enhanced search" capabilities (along with `!items`)
- Added some common command aliases
- Added Xan bosses to `!whereis`, `!boss`, and `!bossloot`
- `!boss` and `!bossloot` now include Inferno static bosses (thanks to Mansikka (RK1) for this suggestion)
- Nearly all commands and events (including core commands and events) are now configurable
- Added `tell_error_msg_type` setting to control how the bot responds when a limit check fails
- Added `tell_min_player_age`' setting for specifying the minimum age a player must be to send a tell to the bot
- `!afk` now shows how long someone has been afk for in `!online` list and when they come back
- Improved support/integration for Dnet
- Budatime now accepts months (mo) and years (y)
- `!orglist` can now search on org name or player name and will return results even faster on most bot setups
- `!server` now estimates the number of players online
- Added core PROFILE module (`!profile`) for saving and loading bot configuration profiles
- Added `!usage <player>` to show usage for a particular player
- Added setting `relaysymbolmethod` for controlling whether the relay symbol relays or does not relay messages
- Added a new and improved BotManager (GUI)
- Added `!recipe`
- Added `!rally`
- Added `!towerstats`
- Added `!nanoloc`
- Added `!showcommand`
- Added `!macro` (to execute multiple commands at once)
- Added core API_MODULE (to allow remote management of bot)
- Added setting `xml_timeout` to control how long to wait for a response from XML servers
- News items can now be set sticky
- PHP supported version increased to 5.3

### Changed

- Changes relating to server merge
- `!usage` now breaks down usage by channel
- `!inits` and `!specials` are now combined into `!weapon`
- `!notes` are now shared between alts
- Improved items-database rip and updated to items-database to 18.06.00
- `!items` now shows exact matches first in the results, and shows a message when results have been limited (thanks to Deathmaster1 (RK2) for this suggestion)
- Color settings should now affect most modules and commands
- Several fixes and improvements for `!whatbuffs`
- Build now includes botmanager
- Improved alias handling with parameters
- `!help` module_name will now show all help files for the specified module
- Converted IRC_MODULE and BBIN_MODULE to use SmartIRC for IRC support
- Logging now goes to a single file by default
- People in IRC now show up in `!online`
- The bot will now respond to commands from the IRC channel (if access is set to 'all')
- `!opentimes` now groups by org and shows total QL of the control towers for each org
- Updated nanos database with nanos from recent patches
- Removed `!kos` and replaced with `!reputation`
- `!whois` now deals with current data, `!lookup` deals with data from name_history
- `!cmdsearch` now replaces `!searchcmd`
- `!searchcmd` is now a default alias for `!cmdsearch`
- Consolidated `!nlline`, and `!nlprof` into `!nanolines`
- Consolidated `!impql` and `!impreq` into `!implant`, which now displays its output on one line
- Renamed `!kickuser` to `!kick` and `!inviteuser` to `!invite`
- Error message now distinguishes between "unknown command" and "access denied"

### Removed

- Removed `!namehistory` since `!whois` already shows name history
- Removed `!fp` since AO now shows this information in the nano crystal description
- Removed BIOR_GUARDIAN_MODULE and WAITLIST_MODULE due to low usage
- Removed default aliases: `!alt`, `!leproc`, `!event`, `!mission`, `!onlineorg`, `!fast`, `!fa`, `!timer`, `!battle`

### Fixed

- Fix some memory leaks
- Fixed some issues with VENTRILO_MODULE
- `!banorg` now works correctly
- Various fixes for MySQL
- Bot should no longer pause when downloading large files from external resources or doing `!orglist`
- Fix for logoff spam in org chat when the bot restarts
- `!aou` once again works (removed some guides in `!guides` that were adequately represented with `!aou`)
- Various fixes for IRC relay
- Fixed LIMITS check

### Coding

- `!settings` is now in the core CONFIG module
- Timer events can now be called reliably every second
- Modules are now declared and defined using an object-oriented approach with annotations
- Modules can now set a priority for messages sent from the bot
- `$db->query()` now returns the resultset
- Added prepared statement support to `DB.class.php`
- Added `$db->queryRow()` for returning the first row from the resultset

### Security

- Mods and admins can no longer configure commands that require an access level higher than their own to execute

## [2.3_GA] - 2011-11-07

### Added

- Added `!rtimers` (repeating timers) and `!timers view` (intended to be used with aliases)
- Added bastion loot in RAID_MODULE (thanks Taylorka (RK2) and Nanomongool (RK2))
- Adding setting to specify colors in the IRC_MODULE
- Added `!guides grafts`
- Added `guild_admin_rank` and `guild_admin_access_level` settings to replace `guild_admin_level` setting
- Added core USAGE module (`!usage`)
- Added setting type 'time' which takes a budatime argument
- Added `!penalty` for showing orgs who have attacked recently and are in penalty (thanks Taylorka (RK2) for requesting)
- Added option `notify_banned_player` to control whether the bot sends a tell to a player when they are banned or unbanned from the bot
- `!neutnet on` now accepts neutnet spam from the new Neutnet satellite bots (Neutnet15, Neutnet16)
- Some core commands are now configurable
- On logon, the bot now notifies players if they have unvalidated alts
- Added `!aliaslist` to show current aliases that are active on the bot
- Added `!logoff` to set a logoff message (similar to setting a logon message with `!logon`) (thanks Rageballs (RK1) for implementing this)
- Timed/cron events can now take an arbitrary time value (in Budatime) (thanks Argufix (RK2) for requesting)
- Added help files for various core commands
- Added `!orgcities` to show coordinates for player cities
- Added CSP map guides to the GUIDES_MODULE (guides that start with 'info-')
- Added `!aospeak` (`!aospeak org`, `!aospeak all`) which tells you who is in your org channel on the AOSpeak server (thanks Tshaar (RK2))
- Added support for Dnet (`!dnet enable`/`!dnet disable`) (RK1 only)
- Added `!aou` for searching for and viewing AO Universe guides from in game
- Added settings to control how much org info (org and rank, rank, none) is displayed on the online list (thanks Raging (RK1) for implementing this)
- Added setting `first_and_last_alt_only` to control whether the bot always spams logon/logoff messages or only when the first alt logs on or the last alt logs off (thanks Hogwar (RK1) for suggesting this)
- Added allpackets event type, so modules can process packets directly if they wish
- Rewrote BANK_MODULE which now imports CSV files from AO Items Assistant (thanks Rosss (RK1) for requesting/testing this)
- `private_channel_cmd_feedback` and `guild_channel_cmd_feedback` settings added to control whether the bot responds if a command doesn't exist (thanks Mdkdoc420 (RK2) for requesting this)
- ALIEN_MODULE now contains `!aiarmor`, `!aigen`, `!leprocs`, `!bio` (can now handle multiple bios as once), and added new commands: `!bioinfo`, `!ofabarmor`, and `!ofabweapons` (thanks Mdkdoc420 (RK2), Wolfbiter (RK1))
- `!logonadmin` lets admins view, change, or clear another character's logon message (thanks Ross (RK1) for requesting this)
- `!logs` command lets you view the last 7000 characters of a log file from in game
- `!checkaccess` shows your effective access level, taking into account `alts_inherit_admin` setting
- `!timers` can now take days as a time unit (thanks Mdkdoc420 (RK2))
- `!findorg`, `!whoisorg`, and `!history` can now take an optional dimension parameter (thanks Mdkdoc420 (RK2) for requesting this)
- Added `!fp` for determining if a nano is usable in false profession (thanks Wolfbiter (RK1) for letting me use his code as reference)
- `!executesql` lets you run queries on your bot database directly from in game (superadmin only)

### Changed

- Tweaked items-database filter and updated it to 18.04.12 along with `text.mdb` (thanks MajorOutage (RK1))
- Rewrote PRIV_TELL_LIMIT core module, renamed it to LIMITS, and removed `!limits` command (use `!config limits`)
- `!ban` now takes the time argument as "Budatime" (e.g. 1d4h6m32s)
- Moved `!orghistory` into GUILD_MODULE (ORG_HISTORY_MODULE no longer exists)
- `!settings change` will now show the current value for colors
- `!lastseen` now takes alts into account
- `!oe` shows info for 50%, 25%, and 0% now in addition to 75% (thanks Mdkdoc420 (RK2))
- TEAMSPEAK3_MODULE replaces TEAMSPEAK_MODULE (and provides TS3 support of course; thanks Tshaar (RK2))
- Changed `gmdate()` to `date()` in case someone would like to set the bot to use their own timezone (thanks MajorOutage (RK1))
- Bot now announces in private channel any time someone adds themselves to a loot item (thanks Teknocrat (RK2) for requesting this)
- `!whois was moved to WHOIS_MODULE and now shows name history
- RAFFLE_MODULE now shows reminders every minute on the minute and at 30 seconds (thanks Kinaton (RK1) for requesting this)
- Nearly all commands should have a help file now
- Guides in the GUIDE_MODULE should page better now; fixed/updated some guides (thanks Raging (RK1) for requesting this)
- Updated `!about` to include people who I intended to add for the 2.2 release but forgot
- Updated `!fight`, `!ding`, `!doh` (thanks Mdkdoc420 (RK2))
- items-database updated to 18.04.07
- Moved ALTS module to be a core module; alts must now be validated with `!altvalidate` before they can inherit their main's access level (thanks Raging (RK1))
- `!13`, `!28`, and `!35` now display the correct items as multi-loot (thank you Haku for reporting this)
- `!online` now shows a default icon for characters where the profession is unknown

### Removed

- Removed join limits from the core LIMITS module
- Removed `!altsadmin export` and `!altsadmin import`
- Removed `!limits` command (use `!config limits`)
- Removed 'guildadmin' access level and changed all references to 'raidleader'
- ITEMS_MODULE no longer has support for [xyphos.com CIDB](http://xyphos.com)

### Fixed

- Reworked BBIN_MODULE and IRC_MODULE to fix some issues and added option to have them reconnect when IRC connection is lost
- Fixed some issues with checking access level when `alts_inherit_admin` was enabled
- Fixed some issues when running Budabot on 64-bit Linux (thanks Wirehead for reporting this)
- Fixed `!eventlist`
- IRC_MODULE should now match IRC implementations of other bots and relay items links and messages between bots correctly
- Fix for `!attacks` and `!victory` paging (thanks Mdkdoc420 (RK2))
- Fix for showing alts with `!whois` output (thanks Mdkdoc420 (RK2))
- Blob sizes should be calculated correctly now, possibly fixing several issues (thanks Raging (RK1))
- Various other fixes

### Coding

- Added `Text::make_structured_blob()` for more control over how blob windows are generated (thanks Raging (RK1))

## [2.2_GA] - 2011-04-16

### Added

- Added LISTBOT_MODULE and renamed it to WAITLIST_MODULE
- Added more output to `!system`
- Added setting to control how `!links` is displayed
- `!whois` will now shows alts for that character if the bot knows of any
- `!afk` can now be used with the command symbol (!)
- Can now change the color of incoming messages on the relay to both the guild and private channels
- Added setting for the maximum number of characters allowed in a logon message (default: 200)
- Added Type 48 to `!bio` and `!aigen ankari`
- `!bio` now shows weapon upgrade info

### Changed

- `!news msg` and `!news del` replaced by `!addnews` and `!remnews`

### Removed

- Removed "Ignore" from the settings as `!ban` does the same thing; also, logon/logoff events now execute even for people on the ban list (for `!track`, `!is`, `!orglist` purposes)

### Fixed

- Fix for topic not sending to characters in guild on logon
- Fix for guildadmin access level

## [2.1_GA] - 2011-04-05

### Added

- Added `!neutnet` for conveniently adding and removing all 14 neutnet slave bots to and from the broadcast list
- Added AO chat proxy support to the `config.php` file

### Changed

- EVENTS_MODULE will now share events with other bots using the same database
- NOTES_MODULE will now share notes with other bots using the same database

### Fixed

- Updated Wrong Window perk modifier for `!leprocs`
- Sub-commands now work even when using MySQL
- Fix for IRC module not showing the guild name in the IRC channel
- Fix for setup wizard not saving the config file correctly in Linux
- Fix for `!whoisall` not showing characters on other dimensions when a character with the same name doesn't exist on the current dimension
- Fix for `!friendlist` search not displaying search results
- Fix for `!vent` not displaying the vent info
- Fixed some issues when adding and removing characters from the notify list would not update the friend list correctly in some cases
- Fix for `!lock` not locking the private channel

## [2.0_GA] - 2011-03-21

### Added

- Added `!track` for silently keeping track of when people sign on and off
- Added `!lookup` command, so you can find the UID of a player
- Added `!specials` command
- Can now use `!config cmd 'cmd_name'` to display config options for an individual command
- Added convenience method for setting up tell relay between two orgs (`/tell bot1 !tellrelay bot2 /tell bot2 !tellrelay bot1`)
- Added some entries to `!whereis`; also added waypoints for a few of the existing entries
- `!waypoint` command for setting waypoints (e.g. `!waypoing 300 400 pw`)
- `!playfields` will show you a list of playfields, playfield-IDs, and short names for use with `!waypoint` and the TOWERS_MODULE
- Help info will now be included in pages for `!config cmd` and settings if a help file exists
- `!logon` with no parameters now shows your current logon message
- `!buffs` is back
- Added `!reloadconfig` to load changes from the config file when changes are made without restarting in certain instances
- Added `!fc` in the FUN_MODULE
- Added `!links` to NOTES_MODULE
- Added a setting to enable or disable bot commands and output being sent over the bot relay
- Finished LEPROCS_MODULE using database from Wolfbiter (RK1), Gatester (RK2)
- Added BROADCAST_MODULE to replace NEUTNET_MODULE (and OMNI_MODULE) (note: both NEUTNET_MODULE and OMNI_MODULE are for RK2 only)
- Added FEEDBACK_MODULE (enhanced KOS list)
- Added `!clearqueue` command to clear the chat queue
- Added `!loadsql` command for manually loading SQL files
- `!accept` to allow the bot to accept a private channel invite from another player
- Can now create command aliases
- Can now share online list with an unlimited number of Budabots (previous limit was 2) and this happens automatically when they share the same database
- Added a setting to allow alts to inherit admin privileges from their main
- Added solitus, opifex, nanomage, and atrox guides to the GUIDE_MODULE (thanks Mdkdoc420 (RK2), Curlycat (RK2))
- Added `!whompah` command
- Added setting `alts_inherit_admin` for people who want that functionality (disabled by default)
- Added `!wtb` and `!wts` commands for searching posts made on the shopping channels
- Added `!findorg` command
- Added setting for ITEMS_MODULE to either look in local database or to use [xyphos.com](xyphos.com) (`!litems` to force local database; `!xitems` to force xyphos.com)
- Added setting `guild_channel_status` for enabling or disabling the guild channel
- Added `!cloak on` to allow the cloak to be turned on manually on the bot

### Changed

- Updated items-database to 18.04.03
- Updated help commands and event and command descriptions for a number of modules (most commands should have a help entry now)
- Moved ORG_ROSTER from a core module to a user module and renamed to GUILD_MODULE
- Updated syntax for the GUIDEBOT_MODULE (e.g., instead of `!elykey` it's now `!guides elykey`) to reduce command name space pollution
- `!guides list` now returns the guides sorted alphabetically
- Moved player info cache into the database
- Renamed BOTCHANNEL_MODULE to PRIVATE_CHANNEL_MODULE
- Rewrote (yet again) `!orglist` so that it can do lookups MUCH faster and is much less likely to bug
- Made `!oe` more succinct
- Re-wrote `!fight` to not favor the first player
- Changed `!nd` and `!hd` to `!nanodelta` and `!healdelta`
- Changed `!reboot` to `!restart`
- Updated `!about`
- Merged `!memory` and `!uptime` into `!system` and added additional info output
- Rewrote much of RAID_MODULE which now includes loot lists for pandemonium, APFs, dust brigade (Chachy (RK2)), albtraum (Dare2005 (RK2)), and Xan-PFs (Morgo (RK2))
- Reduced chat spam when the bot logs on
- Sub-commands can now be enabled or disabled separately from their parent command
- Updated `!whoisorg`, `!orgmembers`, `!orgranks` to accept either a character name or an org-ID (or nothing to use the current org)

### Removed

- Removed BANK_MODULE

### Fixed

- Updated `aochat.php` to use `text.mdb` file for extended message lookups
- Fixed TOWERS_MODULE; renamed to TOWER_MODULE
- Fixed `!cmdlist`
- Fixed `!nanoinit` calculation
- `!premade` works again and now shows formatted results (finally!)

## [1.0_GA] - 2010-09-06

### Added

- Added DBLOOT_MODULE contributed by Chachy, RK2 (`!db1` or `!db2` to use)
- Multiple alts can now be added with one command (`!alts alt1 alt2 alt3`, etc.)
- Added ability to set which character is the main character (`!alts setmain <character>`)
- Ability to import and export alts to and from a file via a command
- Setup will now run if username, password, or character name is not set in config file; "delete me for new setup" file is no longer used
- Added `!memory` to show memory usage (not sure how accurate it is)
- Added `!whitelist` which allows you to add players to the "whitelist". Players on the whitelist will be able to send tells to the bot, even if they would normally be blocked due to "limits" (see `!limits`)
- Support for config files named other than `config.php` and running multiple bots from the same directory
- Added SKILLS_MODULE (`!burst`, `!fling`, etc.)
- Added WEATHER_MODULE
- Added BBIN_MODULE for bot relay over IRC
- Added `!uptime` command
- Created new BOTCHANNEL_MODULE for configuring the guest/private channel (fixes `!guestjoin` problems)
- Added EVENTS_MODULE for scheduling org and raid events, etc.
- `!assist` can now handle multiple assists
- ONLINE_MODULE can now send `!online` message at logon to players
- Added AOJUNKYARD_MODULE (`!wtb item`)
- Now ships with many more 3rd part modules (thanks to all who contributed)

### Changed

- Now `!timers` and `!timer` are interchangeable (aliases)
- `!whois` now displays the source of the whois lookup result (in the "click for more options" link)
- Updated items-database with the latest rip from MajorOutage, 18.03.12
- Reduced timeout bot would spend waiting for a response from [PORK](https://people.anarchy-online.com) from 10s to 5s to reduce lag time when Funcom XML server goes down
- Merged ORG_MSG_RELAY_MODULE functionality into RELAY_MODULE
- Log files are now saved to `/logs/<botname>.<dimension>/` and rotate every month instead of every day
- Merged AUTO_WAVE_MODULE functionality into CITY_CLOAK_MODULE
- Config file will now be created automatically if it doesn't exist
- `!roll` in the RAID_MODULE is now `!rollloot` to distinguish from `!roll` in the HELPBOT_MODULE
- Moved all commands, events, and settings relating to the city cloak to CITY_CLOAK_MODULE
- Moved all commands, events, and settings relating to news to the NEWS_MODULE
- Updated the way the bot handles the buddy list and updated FRIENDLIST_DIAG_MODULE
- Updated ORGLIST_MODULE (`!is` and `!onlineorg`)
- `!config` has been reworked and should make it easier to config the bot
- Made !battles as an alias for !battle
- Update PHP to version 5.2.5 for windows versions
- Added better support for MySQL and Linux (aokex is no longer needed)
- removed `/sql` directory; SQL files are stored in each module's own directory; `bot::loadSQLFile()` to load SQL files

### Fixed

- Fix for some help commands not showing
- PANDE_MODULE should now show up in the config on Linux hosts
- GUIDEBOT_MODULE should now work in org, tells, and private group channels (previously only worked in org channel)
- Help lookups should now work in private channel
- RAFFLE_MODULE completely overhauled and should now work correctly now
- Updated LEVEL_MODULE to give more correct data (e.g., teaming range, pvp range, mission levels, etc.)
- fixed problems with blobs breaking

## [0.6.5] - 2010-03-05

### Added

- Updated guild relays to work with the new chat implementation/feature from Funcom (right-click-on-persons-name-to-open-a-menu). (courtesy of IamZipfile)
- Boss, Breed, Nano, and Skills modules now included in the regular release of Budabot.

### Changed

- Chat library updated to work with Orbital Strike messages. The optional AUTO_OS module should now work correctly with Budabot. (Special thanks to Funkman and Snuggles for helping get this tested and working)
- Bot now ignores the 3 shopping 11-50 channels instead of the old 1-50 channels.

### Removed

- The AI Hoster, Listbot, and Team Modules are now deprecated, so they have been removed from the regular release and added as optional downloads.

### Fixed

- Applied an already posted patch/fix/edit for org invite messages (Thank "TheMekon" for the fix)
- Fixed an issue with the bot not notifying of org logoffs by default. I.e. bot notifications of player logoffs should be working correctly now.  The setting for changing the showing/hiding of logoffs has been moved to the `!settings` page (no more `!botnotify <on|off>`).
- Fixed up the `!whereis` command, so it didn't arbitrarily display the Varmint Woods entry when you gave it an entry it could not find.
- The auto-notify feature now works again. The bot will auto remove and auto-add people to the notify list as they join and leave/get kicked from the org.
- Fixed up the help section for the skills module and updated its links.

### Security

- Revived a lost security feature of the bot; The `!limits` command give you virtually full control over who can send tells to the bot or ask for private group invites.

## [0.6.4] - 2010-04-03

### Added

- Added flatbot's roll system (which includes multi-loot, re-rolling, and various rolling methods) and then I fixed it up a bit (small edits here and there). Special thanks to: Wyziddyj (for making it) and Egads (for making it public).
- Added a very long `!altsadmin` command, so mods can add/delete/fix alt listings.
- Added capability to add/delete alts 'from an alt', without having to log onto their main character.
- Added a `!botnotify` command to combat those times when the chat server crashes and the bots start spamming org chat. A simple `!botnotify off` will stop the bot from spamming org chat with long lists of the org-members signing off. Use `!botnotify help` for more details.

### Changed

- Updated the bot with the new chat servers since the 18.4.1 update

### Fixed

- Fixed the chat relay glitch (`xml.php` and `chatbot.php`).
- Fixed various settings' help-file links that were broken
- Fixed a bug that allowed alts to add other people as alts of themselves, thereby creating a whole new alts list, separate from the original main.
- Fixed a minor bug where the bot was unable to update char info for players having apostrophes in their first/last name

## [0.6.3] - 2010-04-03

### Added

- Adding a command similar to `!updatme` just for the org-roster
- Added that you can show also only org-members from a specific profession similar to online
- Added that a member can set himself as setting "afk kiting"
- Added that when the name of the player is being mentioned in the channel, and he is afk, a message will be shown on the channel
- Added support for adding/kicking admins
- On `!adminlist` the superadmin will be highlighted
- Added commands to set/show points of a specific player (Raidmodule stuff)
- Added a few missing items to the items-database (all platinum filigree rings were missing)
- You can set now if the text of a raidleader will be repeated and the color of the repeated text
- Added a command to send a tell to all online org-members
- Command added to see when an org-member was last online
- Added `-` as new Symbol
- Added a command to search for Land Control Areas (by QL or name).

### Changed

- Changed the name of the `php.ini` to `php-win.ini`
- Added the loading of the `php-win.ini` in the Batchfile and in the `mainloop.php` (if the OS is Windows)
- Added that the org-members variable gets created during the start of the Bot
- Added that players can join the guest-channel without an invitation of an org-member. You can set if everyone or just players on guest-list can join.
- Added that `!verify` and `!guestjoin` commands by-pass the tell requirements
- Changed the look of the `!orgmembers` command a bit
- The `!lock` command will no longer kick everyone out of the private group
- Made the tell command available for org-channel
- Added sub-commands for the News-command so that the access-level for showing and adding/deleting news is different
- The `!roll` command has been renamed to `!flatroll` command to solve problems with the `!roll` command from the Helpbot module
- The command to set news for the private group has been renamed to `!privnews` to solve problems with the `!news` command for the Org
- Added and updated help files

### Removed

- Added Clan Newbie OOC to the Ignore List

### Fixed

- Fixed some SQL queries in the Online Module that were still not compatible with MySQL
- Fixed a problem with using `"` in a timer name
- Fixed that you can't add/kick yourself to an admin-group
- Fixed a problem with `'` in items' names while adding it to a raid
- Fixed a problem with deleting a raid
- Fixed a few problems regarding the check of required access-levels on commands
- Fixed that in the config command some access levels wasn't shown correctly
- Fixed that the `!battle` command had a wrong starter access level
- Fixed typos
- Fixed that the profession of guest-channel members wasn't shown always
- Fixed a problem with using `+` as symbol
- Fixed a bug that the `!roll` command was continued even though there was nothing to roll.
- Smiley command will now correctly be sent back to the right channel

## [0.6.2] - 2010-04-03

### Added

- Added a shell script for Linux

### Changed

- Changed all filenames to lower cases except the Loading script for Modules
- changed all paths that they are only including `/` instead of `.` For Linux compatibility
- Chatbot.bat is starting the php.exe with a reference to the new php-win.ini
- Added that only PHP files will be accepted that are in lowercase
- Added the XML Infofile required for the BotManager
- Updated the items-database to 17.0.1

### Fixed

- Changed some SQL statements that have not been changed in the last version (for MySQL)
- The title command was sending the output only to guild channel and not to the channel where the user used the command for it
- Fixed some references to wrong files (no effect on Windows, as it isn't case-sensitive)
- Fixed an SQL Select Statement error in the count command
- Fixed a rounding mistake in the `!oe`-command
- Fixed that the sender name of tells was shown instead of the receivers one at the console

## [0.6.1] - 2010-04-02

### Added

- On the adminlist it is now shown too if he is in the private group
- Added that you can set in which channel you want to see the city attacks. (useful when you have 2 bots (1 raidbot and 1 orgbot) so they don't show both the attack in your guild channel)
- Using a command with prefix (like `#` or `!`) in tells will work now too
- Added an AI raid hoster Module (used to roll for an AI raid sponsor)
- Added a little countdown command to the timers module
- Added that you can see the rank too on the `!orgmembers` function
- Added that you can set the color for the guest-channel relay text (changed that you can set different colors for channel, username and channel color)
- Added that you can relay also the commands and results
- Added that you can set if the guest-channel will be always/automatic/never relayed
- Added a little news script for the private group join. One for admins and one for normal users
- Added a very flexible raidmodule. Including `!raidloot`, `!raidlist`, `!raidkick`, `!raidstart`, `!raidcheck`, `!rules`, `!spawntime`, `!raidhistory` (Items can be flatrolled or pts). The `!raidloot` table can easily be extended
- Added the old `!about` command and updated the `about.txt`
- Added the `!whereis` script from Blackruby
- Added the Alien City General Info File from Blackruby (shows what drops of a specific General)
- Added a Module with which you can see what you need for a specific Alien Armor Part(like which viralbots and skills for the combine)
- Added that news entries can be deleted

### Changed

- Changed the Online module output design and added the rank of each player
- The log functions (file and console) are put now into one function to. Also, the format of the output has been changed a bit.
- Messages from the Bot itself are shown in the log files and the console now too.
- Changed the Forum address in various scripts
- Updated the admin module. Changed some messages that are sent to the rl/mod and the sender of the command. Added check if the player is a RL/Mod when you try to remove them from the list.
- Updated the Level Info with some new colors and also show how many tokens you get(done by Blackruby)
- The Organization XML file from the Bot org will be able to update every 6hrs while the rest is still set to 24hrs.
- Updated to PHP 5.2

### Removed

- Removed the loot module as it is now part of the raidmodule

### Fixed

- Fixed a problem within the admin module that their online status was not shown correctly
- Removed an old Tower debug-message that was shown on the console
- Corrected some item links in the bio material script
- Some old helpfile descriptions weren't stored correctly in the Database
- Fixed a problem when assigning some to a team when he had a number in his name
- Fixed that players that left the Bot couldn't be removed from a team
- Fixed a problem with commands that are used in different modules but where shown on one cmd config window
- Updated the Character/org/server lookup class (`xml.php`) to solve some issues with getting wrong data. Also, the Speed of the Splitting functions for XML files has been increased.
- Fixed some issues with using MySQL as Database
- Fixed that guest-channel players were not shown on the `!count` commands
- Fixed a bug in the `!count lvl` command that the amount of players in a TL were not calculated correctly
- Fixed a bug that the bot were unable to respond to users with a user-ID higher than 2^31

### Coding

- Added an Exec command to the DB Class
- Added a Function to the DB class to change the Create Table statements for SQLite(as they are compatible with the ones used for MySQL, just have different syntax. For example the "autoincrement" vs "auto increment" columns)

## [0.6.0] - 2010-04-02

### Added

- Added a new Module to show who is online and in the private chat
- The online commands are available for tells now too. You can choose over a setting if it should show the guild or private members that are online
- Added a helpfile for the secure tell module
- `!guestlist` is showing if the user is online/in chat or offline
- `!memberslist` shows now if the user is online/offline and in chat
- Added version number and support forum address (startup logo)
- Added a little fun module
- Added autoreinvite for players that have been in the private channel after a bot restart or crash
- Topic can now be sent too when an org-member logs on
- Added the days to the topic command for the time it has been set
- Added a list to show current org-members, their stats and when they logged off the last time
- Added help files
- Added that characters can be banned for a specific amount of time
- Added a listbot module
- Added a heal-assist Macro
- Added a command, so that player can force an update of their characters stats for private channel

### Changed

- Changed the default settings for the secure tell module
- All public channels are now from default on ignore
- Updated the items-module, so it is compatible with the new table
- Updated the loot module for the new items table
- guests list looks the same way as the online list and the `!guestslist` is now sorted by player names
- Changed the look of the online list a bit and added the alien-level is shown too now
- The logon-message that is relayed looks now the same way as normal logons
- The Helpfile system got an overwork. Now only help files of active modules are shown, they are categorized, their access-level can be changed over the `!config` command and the `!help` command now works in guild channel, too.
- Logons are now relayed to the relay-org
- Guest-channel messages are now sent over guild-relay, too
- Did a little overhaul of the timer module. Some old timers didn't get deleted in the DB and you can now use as time 2days 18hr 15min instead of setting it with the time in minutes.

### Removed

- Removed the online commands from the basic guild and basic chat
- Players in the guest channel can't use the `!afk`-command anymore (was showing that he is back only)
- All previously created timers were deleted

### Fixed

- Fixed a problem with the clear command and when a text is in front of an item ref(when adding an item with loot)
- Fixed a problem with disabling of pwinners
- Fixed a problem with the help files (`!help` wasn't available in guild/priv and admin check wasn't correct)
- Fixed an error in the guest channel. If the player that has been added is an org-member his logon was shown.
- The Bot is now updating breed and gender too now in the org roster update
- Fixed a problem with the new timer syntax and removed debug messages
- Fixed a bug in one of the SELECT query statements(some entries has been shown double or more)
- Fixed a problem with the new plugins command
- Fixed a problem with the timer syntax
- The minimum level for a loot-spot wasn't checked correctly
- Fixed a bug in the `!calc` command that has shown an error when the result was zero
- Fixed a bug in the tower messages. (Instead of saving the attacker's faction, we saved the attacker's org into the DB)

### Coding

- Updated the Header in all Files which license, information, and stuff
- Added a GPL license file
- Added a file with the patch notes
