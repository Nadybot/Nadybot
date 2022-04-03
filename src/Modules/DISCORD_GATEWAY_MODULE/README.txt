This module handles incoming Discord messages and provides a new
source for routing them.
You can configure if and how you want to relay messages from
and to Discord/org/private chat with the !route command
(e.g.!route add discordpriv(*) &lt;-&gt; aoorg,
see https://github.com/Nadybot/Nadybot/wiki/Routing for details).

If you want your bot to react to commands from Discord, use
the '<symbol>cmdmap' command to assign a permission set.