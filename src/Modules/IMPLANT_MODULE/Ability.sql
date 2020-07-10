DROP TABLE IF EXISTS Ability;
CREATE TABLE Ability (
	AbilityID INT NOT NULL PRIMARY KEY,
	Name VARCHAR(20) NOT NULL
);
INSERT INTO Ability (AbilityID, Name) VALUES
(1,'Agility'),
(2,'Intelligence'),
(3,'Psychic'),
(4,'Sense'),
(5,'Stamina'),
(6,'Strength');
