CREATE TABLE IF NOT EXISTS `tradebot_colors_<myname>` (
	`id` INT PRIMARY KEY AUTO_INCREMENT,
	`tradebot` VARCHAR(12) NOT NULL,
	`channel` VARCHAR(25) NOT NULL DEFAULT '*',
	`color` VARCHAR(6) NOT NULL,
	UNIQUE(`tradebot`, `channel`)
);

CREATE INDEX IF NOT EXISTS `tradebot_colors_<myname>_tradebot_idx` ON `tradebot_colors_<myname>`(`tradebot`);
CREATE INDEX IF NOT EXISTS `tradebot_colors_<myname>_channel_idx` ON `tradebot_colors_<myname>`(`channel`);