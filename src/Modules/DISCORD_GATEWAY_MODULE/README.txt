This module handles incoming Discord messages and provides a new
source for routing them.
You can configure if and how you want to relay messages from
and to Discord/org/private chat with the !route command
(e.g.!route add discordpriv(*) &lt;-&gt; aoorg,
see https://github.com/Nadybot/Nadybot/wiki/Routing for details).

You can also configure whether to react on commands
sent from Discord with the discord_process_commands setting.