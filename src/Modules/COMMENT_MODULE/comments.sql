CREATE TABLE IF NOT EXISTS `comments_<myname>`(
	`id` INT PRIMARY KEY NOT NULL,
	`character` VARCHAR(15) NOT NULL,
	`created_by` VARCHAR(15) NOT NULL,
	`created_at` INT NOT NULL,
	`category` VARCHAR(20) NOT NULL,
	`comment` TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS `comments_<myname>_character_idx` ON `comments_<myname>`(`character`);
CREATE INDEX IF NOT EXISTS `comments_<myname>_category_idx` ON `comments_<myname>`(`category`);

CREATE TABLE IF NOT EXISTS `comment_categories_<myname>`(
	`name` VARCHAR(20) PRIMARY KEY NOT NULL,
	`created_by` VARCHAR(15) NOT NULL,
	`created_at` INT NOT NULL,
	`min_al_read` VARCHAR(25) NOT NULL DEFAULT 'all',
	`min_al_write` VARCHAR(25) NOT NULL DEFAULT 'all',
	`user_managed` BOOLEAN DEFAULT TRUE
);
