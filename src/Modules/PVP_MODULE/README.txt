The PVP module tracks tower stats, attacks and outcomes in real-time
without the need of scouting. Every event creates a routable message
in a <highlight>pvp(*)<end>-source that you can then route via
<highlight><symbol>route add pvp(&lt;name&gt;) -&gt; aoorg<end> to your org chat, or anywhere else.
It is meant as the successor to the TOWER_MODULE, but can be used alongside
for a while to ease transition.

For more information, see https://github.com/Nadybot/Nadybot/wiki/PVP