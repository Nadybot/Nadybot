DROP TABLE IF EXISTS Profession;
CREATE TABLE Profession (
	ID INT NOT NULL PRIMARY KEY,
	Name VARCHAR(20) NOT NULL
);
INSERT INTO Profession (ID, Name) VALUES
(1,'Adventurer'),
(2,'Agent'),
(3,'Bureaucrat'),
(4,'Doctor'),
(5,'Enforcer'),
(6,'Engineer'),
(7,'Fixer'),
(8,'Keeper'),
(9,'Martial Artist'),
(10,'Meta-Physicist'),
(11,'Nano-Technician'),
(12,'Shade'),
(13,'Soldier'),
(14,'Trader');
