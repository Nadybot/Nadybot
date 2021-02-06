CREATE TABLE IF NOT EXISTS `<table:comments>`(
	`id` INT PRIMARY KEY AUTO_INCREMENT NOT NULL,
	`character` VARCHAR(15) NOT NULL,
	`created_by` VARCHAR(15) NOT NULL,
	`created_at` INT NOT NULL,
	`category` VARCHAR(20) NOT NULL,
	`comment` TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS `<table:comments>_character_idx` ON `<table:comments>`(`character`);
CREATE INDEX IF NOT EXISTS `<table:comments>_category_idx` ON `<table:comments>`(`category`);

CREATE TABLE IF NOT EXISTS `<table:comment_categories>`(
	`name` VARCHAR(20) PRIMARY KEY NOT NULL,
	`created_by` VARCHAR(15) NOT NULL,
	`created_at` INT NOT NULL,
	`min_al_read` VARCHAR(25) NOT NULL DEFAULT 'all',
	`min_al_write` VARCHAR(25) NOT NULL DEFAULT 'all',
	`user_managed` BOOLEAN DEFAULT TRUE
);
