CREATE TABLE IF NOT EXISTS `raid_points_log_<myname>` (
	`username`   VARCHAR(20) NOT NULL,
	`delta`      INT NOT NULL,
	`time`       INT NOT NULL,
	`changed_by` VARCHAR(20) NOT NULL,
	`reason`     VARCHAR(255) DEFAULT 'unknown',
	`ticker`     BOOLEAN NOT NULL DEFAULT FALSE,
	`raid_id`    INT
);

CREATE INDEX IF NOT EXISTS `raid_points_log_<myname>_username_idx` ON `raid_points_log_<myname>`(`username`);
CREATE INDEX IF NOT EXISTS `raid_points_log_<myname>_readon_idx` ON `raid_points_log_<myname>`(`reason`);
CREATE INDEX IF NOT EXISTS `raid_points_log_<myname>_changed_by_idx` ON `raid_points_log_<myname>`(`changed_by`);
CREATE INDEX IF NOT EXISTS `raid_points_log_<myname>_time_idx` ON `raid_points_log_<myname>`(`time`);
CREATE INDEX IF NOT EXISTS `raid_points_log_<myname>_ticker_idx` ON `raid_points_log_<myname>`(`ticker`);
CREATE INDEX IF NOT EXISTS `raid_points_log_<myname>_raid_id_idx` ON `raid_points_log_<myname>`(`raid_id`);