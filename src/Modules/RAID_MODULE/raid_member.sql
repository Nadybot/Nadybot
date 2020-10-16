CREATE TABLE IF NOT EXISTS `raid_member_<myname>` (
	`raid_id`  INT NOT NULL,
	`player`   VARCHAR(20) NOT NULL,
	`joined`   INT,
	`left`     INT
);
CREATE INDEX raid_member_<myname>_raid_id_idx ON  raid_member_<myname>(raid_id);
CREATE INDEX raid_member_<myname>_player_idx ON  raid_member_<myname>(player);