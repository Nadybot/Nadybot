CREATE TABLE IF NOT EXISTS discord_mapping_<myname> (
	`name` VARCHAR(12) NOT NULL,
	`discord_id` VARCHAR(50) NOT NULL,
	`token` VARCHAR(32),
	`created` INT NOT NULL,
	`confirmed` INT,
	UNIQUE(`name`, `discord_id`)
);