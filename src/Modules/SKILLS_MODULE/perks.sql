DROP TABLE IF EXISTS perk;
CREATE TABLE perk (
	id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
	name VARCHAR(30) NOT NULL,
	expansion VARCHAR(2) NOT NULL,
	description TEXT
);

CREATE INDEX IF NOT EXISTS `perk_name_idx` ON `perk`(`name`);

DROP TABLE IF EXISTS perk_level;
CREATE TABLE perk_level (
	id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
	aoid INT,
	perk_id INT NOT NULL,
	perk_level INT NOT NULL,
	required_level INT NOT NULL
);

CREATE INDEX IF NOT EXISTS `perk_level_perk_id_idx` ON `perk_level`(`perk_id`);
CREATE INDEX IF NOT EXISTS `perk_level_perk_level_idx` ON `perk_level`(`perk_level`);
CREATE INDEX IF NOT EXISTS `perk_level_required_level_idx` ON `perk_level`(`required_level`);

DROP TABLE IF EXISTS perk_level_prof;
CREATE TABLE perk_level_prof (
	perk_level_id INT NOT NULL,
	profession VARCHAR(25) NOT NULL
);

CREATE INDEX IF NOT EXISTS `perk_level_prof_perk_level_id_idx` ON `perk_level_prof`(`perk_level_id`);
CREATE INDEX IF NOT EXISTS `perk_level_prof_profession_idx` ON `perk_level_prof`(`profession`);

DROP TABLE IF EXISTS perk_level_buffs;
CREATE TABLE perk_level_buffs (
	perk_level_id INT NOT NULL,
	skill_id INT NOT NULL,
	amount INT NOT NULL
);

CREATE INDEX IF NOT EXISTS `perk_level_buffs_perk_level_id_idx` ON `perk_level_buffs`(`perk_level_id`);
CREATE INDEX IF NOT EXISTS `perk_level_buffs_skill_id_idx` ON `perk_level_buffs`(`skill_id`);

DROP TABLE IF EXISTS perk_level_actions;
CREATE TABLE perk_level_actions (
	perk_level_id INT NOT NULL,
	action_id INT NOT NULL,
	scaling BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE INDEX IF NOT EXISTS `perk_level_actions_perk_level_id_idx` ON `perk_level_actions`(`perk_level_id`);

DROP TABLE IF EXISTS perk_level_resistances;
CREATE TABLE perk_level_resistances (
	perk_level_id INT NOT NULL,
	strain_id INT NOT NULL,
	amount INT NOT NULL
);

CREATE INDEX IF NOT EXISTS `perk_level_resistances_perk_level_id_idx` ON `perk_level_resistances`(`perk_level_id`);