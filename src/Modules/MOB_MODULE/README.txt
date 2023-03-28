This module allows you to track spawning, killing and HPs of certain
mobs in real-time. You can either use the commands for each mob, or
even route spawning- and death-messages.
Every mob has a type and a key, which is printed right next to its
waypoint. Use <highlight><symbol>route add mobs(&lt;type+key&gt;-spawn) -&gt; aoorg<end>
or <highlight><symbol>route add mobs(&lt;type+key&gt;-kill) -&gt; aoorg<end> for routing the
spawning and killing messages into your org-chat.

Examples:
* <highlight><symbol>route add mobs(prisoner-diseased-kill) -&gt; aoorg<end>
* <highlight><symbol>route add mobs(prisoner-*-spawn) -&gt; aopriv<end>