CREATE TABLE IF NOT EXISTS players ( `charid` BIGINT NOT NULL, `firstname` VARCHAR(30) NOT NULL  DEFAULT '', `name` VARCHAR(20) NOT NULL, `lastname` VARCHAR(30) NOT NULL DEFAULT '', `level` smallint DEFAULT NULL, `breed` VARCHAR(20) NOT NULL DEFAULT '', `gender` VARCHAR(20) NOT NULL DEFAULT '', `faction` VARCHAR(20) NOT NULL DEFAULT '', `profession` VARCHAR(20) NOT NULL DEFAULT '', `prof_title` VARCHAR(50) NOT NULL DEFAULT '', `ai_rank` VARCHAR(20) NOT NULL DEFAULT '', `ai_level` smallint DEFAULT NULL, `guild_id` int DEFAULT NULL, `guild` VARCHAR(255) NOT NULL DEFAULT '', `guild_rank`  VARCHAR(20) NOT NULL DEFAULT '', `guild_rank_id` smallint DEFAULT NULL, `dimension` smallint, `source`  VARCHAR(50) NOT NULL DEFAULT '', `last_update` INT, INDEX players_name (name), INDEX players_name (charid));