This module allows you to easily track the status of some NPCs in AO.
With this module, you can quickly see which NPCs are currently up, have
been killed, or are currently being killed.
This information can be invaluable for players looking to complete quests
or obtain rare items that are only available when certain NPCs are killed.

You can either use the commands for each mob, or even route spawning-
and death-messages. Every mob has a type and a key, which is printed right
next to its waypoint.
Use <highlight><symbol>route add mobs(&lt;type+key&gt;-spawn) -&gt; aoorg<end>
or <highlight><symbol>route add mobs(&lt;type+key&gt;-kill) -&gt; aoorg<end> for routing the
spawning and killing messages into your org-chat.

Examples:
* <highlight><symbol>route add mobs(prisoner-diseased-kill) -&gt; aoorg<end>
* <highlight><symbol>route add mobs(prisoner-*-spawn) -&gt; aopriv<end>