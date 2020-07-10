DROP TABLE IF EXISTS EffectTypeMatrix;
CREATE TABLE EffectTypeMatrix (
	ID INT NOT NULL PRIMARY KEY,
	Name VARCHAR(20) NOT NULL,
	MinValLow INT NOT NULL,
	MaxValLow INT NOT NULL,
	MinValHigh INT NOT NULL,
	MaxValHigh INT NOT NULL
);
INSERT INTO EffectTypeMatrix (ID, Name, MinValLow, MaxValLow, MinValHigh, MaxValHigh) VALUES
(1,'Skill',6,105,106,141),
(2,'Ability',5,55,55,73),
(3,'AC',8,505,508,687),
(4,'Max H/N',7,405,407,550),
(5,'XP',5,7,7,8),
(6,'Add Dmg',5,18,18,22),
(7,'Reflect',5,20,20,25),
(8,'Add Def',6,130,131,175),
(9,'Add Off',5,30,30,39),
(10,'NCU',5,30,30,39),
(11,'Skill Lock',5,-5,-5,-9),
(12,'Interrupt',5,-5,-5,-9),
(13,'Nano Cost',5,-2,-3,-5),
(14,'Range',5,15,15,19),
(15,'Delta',5,55,55,73),
(16,'NanoSkill',6,105,106,141);
