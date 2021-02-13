DROP TABLE IF EXISTS perk;
CREATE TABLE perk (
	id INT NOT NULL PRIMARY KEY,
	name VARCHAR(25) NOT NULL,
	expansion VARCHAR(2) NOT NULL
);

DROP TABLE IF EXISTS perk_level;
CREATE TABLE perk_level (
	id INT NOT NULL PRIMARY KEY,
	aoid INT,
	perk_id INT NOT NULL,
	perk_level INT NOT NULL,
	required_level INT NOT NULL
);

DROP TABLE IF EXISTS perk_level_prof;
CREATE TABLE perk_level_prof (
	perk_level_id INT NOT NULL,
	profession VARCHAR(25) NOT NULL
);

DROP TABLE IF EXISTS perk_level_buffs;
CREATE TABLE perk_level_buffs (
	perk_level_id INT NOT NULL,
	skill_id INT NOT NULL,
	amount INT NOT NULL
);