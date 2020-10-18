DROP TABLE IF EXISTS raid_loot;
CREATE TABLE raid_loot (
	`id` INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
	raid VARCHAR(30) NOT NULL,
	category VARCHAR(50) NOT NULL,
	ql INT NOT NULL,
	name VARCHAR(255) NOT NULL,
	comment VARCHAR(255) NOT NULL,
	multiloot INT NOT NULL,
	aoid INT
);

-- Vortexx
INSERT INTO raid_loot (raid, category, ql, name, comment, multiloot, aoid) VALUES
('Vortexx', 'General', 300, 'Base NCU - Type 00 (0/6)', '', 1, NULL),
('Vortexx', 'General', 300, 'Nanodeck Activation Device', '', 1, NULL),
('Vortexx', 'General', 1, 'Multi Colored Xan Belt Tuning Device', 'For TNH belts', 1, NULL),
('Vortexx', 'General', 1, 'Green Xan Belt Tuning Device', 'For S28 belt', 1, NULL),
('Vortexx', 'General', 300, 'Xan Weapon Upgrade Device', '', 1, NULL),
('Vortexx', 'General', 1, 'Xan Defense Merit Board Base', '', 1, NULL),
('Vortexx', 'General', 1, 'Xan Combat Merit Board Base', '', 1, NULL),
('Vortexx', 'Symbiants', 300, 'Xan Waist Symbiant, Artillery Unit Beta', '', 1, NULL),
('Vortexx', 'Symbiants', 300, 'Xan Waist Symbiant, Control Unit Beta', '', 1, NULL),
('Vortexx', 'Symbiants', 300, 'Xan Waist Symbiant, Extermination Unit Beta', '', 1, NULL),
('Vortexx', 'Symbiants', 300, 'Xan Waist Symbiant, Infantry Unit Beta', '', 1, NULL),
('Vortexx', 'Symbiants', 300, 'Xan Waist Symbiant, Support Unit Beta', '', 1, NULL),
('Vortexx', 'Symbiants', 300, 'Xan Left Arm Symbiant, Artillery Unit Beta', '', 1, NULL),
('Vortexx', 'Symbiants', 300, 'Xan Left Arm Symbiant, Control Unit Beta', '', 1, NULL),
('Vortexx', 'Symbiants', 300, 'Xan Left Arm Symbiant, Extermination Unit Beta', '', 1, NULL),
('Vortexx', 'Symbiants', 300, 'Xan Left Arm Symbiant, Infantry Unit Beta', '', 1, NULL),
('Vortexx', 'Symbiants', 300, 'Xan Left Arm Symbiant, Support Unit Beta', '', 1, NULL),
('Vortexx', 'Symbiants', 300, 'Xan Right Wrist Symbiant, Artillery Unit Beta', '', 1, NULL),
('Vortexx', 'Symbiants', 300, 'Xan Right Wrist Symbiant, Control Unit Beta', '', 1, NULL),
('Vortexx', 'Symbiants', 300, 'Xan Right Wrist Symbiant, Extermination Unit Beta', '', 1, NULL),
('Vortexx', 'Symbiants', 300, 'Xan Right Wrist Symbiant, Infantry Unit Beta', '', 1, NULL),
('Vortexx', 'Symbiants', 300, 'Xan Right Wrist Symbiant, Support Unit Beta', '', 1, NULL),
('Vortexx', 'Symbiants', 300, 'Xan Ocular Symbiant, Artillery Unit Beta', '', 1, NULL),
('Vortexx', 'Symbiants', 300, 'Xan Ocular Symbiant, Control Unit Beta', '', 1, NULL),
('Vortexx', 'Symbiants', 300, 'Xan Ocular Symbiant, Extermination Unit Beta', '', 1, NULL),
('Vortexx', 'Symbiants', 300, 'Xan Ocular Symbiant, Infantry Unit Beta', '', 1, NULL),
('Vortexx', 'Symbiants', 300, 'Xan Ocular Symbiant, Support Unit Beta', '', 1, NULL),
('Vortexx', 'Spirits', 250, 'Xan Spirit of Right Wrist Offence - Beta', '', 1, NULL),
('Vortexx', 'Spirits', 250, 'Xan Spirit of Right Wrist Weakness - Beta', '', 1, NULL),
('Vortexx', 'Spirits', 250, 'Xan Left Limb Spirit of Essence - Beta', '', 1, NULL),
('Vortexx', 'Spirits', 250, 'Xan Left Limb Spirit of Strength - Beta', '', 1, NULL),
('Vortexx', 'Spirits', 250, 'Xan Left Limb Spirit of Understanding - Beta', '', 1, NULL),
('Vortexx', 'Spirits', 250, 'Xan Left Limb Spirit of Weakness - Beta', '', 1, NULL),
('Vortexx', 'Spirits', 250, 'Xan Midriff Spirit of Essence - Beta', '', 1, NULL),
('Vortexx', 'Spirits', 250, 'Xan Midriff Spirit of Knowledge - Beta', '', 1, NULL),
('Vortexx', 'Spirits', 250, 'Xan Midriff Spirit of Strength - Beta', '', 1, NULL),
('Vortexx', 'Spirits', 250, 'Xan Midriff Spirit of Weakness - Beta', '', 1, NULL),
('Vortexx', 'Spirits', 250, 'Xan Spirit of Essence - Beta', '', 1, NULL),
('Vortexx', 'Spirits', 250, 'Xan Spirit of Discerning Weakness - Beta', '', 1, NULL),

-- Mitaar
('Mitaar', 'General', 300, 'Base NCU - Type 00 (0/6)', '', 1, NULL),
('Mitaar', 'General', 300, 'Nanodeck Activation Device', '', 1, NULL),
('Mitaar', 'General', 1, 'Multi Colored Xan Belt Tuning Device', 'For TNH belts', 1, NULL),
('Mitaar', 'General', 1, 'Green Xan Belt Tuning Device', 'For S28 belt', 1, NULL),
('Mitaar', 'General', 300, 'Xan Weapon Upgrade Device', '', 1, NULL),
('Mitaar', 'General', 1, 'Xan Defense Merit Board Base', '', 1, NULL),
('Mitaar', 'General', 1, 'Xan Combat Merit Board Base', '', 1, NULL),
('Mitaar', 'Symbiants', 300, 'Xan Brain Symbiant, Artillery Unit Beta', '', 1, NULL),
('Mitaar', 'Symbiants', 300, 'Xan Brain Symbiant, Control Unit Beta', '', 1, NULL),
('Mitaar', 'Symbiants', 300, 'Xan Brain Symbiant, Extermination Unit Beta', '', 1, NULL),
('Mitaar', 'Symbiants', 300, 'Xan Brain Symbiant, Infantry Unit Beta', '', 1, NULL),
('Mitaar', 'Symbiants', 300, 'Xan Brain Symbiant, Support Unit Beta', '', 1, NULL),
('Mitaar', 'Symbiants', 300, 'Xan Chest Symbiant, Artillery Unit Beta', '', 1, NULL),
('Mitaar', 'Symbiants', 300, 'Xan Chest Symbiant, Control Unit Beta', '', 1, NULL),
('Mitaar', 'Symbiants', 300, 'Xan Chest Symbiant, Extermination Unit Beta', '', 1, NULL),
('Mitaar', 'Symbiants', 300, 'Xan Chest Symbiant, Infantry Unit Beta', '', 1, NULL),
('Mitaar', 'Symbiants', 300, 'Xan Chest Symbiant, Support Unit Beta', '', 1, NULL),
('Mitaar', 'Symbiants', 300, 'Xan Left Hand Symbiant, Artillery Unit Beta', '', 1, NULL),
('Mitaar', 'Symbiants', 300, 'Xan Left Hand Symbiant, Control Unit Beta', '', 1, NULL),
('Mitaar', 'Symbiants', 300, 'Xan Left Hand Symbiant, Extermination Unit Beta', '', 1, NULL),
('Mitaar', 'Symbiants', 300, 'Xan Left Hand Symbiant, Infantry Unit Beta', '', 1, NULL),
('Mitaar', 'Symbiants', 300, 'Xan Left Hand Symbiant, Support Unit Beta', '', 1, NULL),
('Mitaar', 'Symbiants', 300, 'Xan Left Wrist Symbiant, Artillery Unit Beta', '', 1, NULL),
('Mitaar', 'Symbiants', 300, 'Xan Left Wrist Symbiant, Control Unit Beta', '', 1, NULL),
('Mitaar', 'Symbiants', 300, 'Xan Left Wrist Symbiant, Extermination Unit Beta', '', 1, NULL),
('Mitaar', 'Symbiants', 300, 'Xan Left Wrist Symbiant, Infantry Unit Beta', '', 1, NULL),
('Mitaar', 'Symbiants', 300, 'Xan Left Wrist Symbiant, Support Unit Beta', '', 1, NULL),
('Mitaar', 'Spirits', 250, 'Xan Brain Spirit of Computer Skill - Beta', '', 1, NULL),
('Mitaar', 'Spirits', 250, 'Xan Brain Spirit of Offence - Beta', '', 1, NULL),
('Mitaar', 'Spirits', 250, 'Xan Essence Brain Spirit - Beta', '', 1, NULL),
('Mitaar', 'Spirits', 250, 'Xan Left Hand Spirit of Defence - Beta', '', 1, NULL),
('Mitaar', 'Spirits', 250, 'Xan Left Hand Spirit of Strength - Beta', '', 1, NULL),
('Mitaar', 'Spirits', 250, 'Xan Spirit of Left Wrist Defense - Beta', '', 1, NULL),
('Mitaar', 'Spirits', 250, 'Xan Spirit of Left Wrist Strength - Beta', '', 1, NULL),
('Mitaar', 'Spirits', 250, 'Xan Heart Spirit of Essence - Beta', '', 1, NULL),
('Mitaar', 'Spirits', 250, 'Xan Heart Spirit of Knowledge - Beta', '', 1, NULL),
('Mitaar', 'Spirits', 250, 'Xan Heart Spirit of Strength - Beta', '', 1, NULL),
('Mitaar', 'Spirits', 250, 'Xan Heart Spirit of Weakness - Beta', '', 1, NULL),
('Mitaar', 'Spirits', 250, 'Xan Spirit of Clear Thought - Beta', '', 1, NULL),

-- 12 man
('12Man', 'General', 1, 'Unknown Mixture', '', 1, NULL),
('12Man', 'General', 1, 'A piece of cloth', '', 1, NULL),
('12Man', 'General', 300, 'Base NCU - Type 00 (0/6)', '', 1, NULL),
('12Man', 'General', 300, 'Nanodeck Activation Device', '', 1, NULL),
('12Man', 'General', 1, 'Multi Colored Xan Belt Tuning Device', 'For TNH belts', 1, NULL),
('12Man', 'General', 1, 'Green Xan Belt Tuning Device', 'For S28 belt', 1, NULL),
('12Man', 'General', 300, 'Xan Weapon Upgrade Device', '', 1, NULL),
('12Man', 'General', 1, 'Xan Defense Merit Board Base', '', 1, NULL),
('12Man', 'General', 1, 'Xan Combat Merit Board Base', '', 1, NULL),
('12Man', 'Symbiants', 300, 'All YesDrop Symbs', '', 1, NULL),
('12Man', 'Symbiants', 300, 'Xan Ear Symbiant, Artillery Unit Beta', 'NODROP, right click to change prof requirement', 1, NULL),
('12Man', 'Symbiants', 300, 'Xan Thigh Symbiant, Artillery Unit Beta', 'NODROP, right click to change prof requirement', 1, NULL),
('12Man', 'Symbiants', 300, 'Xan Right Arm Symbiant, Artillery Unit Beta', '', 1, NULL),
('12Man', 'Symbiants', 300, 'Xan Right Arm Symbiant, Control Unit Beta', '', 1, NULL),
('12Man', 'Symbiants', 300, 'Xan Right Arm Symbiant, Extermination Unit Beta', '', 1, NULL),
('12Man', 'Symbiants', 300, 'Xan Right Arm Symbiant, Infantry Unit Beta', '', 1, NULL),
('12Man', 'Symbiants', 300, 'Xan Right Arm Symbiant, Support Unit Beta', '', 1, NULL),
('12Man', 'Symbiants', 300, 'Xan Right Hand Symbiant, Artillery Unit Beta', '', 1, NULL),
('12Man', 'Symbiants', 300, 'Xan Right Hand Symbiant, Control Unit Beta', '', 1, NULL),
('12Man', 'Symbiants', 300, 'Xan Right Hand Symbiant, Extermination Unit Beta', '', 1, NULL),
('12Man', 'Symbiants', 300, 'Xan Right Hand Symbiant, Infantry Unit Beta', '', 1, NULL),
('12Man', 'Symbiants', 300, 'Xan Right Hand Symbiant, Support Unit Beta', '', 1, NULL),
('12Man', 'Symbiants', 300, 'Xan Feet Symbiant, Artillery Unit Beta', '', 1, NULL),
('12Man', 'Symbiants', 300, 'Xan Feet Symbiant, Control Unit Beta', '', 1, NULL),
('12Man', 'Symbiants', 300, 'Xan Feet Symbiant, Extermination Unit Beta', '', 1, NULL),
('12Man', 'Symbiants', 300, 'Xan Feet Symbiant, Infantry Unit Beta', '', 1, NULL),
('12Man', 'Symbiants', 300, 'Xan Feet Symbiant, Support Unit Beta', '', 1, NULL),
('12Man', 'Spirits', 250, 'All YesDrop Spirits', '', 1, NULL),
('12Man', 'Spirits', 250, 'Xan Right Limb Spirit of Essence - Beta', '', 1, NULL),
('12Man', 'Spirits', 250, 'Xan Right Limb Spirit of Strength - Beta', '', 1, NULL),
('12Man', 'Spirits', 250, 'Xan Right Limb Spirit of Weakness - Beta', '', 1, NULL),
('12Man', 'Spirits', 250, 'Xan Right Hand Defensive Spirit - Beta', '', 1, NULL),
('12Man', 'Spirits', 250, 'Xan Right Hand Strength Spirit - Beta', '', 1, NULL),
('12Man', 'Spirits', 250, 'Xan Spirit of Insight - Right Hand - Beta', '', 1, NULL),
('12Man', 'Spirits', 250, 'Xan Spirit of Feet Defense - Beta', '', 1, NULL),
('12Man', 'Spirits', 250, 'Xan Spirit of Feet Strength - Beta', '', 1, NULL),
('12Man', 'Spirits', 250, 'Xan Spirit of Defense - Beta', '', 1, NULL),
('12Man', 'Spirits', 250, 'Xan Spirit of Essence - Beta', '', 1, NULL),
('12Man', 'Spirits', 250, 'Xan Spirit of Essence Whispered - Beta', '', 1, NULL),
('12Man', 'Spirits', 250, 'Xan Spirit of Knowledge Whispered - Beta', '', 1, NULL),
('12Man', 'Spirits', 250, 'Xan Spirit of Strength Whispered - Beta', '', 1, NULL),
('12Man', 'Profession Gems', 1, 'Brute''s Gem', 'Enf', 1, NULL),
('12Man', 'Profession Gems', 1, 'Builder''s Gem', 'Eng', 1, NULL),
('12Man', 'Profession Gems', 1, 'Dictator''s Gem', 'Crat', 1, NULL),
('12Man', 'Profession Gems', 1, 'Explorer''s Gem', 'Adv', 1, NULL),
('12Man', 'Profession Gems', 1, 'Hacker''s Gem', 'Fix', 1, NULL),
('12Man', 'Profession Gems', 1, 'Healer''s Gem', 'Doc', 1, NULL),
('12Man', 'Profession Gems', 1, 'Master''s Gem', 'MA', 1, NULL),
('12Man', 'Profession Gems', 1, 'Merchant''s Gem', 'Trad', 1, NULL),
('12Man', 'Profession Gems', 1, 'Protecter''s Gem', 'Keep', 1, NULL),
('12Man', 'Profession Gems', 1, 'Sniper''s Gem', 'Agent', 1, NULL),
('12Man', 'Profession Gems', 1, 'Spirit''s Gem', 'Shade', 1, NULL),
('12Man', 'Profession Gems', 1, 'Techno Wizard''s Gem', 'NT', 1, NULL),
('12Man', 'Profession Gems', 1, 'Warrior''s Gem', 'Sol', 1, NULL),
('12Man', 'Profession Gems', 1, 'Worshipper''s Gem', 'MP', 1, NULL),

-- APF
('Sector 7', 'Misc', 300, 'Cell Templates', '', 1, NULL),
('Sector 7', 'Misc', 300, 'Mitochondria Samples', '', 1, NULL),
('Sector 7', 'Misc', 300, 'Plasmid Cultures', '', 1, NULL),
('Sector 7', 'Misc', 1, 'Power Core Mainboard', '', 1, NULL),
('Sector 7', 'Misc', 1, 'Power Core Stabilizer', '', 1, NULL),
('Sector 7', 'Misc', 1, 'Inactive Power Core', '', 1, NULL),
('Sector 7', 'Misc', 200, 'Basic Belt', '', 1, NULL),
('Sector 7', 'Misc', 200, 'Viral Belt Control Component', '', 1, NULL),
('Sector 7', 'Misc', 200, 'Viral Belt NCU Slots', '', 1, NULL),
('Sector 7', 'Misc', 200, 'Viral Belt Nanobot Power Unit', '', 1, NULL),
('Sector 7', 'Misc', 1, 'Kyr''Ozch Storage Box', '', 1, NULL),
('Sector 7', 'Misc', 1, 'Kyr''Ozch Storage Container', '', 1, NULL),
('Sector 7', 'NCU', 200, 'Active Viral CPU Upgrade', '', 1, NULL),
('Sector 7', 'NCU', 200, 'Active Viral Computer Deck Range Increaser', '', 1, NULL),
('Sector 7', 'NCU', 200, 'Active Viral NCU Coolant Sink', '', 1, NULL),
('Sector 7', 'NCU', 150, 'Viral Memory Storage Unit (Damage)', '', 1, NULL),
('Sector 7', 'NCU', 200, 'Passive Viral CPU Upgrade', '', 1, NULL),
('Sector 7', 'NCU', 200, 'Passive Viral Computer Deck Range Increaser', '', 1, NULL),
('Sector 7', 'NCU', 200, 'Passive Viral NCU Coolant Sink', '', 1, NULL),
('Sector 7', 'NCU', 150, 'Viral Memory Storage Unit (XP)', '', 1, NULL),
('Sector 7', 'Weapons', 150, 'Special Edition Kyr''Ozch Axe', '', 1, NULL),
('Sector 7', 'Weapons', 150, 'Special Edition Kyr''Ozch Cannon', '', 1, NULL),
('Sector 7', 'Weapons', 150, 'Special Edition Kyr''Ozch Carbine', '', 1, NULL),
('Sector 7', 'Weapons', 150, 'Special Edition Kyr''Ozch Crossbow', '', 1, NULL),
('Sector 7', 'Weapons', 150, 'Special Edition Kyr''Ozch Energy Pistol', '', 1, NULL),
('Sector 7', 'Weapons', 150, 'Special Edition Kyr''Ozch Energy Rapier', '', 1, NULL),
('Sector 7', 'Weapons', 150, 'Special Edition Kyr''Ozch Grenade Gun', '', 1, NULL),
('Sector 7', 'Weapons', 150, 'Special Edition Kyr''Ozch Hammer', '', 1, NULL),
('Sector 7', 'Weapons', 150, 'Special Edition Kyr''Ozch Nunchacko', '', 1, NULL),
('Sector 7', 'Weapons', 150, 'Special Edition Kyr''Ozch Pistol', '', 1, NULL),
('Sector 7', 'Weapons', 150, 'Special Edition Kyr''Ozch Rapier', '', 1, NULL),
('Sector 7', 'Weapons', 150, 'Special Edition Kyr''Ozch Rifle', '', 1, NULL),
('Sector 7', 'Weapons', 150, 'Special Edition Kyr''Ozch Shotgun', '', 1, NULL),
('Sector 7', 'Weapons', 150, 'Special Edition Kyr''Ozch Sledgehammer', '', 1, NULL),
('Sector 7', 'Weapons', 150, 'Special Edition Kyr''Ozch Submachine Gun', '', 1, NULL),
('Sector 7', 'Weapons', 150, 'Special Edition Kyr''Ozch Sword', '', 1, NULL),
('Sector 7', 'Viralbots', 300, 'Arithmetic Lead Viralbots', '', 1, NULL),
('Sector 7', 'Viralbots', 300, 'Enduring Lead Viralbots', '', 1, NULL),
('Sector 7', 'Viralbots', 300, 'Observant Lead Viralbots', '', 1, NULL),
('Sector 7', 'Viralbots', 300, 'Strong Lead Viralbots', '', 1, NULL),
('Sector 7', 'Viralbots', 300, 'Supple Lead Viralbots', '', 1, NULL),

('APF', 'Sector 13', 1, 'Gelatinous Lump', '', 3, NULL),
('APF', 'Sector 13', 1, 'Biotech Matrix', '', 3, NULL),
('APF', 'Sector 13', 250, 'Action Probability Estimator', '', 1, NULL),
('APF', 'Sector 13', 250, 'Dynamic Gas Redistribution Valves', '', 1, NULL),
('APF', 'Sector 13', 1, 'Kyr''Ozch Video Processing Unit', 'All Bounties', 1, NULL),
('APF', 'Sector 13', 1, 'Hacker ICE-Breaker Source', 'All ICE', 1, NULL),
('APF', 'Sector 13', 1, 'Kyr''Ozch Helmet', '2500 Token board', 1, NULL),

('APF', 'Sector 28', 1, 'Crystalline Matrix', '', 3, NULL),
('APF', 'Sector 28', 1, 'Kyr''ozch Circuitry', '', 3, NULL),
('APF', 'Sector 28', 250, 'Inertial Adjustment Processing Unit', '', 1, NULL),
('APF', 'Sector 28', 250, 'Notum Amplification Coil', '', 1, NULL),
('APF', 'Sector 28', 1, 'Kyr''Ozch Video Processing Unit', 'All Bounties', 1, NULL),
('APF', 'Sector 28', 1, 'Hacker ICE-Breaker Source', 'All ICE', 1, NULL),
('APF', 'Sector 28', 1, 'Kyr''Ozch Helmet', '2500 Token board', 1, NULL),

('APF', 'Sector 35', 1, 'Alpha Program Chip', '', 3, NULL),
('APF', 'Sector 35', 1, 'Beta Program Chip', '', 3, NULL),
('APF', 'Sector 35', 1, 'Odd Kyr''ozch Nanobots', '', 3, NULL),
('APF', 'Sector 35', 1, 'Kyr''ozch Processing Unit', '', 3, NULL),
('APF', 'Sector 35', 250, 'Energy Redistribution Unit', '', 1, NULL),
('APF', 'Sector 35', 250, 'Visible Light Remodulation Device', '', 1, NULL),
('APF', 'Sector 35', 1, 'Kyr''Ozch Video Processing Unit', 'All Bounties', 1, NULL),
('APF', 'Sector 35', 1, 'Hacker ICE-Breaker Source', 'All ICE', 1, NULL),
('APF', 'Sector 35', 1, 'Kyr''Ozch Helmet', '2500 Token board', 1, NULL),

-- Albtraum
('Albtraum', 'Crystals & Crystalised Memories', 250, 'Inert Knowledge Crystal', '', 1, NULL),
('Albtraum', 'Crystals & Crystalised Memories', 250, 'Energy Infused Crystal', '', 1, NULL),
('Albtraum', 'Crystals & Crystalised Memories', 250, 'Crystalised Memories of a Sniper', '', 1, NULL),
('Albtraum', 'Crystals & Crystalised Memories', 250, 'Crystalised Memories of a Defender', '', 1, NULL),
('Albtraum', 'Crystals & Crystalised Memories', 250, 'Crystalised Memories of a Technician', '', 1, NULL),
('Albtraum', 'Crystals & Crystalised Memories', 250, 'Crystalised Memories of a Mechanic', '', 1, NULL),
('Albtraum', 'Crystals & Crystalised Memories', 250, 'Crystalised Memories of a Surgeon', '', 1, NULL),
('Albtraum', 'Crystals & Crystalised Memories', 250, 'Crystalised Memories of a Engineer', '', 1, NULL),
('Albtraum', 'Crystals & Crystalised Memories', 250, 'Crystalised Memories of an Instructor', '', 1, NULL),
('Albtraum', 'Crystals & Crystalised Memories', 250, 'Crystalised Memories of a Doctor', '', 1, NULL),
('Albtraum', 'Crystals & Crystalised Memories', 250, 'Crystalised Memories of a Warrior', '', 1, NULL),
('Albtraum', 'Crystals & Crystalised Memories', 250, 'Crystalised Memories of an Archer', '', 1, NULL),
('Albtraum', 'Crystals & Crystalised Memories', 250, 'Crystalised Memories of a Scientist', '', 1, NULL),
('Albtraum', 'Samples', 250, 'Radioactive Gland Sample', '', 1, NULL),
('Albtraum', 'Samples', 250, 'Venom Gland Sample', '', 1, NULL),
('Albtraum', 'Samples', 250, 'Frost Gland Sample', '', 1, NULL),
('Albtraum', 'Samples', 250, 'Acid Gland Sample', '', 1, NULL),
('Albtraum', 'Samples', 250, 'Fire Gland Sample', '', 1, NULL),
('Albtraum', 'Pocket Boss Crystals', 250, 'Xan Essence Crystal - Summoned Terror', '', 1, NULL),
('Albtraum', 'Pocket Boss Crystals', 250, 'Xan Essence Crystal - Gruesome Misery', '', 1, NULL),
('Albtraum', 'Pocket Boss Crystals', 250, 'Xan Essence Crystal - Sister Pestilence', '', 1, NULL),
('Albtraum', 'Pocket Boss Crystals', 250, 'Xan Essence Crystal - Sister Merciless', '', 1, NULL),
('Albtraum', 'Pocket Boss Crystals', 250, 'Xan Essence Crystal - Divided Loyalty', '', 1, NULL),
('Albtraum', 'Rings and Preservation Units', 250, 'Ancient Speed Preservation Unit', '', 1, NULL),
('Albtraum', 'Rings and Preservation Units', 250, 'Ancient Vision Preservation Unit', '', 1, NULL),
('Albtraum', 'Rings and Preservation Units', 250, 'Ring of Divided Loyalty', '', 1, NULL),
('Albtraum', 'Rings and Preservation Units', 250, 'Ring of Gruesome Misery', '', 1, NULL),
('Albtraum', 'Rings and Preservation Units', 250, 'Ring of Sister Pestilence', '', 1, NULL),
('Albtraum', 'Rings and Preservation Units', 250, 'Ring of Sister Merciless', '', 1, NULL),
('Albtraum', 'Rings and Preservation Units', 250, 'Ring of Summoned Terror', '', 1, NULL),
('Albtraum', 'Ancients', 250, 'Dormant Ancient Circuit', '', 1, NULL),
('Albtraum', 'Ancients', 250, 'Empty Ancient Device', '', 1, NULL),
('Albtraum', 'Ancients', 250, 'Inactive Ancient Bracer', '', 1, NULL),
('Albtraum', 'Ancients', 250, 'Ancient Scrap of Spirit Knowledge', '', 1, NULL),
('Albtraum', 'Ancients', 250, 'Ancient Scrap of Saturated Spirit Knowledge', '', 1, NULL),
('Albtraum', 'Ancients', 250, 'Ancient Damage Generation Device', '', 1, NULL),
('Albtraum', 'Ancients', 250, 'Inactive Ancient Medical Device', '', 1, NULL),
('Albtraum', 'Ancients', 250, 'Inactive Ancient Engineering Device', '', 1, NULL),

-- Dust Brigade
('DustBrigade', 'Armor', 200, 'Enhanced Dustbrigade Combat Chestpiece', '', 1, NULL),
('DustBrigade', 'Armor', 200, 'Enhanced Dustbrigade Spirit-tech Chestpiece', '', 1, NULL),
('DustBrigade', 'Armor', 200, 'Enhanced Dustbrigade Sleeves', '', 1, NULL),
('DustBrigade', 'Armor', 200, 'Enhanced Dustbrigade Notum Gloves', '', 1, NULL),
('DustBrigade', 'Armor', 200, 'Enhanced Dustbrigade Chemist Gloves', '', 1, NULL),
('DustBrigade', 'Armor', 200, 'Enhanced Dustbrigade Covering', '', 1, NULL),
('DustBrigade', 'Armor', 200, 'Enhanced Dustbrigade Flexible Boots', '', 1, NULL),
('DustBrigade', 'DB1', 200, 'Enhanced Safeguarded NCU Memory Unit', 'Int+Psy', 1, 269985),
('DustBrigade', 'DB1', 200, 'Enhanced Safeguarded NCU Memory Unit', 'Str+Sta', 1, 269986),
('DustBrigade', 'DB1', 200, 'Enhanced Safeguarded NCU Memory Unit', 'Agi+Sen', 1, 269987),
('DustBrigade', 'DB1', 200, 'Protected Safeguarded NCU Memory Unit', 'evades', 1, NULL),
('DustBrigade', 'DB1', 250, 'Master Melee Program', 'Alappaa Pad Upgrade', 1, NULL),
('DustBrigade', 'DB1', 250, 'Master Combat Program', 'Alappaa Pad Upgrade', 1, NULL),
('DustBrigade', 'DB1', 250, 'Master Nano Technology Program', 'Alappaa Pad Upgrade', 1, NULL),
('DustBrigade', 'DB2', 250, 'Basic Infused Dust Brigade Bracer', '', 1, NULL),
('DustBrigade', 'DB2', 250, 'Dust Brigade Notum Infuser', 'DB Bracer/Alb Item Upgrade', 2, NULL),
('DustBrigade', 'DB2', 250, 'White Molybdenum-Matrix of Xan', 'Kegern/Jathos Upgrades', 1, NULL),
('DustBrigade', 'DB2', 250, 'Black Molybdenum-Matrix of Xan', 'Kegern/Jathos Upgrades', 1, NULL),
('DustBrigade', 'DB2', 300, 'Dust Brigade Engineer Pistol', '', 1, NULL),
('DustBrigade', 'DB2', 250, 'Dust Brigade Solar Notum Infuser', 'Engineer DB Pistol Upgrade', 1, NULL),

-- Pande
('Pande', 'Beast Armor', 300, 'Sigil of Bahomet', '', 1, NULL),
('Pande', 'Beast Armor', 300, 'Helmet of Hypocrisy', '', 1, NULL),
('Pande', 'Beast Armor', 300, 'Burden of Competence', '', 1, NULL),
('Pande', 'Beast Armor', 300, 'Shoulderplates of Sabotage', '', 1, NULL),
('Pande', 'Beast Armor', 300, 'Cuirass of Obstinacy', '', 1, NULL),
('Pande', 'Beast Armor', 300, 'Sleeves of Senseless Violence', '', 1, NULL),
('Pande', 'Beast Armor', 300, 'Gauntlets of Deformation', '', 1, NULL),
('Pande', 'Beast Armor', 300, 'Armplates of Elimination', '', 1, NULL),
('Pande', 'Beast Armor', 300, 'Greaves of Malfeasance', '', 1, NULL),
('Pande', 'Beast Armor', 300, 'Boots of Concourse', '', 1, NULL),
('Pande', 'Beast Weapons', 300, 'Lord of Abandonment', '1HB', 1, NULL),
('Pande', 'Beast Weapons', 299, 'Lady of Abandonment', '1HB', 1, NULL),
('Pande', 'Beast Weapons', 300, 'Lord of Anger', 'Assault Rifle', 1, NULL),
('Pande', 'Beast Weapons', 299, 'Lady of Anger', 'Assault Rifle', 1, NULL),
('Pande', 'Beast Weapons', 300, 'Lord of Angst', 'Rifle', 1, NULL),
('Pande', 'Beast Weapons', 299, 'Lady of Angst', 'Rifle', 1, NULL),
('Pande', 'Beast Weapons', 300, 'Lord of Chaos', 'Bow', 1, NULL),
('Pande', 'Beast Weapons', 299, 'Lady of Chaos', 'Bow', 1, NULL),
('Pande', 'Beast Weapons', 300, 'Lord of Deceit', 'Piercing', 1, NULL),
('Pande', 'Beast Weapons', 299, 'Lady of Deceit', 'Piercing', 1, NULL),
('Pande', 'Beast Weapons', 300, 'Lord of Envy', 'SMG', 1, NULL),
('Pande', 'Beast Weapons', 299, 'Lady of Envy', 'SMG', 1, NULL),
('Pande', 'Beast Weapons', 300, 'Lord of Gluttony', '2HB', 1, NULL),
('Pande', 'Beast Weapons', 299, 'Lady of Gluttony', '2HB', 1, NULL),
('Pande', 'Beast Weapons', 300, 'Lord of Greed', 'Shotgun', 1, NULL),
('Pande', 'Beast Weapons', 299, 'Lady of Greed', 'Shotgun', 1, NULL),
('Pande', 'Beast Weapons', 300, 'Lord of Hatred', '1HE', 1, NULL),
('Pande', 'Beast Weapons', 299, 'Lady of Hatred', '1HE', 1, NULL),
('Pande', 'Beast Weapons', 300, 'Lord of Lust', 'Pistol', 1, NULL),
('Pande', 'Beast Weapons', 299, 'Lady of Lust', 'Pistol', 1, NULL),
('Pande', 'Beast Weapons', 300, 'Lord of Pride', '2HE', 1, NULL),
('Pande', 'Beast Weapons', 299, 'Lady of Pride', '2HE', 1, NULL),
('Pande', 'Beast Weapons', 300, 'Lord of Sloth', 'Grenades', 1, NULL),
('Pande', 'Beast Weapons', 299, 'Lady of Sloth', 'Grenades', 1, NULL),
('Pande', 'Beast Weapons', 200, 'Sunrise Hilt', 'Melee Energy', 1, NULL),
('Pande', 'Beast Weapons', 200, 'Sunset Hilt', 'Melee Energy', 1, NULL),
('Pande', 'Beast Weapons', 300, 'Lady of Wisdom', 'Martial Arts', 1, 294000),
('Pande', 'Beast Weapons', 300, 'Lord of Wisdom', 'Martial Arts', 1, 293997),

('Pande', 'Stars', 250, 'Star of Ardency', 'NT', 1, NULL),
('Pande', 'Stars', 250, 'Star of Enterprice', 'Fix', 1, NULL),
('Pande', 'Stars', 250, 'Star of Enticement', 'Shade', 1, NULL),
('Pande', 'Stars', 250, 'Star of Equanimity', 'MA', 1, NULL),
('Pande', 'Stars', 250, 'Star of Faith', 'Keep', 1, NULL),
('Pande', 'Stars', 250, 'Star of Fidelity', 'Sol', 1, NULL),
('Pande', 'Stars', 250, 'Star of Fortitude', 'Enfo', 1, NULL),
('Pande', 'Stars', 250, 'Star of Freedom', 'Adv', 1, NULL),
('Pande', 'Stars', 250, 'Star of Ingenuity', 'Eng', 1, NULL),
('Pande', 'Stars', 250, 'Star of Interchange', 'Trad', 1, NULL),
('Pande', 'Stars', 250, 'Star of Management', 'Crat', 1, NULL),
('Pande', 'Stars', 250, 'Star of Moral', 'MP', 1, NULL),
('Pande', 'Stars', 250, 'Star of Recovery', 'Doc', 1, NULL),
('Pande', 'Stars', 250, 'Star of Stealth', 'Agent', 1, NULL),
('Pande', 'Shadowbreeds', 1, 'The Lighter Side', 'Clan SB', 1, NULL),
('Pande', 'Shadowbreeds', 1, 'The Darker Side', 'Omni SB', 1, NULL),
('Pande', 'Shadowbreeds', 1, 'The Unknown Path', 'Neutral SB', 1, NULL),
('Pande', 'The Night Heart', 300, 'Maar''s Blue Belt of Double Prudence', 'INT/PSY', 1, NULL),
('Pande', 'The Night Heart', 300, 'Maar''s Red Belt of Double Power', 'STR/STA', 1, NULL),
('Pande', 'The Night Heart', 300, 'Maar''s Yellow Belt of Double Speed', 'AGI/SEN', 1, NULL),
('Pande', 'The Night Heart', 200, 'Notum Seed', '', 1, NULL),
('Pande', 'The Night Heart', 200, 'Novictum Seed', '', 1, NULL),

('Pande', 'Aries', 250, 'Dynamic Sleeve of Aries', '', 1, NULL),
('Pande', 'Aries', 250, 'Aries'' Tiara of the Quick Witted', '', 1, NULL),
('Pande', 'Aries', 250, 'Quick-Draw Holster of Aries', '', 1, NULL),
('Pande', 'Aries', 250, 'Boon of Aries', '', 1, NULL),
('Pande', 'Leo', 250, 'Enthusiastic Spirit Helper of the Leo', '', 1, NULL),
('Pande', 'Leo', 250, 'Leo''s Faithful Boots of Ancient Gold', '', 1, NULL),
('Pande', 'Leo', 250, 'Leo''s Grandiose Gold Armband of Plenty', '', 1, NULL),
('Pande', 'Leo', 250, 'Leo''s Mellow Gold Pad of Auto-Support', '', 1, NULL),
('Pande', 'Virgo', 250, 'Virgo''s Arrow Guide', '', 1, NULL),
('Pande', 'Virgo', 250, 'Virgo''s Analytical Spirit Helper', '', 1, NULL),
('Pande', 'Virgo', 250, 'Virgo''s Practical Spirit Helper', '', 1, NULL),
('Pande', 'Virgo', 250, 'Virgo''s Modest Spirit of Faith', '', 1, NULL),
('Pande', 'Aquarius', 250, 'Aquarius'' Boots of Small Steps', '', 1, NULL),
('Pande', 'Aquarius', 250, 'Mediative Gloves of the Aquarius', '', 1, NULL),
('Pande', 'Aquarius', 250, 'Intuitive Memory of the Aquarius', '', 1, NULL),
('Pande', 'Aquarius', 250, 'Aquarius''s Multitask Calculator', '', 1, NULL),
('Pande', 'Cancer', 250, 'Cancer''s Gloves of Automatic Knowledge', '', 1, NULL),
('Pande', 'Cancer', 250, 'Cancer''s Silver Boots of the Autodidact', '', 1, NULL),
('Pande', 'Cancer', 250, 'Cancer''s Ring of Circumspection', '', 1, NULL),
('Pande', 'Cancer', 250, 'Cancer''s Time-Saving Memory', '', 1, NULL),
('Pande', 'Gemini', 250, 'Collector Pants of Gemini', '', 1, NULL),
('Pande', 'Gemini', 250, 'Gemini''s Double Band of Linked Information', '', 1, NULL),
('Pande', 'Gemini', 250, 'Cross Dimensional Gyro of Gemini', '', 1, NULL),
('Pande', 'Gemini', 250, 'Gemini''s Green Scope of Variety', '', 1, NULL),
('Pande', 'Libra', 250, 'Libra''s Charming Assistant', '', 1, NULL),
('Pande', 'Libra', 250, 'Urbane Pants of Libra', '', 1, NULL),
('Pande', 'Libra', 250, 'Aim of Libra', '', 1, NULL),
('Pande', 'Libra', 250, 'Well Balanced Spirit Helper of Libra', '', 1, NULL),
('Pande', 'Libra', 1, 'Activation Code', '', 6, NULL),
('Pande', 'Pisces', 250, 'Cosmic Guide of the Pisces', '', 1, NULL),
('Pande', 'Pisces', 250, 'Octopus Contraption of the Pisces', '', 1, NULL),
('Pande', 'Pisces', 250, 'Soul Mark of Pisces', '', 1, NULL),
('Pande', 'Pisces', 250, 'Mystery of Pisces', '', 1, NULL),
('Pande', 'Taurus', 250, 'Taurus'' Ring of the Heart', '', 1, NULL),
('Pande', 'Taurus', 250, 'Taurus'' Spirit of Patience', '', 1, NULL),
('Pande', 'Taurus', 250, 'Taurus'' Swordmaster Spirit', '', 1, NULL),
('Pande', 'Taurus', 250, 'Taurus'' Spirit of Reflection', '', 1, NULL),
('Pande', 'Capricorn', 250, 'Capricorn Bracer of Toxication', '', 1, NULL),
('Pande', 'Capricorn', 250, 'Gloves of the Caring Capricorn', '', 1, NULL),
('Pande', 'Capricorn', 250, 'Capricorn''s Reliable Memory', '', 1, NULL),
('Pande', 'Capricorn', 250, 'Capricorn''s Guide to Alchemy', '', 1, NULL),
('Pande', 'Sagittarius', 250, 'Comfort of the Sagittarius', '', 1, NULL),
('Pande', 'Sagittarius', 250, 'First Creation of the Sagittarius', '', 1, NULL),
('Pande', 'Sagittarius', 250, 'Sagittarius''s Hearty Spirit Helper', '', 1, NULL),
('Pande', 'Sagittarius', 250, 'Strong Mittens of the Sagittarius', '', 1, NULL),
('Pande', 'Scorpio', 250, 'Punters of the Scorpio', '', 1, NULL),
('Pande', 'Scorpio', 250, 'Scorpio''s Shell of Change', '', 1, NULL),
('Pande', 'Scorpio', 250, 'Scorpio''s Aim of Anger', '', 1, NULL),
('Pande', 'Scorpio', 250, 'Sash of Scorpio Strength', '', 1, NULL),

('Pande', 'Bastion', 1, 'SSC "Bastion" Back Armor - Inactive', '', 1, NULL),
('Pande', 'Bastion', 1, 'SSC "Bastion" Left Shoulder Armor - Inactive', '', 1, NULL),
('Pande', 'Bastion', 1, 'SSC "Bastion" Right Shoulder Armor - Inactive', '', 1, NULL),
('Pande', 'Bastion', 250, 'NCU Infuser', '', 1, NULL),
('Pande', 'Bastion', 1, 'Collatz Upgrade Plate', '', 1, NULL),
('Pande', 'Bastion', 1, 'Fatou Upgrade Plate', '', 1, NULL),
('Pande', 'Bastion', 1, 'Mandelbrot Upgrade Plate', '', 1, NULL),
('Pande', 'Bastion', 1, 'A Single Strand of Glowing Dark Energy', '', 1, NULL),
('Pande', 'Bastion', 1, 'Inert Bacteriophage', '', 1, NULL),
('Pande', 'Bastion', 1, 'Pattern Conversion Device', '', 1, NULL),
('Pande', 'Bastion', 1, 'Nickel-Cobalt Ferrous Alloy', '', 1, NULL),
('Pande', 'Bastion', 1, 'Dacite Fiber', '', 1, NULL),
('Pande', 'Bastion', 1, 'Sealed Packet of Bilayer Graphene Sheets', '', 1, NULL),
('Pande', 'Bastion', 1, 'Mu-Negative Novictum Enriched Metamaterial', '', 1, NULL),
('Pande', 'Bastion', 1, 'Compressed Silane', '', 1, NULL),
('Pande', 'Bastion', 1, 'Potassium Nitrate', '', 1, NULL),
('Pande', 'Bastion', 1, 'VLS Synthesis Catalyst', '', 1, NULL),
('Pande', 'Bastion', 1, 'Bi-Isotropic Nano Media', '', 1, NULL),
('Pande', 'Bastion', 1, 'Synchotronic Recombinator - Pocket Edition (Empty)', '', 1, NULL),
('Pande', 'Bastion', 1, 'Red Data Crystal', '', 1, NULL),
('Pande', 'Bastion', 1, 'Blue Data Crystal', '', 1, NULL),
('Pande', 'Bastion', 1, 'Green Data Crystal', '', 1, NULL),
('Pande', 'Bastion', 1, 'Virus Programming: Bacteriophage Phi X 3957', '', 1, NULL),
('Pande', 'Bastion', 1, 'Virus Programming: Bacteriophage M73', '', 1, NULL),
('Pande', 'Bastion', 1, 'Virus Programming: Bacteriophage F9', '', 1, NULL),

('Pyramid of Home', 'General', 300, 'Inert Sigil of Alighieri', '', 1, NULL),
('Pyramid of Home', 'General', 300, 'Inert Sigil of Machiavelli', '', 3, NULL),
('Pyramid of Home', 'General', 300, 'Portable Notum Infusion Device', 'Activates Sigils', 1, NULL),
('Pyramid of Home', 'General', 1, 'Laughing Spirit Capsule', 'Boss 1', 3, NULL),
('Pyramid of Home', 'General', 1, 'Crying Spirit Capsule', 'Boss 2', 3, NULL),
('Pyramid of Home', 'General', 1, 'Screaming Spirit Capsule', 'Boss 3', 3, NULL),
('Pyramid of Home', 'HUD/NCU', 100, 'Ancient Resorative Fungus', '', 1, NULL),
('Pyramid of Home', 'HUD/NCU', 300, 'Sluggish Notum Lens', '', 1, NULL),
('Pyramid of Home', 'HUD/NCU', 100, 'Ancient Aggressive Webbing', '', 1, NULL),
('Pyramid of Home', 'HUD/NCU', 300, 'Dense Nanite Aegis', '', 1, NULL),
('Pyramid of Home', 'HUD/NCU', 100, 'Ancient Protective Drone', '', 1, NULL),
('Pyramid of Home', 'Weapons', 300, 'Achaean Conqueror', 'Heavy Weapons', 1, NULL),
('Pyramid of Home', 'Weapons', 300, 'Corrupted Lord of Abandonment', '1HB', 1, NULL),
('Pyramid of Home', 'Weapons', 300, 'Corrupted Lord of Anger', 'Assault Rifle', 1, NULL),
('Pyramid of Home', 'Weapons', 300, 'Corrupted Lord of Angst', 'Rifle', 1, NULL),
('Pyramid of Home', 'Weapons', 300, 'Corrupted Lord of Chaos', 'Bow', 1, NULL),
('Pyramid of Home', 'Weapons', 300, 'Corrupted Lord of Deceit', 'Piercing', 1, NULL),
('Pyramid of Home', 'Weapons', 300, 'Corrupted Lord of Envy', 'SMG', 1, NULL),
('Pyramid of Home', 'Weapons', 300, 'Corrupted Lord of Gluttony', '2HE', 1, NULL),
('Pyramid of Home', 'Weapons', 300, 'Corrupted Lord of Greed', 'Shotgun', 1, NULL),
('Pyramid of Home', 'Weapons', 300, 'Corrupted Lord of Hatred', '1HE', 1, NULL),
('Pyramid of Home', 'Weapons', 300, 'Corrupted Lord of Lust', 'Pistol', 1, NULL),
('Pyramid of Home', 'Weapons', 300, 'Corrupted Lord of Pride', '2HE', 1, NULL),
('Pyramid of Home', 'Weapons', 300, 'Corrupted Lord of Sloth', 'Grenades', 1, NULL),
('Pyramid of Home', 'Weapons', 300, 'Corrupted Lady of Wisdom', 'MA', 1, 302947),
('Pyramid of Home', 'Weapons', 300, 'Corrupted Lord of Wisdom', 'MA', 1, 302945),

('Temple of the Three Winds', 'Armor', 1, 'Desecrated Flesh', '', 1, NULL),
('Temple of the Three Winds', 'Armor', 1, 'Fist of Heavens', 'MA', 1, NULL),
('Temple of the Three Winds', 'Armor', 1, 'Gartua''s Second Coat', '', 1, NULL),
('Temple of the Three Winds', 'Armor', 1, 'Mountain Razing Gauntlets', '', 1, NULL),
('Temple of the Three Winds', 'Armor', 1, 'Strength of the Immortal', '', 1, NULL),
('Temple of the Three Winds', 'Symbiants', 1, 'Blessing of the Gripper', '', 1, NULL),
('Temple of the Three Winds', 'Symbiants', 1, 'Grasp of the Immortal', 'MA', 1, NULL),
('Temple of the Three Winds', 'Symbiants', 1, 'Inner Peace', '', 1, NULL),
('Temple of the Three Winds', 'Symbiants', 1, 'Keeper''s Vigor', '', 1, NULL),
('Temple of the Three Winds', 'Symbiants', 1, 'Nematet''s Third Eye', '', 1, NULL),
('Temple of the Three Winds', 'Symbiants', 1, 'Notum Graft', '', 1, NULL),
('Temple of the Three Winds', 'Symbiants', 1, 'Vision of the Heretic', '', 1, NULL),
('Temple of the Three Winds', 'Symbiants', 1, 'Wit of the Immortal', 'MP, NT', 1, NULL),
('Temple of the Three Winds', 'Symbiants', 300, 'Ethereal Embrace', 'Shade', 1, NULL),
('Temple of the Three Winds', 'Misc', 1, 'Knowledge of the Immortal One', '+160 to tradeskills', 1, NULL),
('Temple of the Three Winds', 'Misc', 1, 'Sacred Text of the Immortal One', 'Doc', 1, NULL),
('Temple of the Three Winds', 'Misc', 1, 'Summoner''s Staff of Dismissal', 'MP, Crat, Engi', 1, NULL),
('Temple of the Three Winds', 'NCU', 1, 'Aegis Circuit Board', '', 1, NULL),
('Temple of the Three Winds', 'NCU', 1, 'Lucid Nightmares', '', 1, NULL),
('Temple of the Three Winds', 'NCU', 1, 'Memory of Future Events', '', 1, NULL),
('Temple of the Three Winds', 'NCU', 1, 'Mnemonic Shard', '', 1, NULL),
('Temple of the Three Winds', 'Weapons', 1, 'Bone Staff of The Immortal Summoner', '', 1, NULL),
('Temple of the Three Winds', 'Weapons', 300, 'Ceremonial Blade', '1HE', 1, NULL),
('Temple of the Three Winds', 'Weapons', 300, 'Corrupted Edge', '2HE', 1, NULL),
('Temple of the Three Winds', 'Weapons', 300, 'Envoy to Chaos', 'Pistol', 1, NULL),
('Temple of the Three Winds', 'Weapons', 300, 'Uklesh''s Talon', 'Piercing', 1, NULL),
('Temple of the Three Winds', 'Weapons', 1, 'Obsidian Desecrator', '2HE', 1, NULL),
('Temple of the Three Winds', 'Weapons', 1, 'Sacred Chalice', 'Doc', 1, NULL);

-- GUPHs
INSERT INTO raid_loot (raid, category, ql, name, comment, multiloot, aoid) VALUES
('Halloween', 'Griefing Uncle Pumpkin-Head',   1, 'Sparkling Freedom Arms 3927', '', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head',  10, 'Battered Freedom Arms 3927', '', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head',  40, 'Freedom Arms 3927a', '', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head',  44, 'Freedom Arms 3927', '', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head',  96, 'Freedom Arms 3927k', '', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 100, 'Freedom Arms 3927k Ultra', '', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 108, 'Freedom Arms 3927 Notum', '', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 150, 'Freedom Arms 3927 Chapman', '', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 158, 'Freedom Arms 3927 Guerrilla', '', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 200, 'Freedom Arms 3927 G2', '', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 100, 'Ai-X44 Android Head', 'QL1-200', 1, 152269),
('Halloween', 'Griefing Uncle Pumpkin-Head', 100, 'BBQ Shoulder Pillow', 'QL1-200', 1, 152258),
('Halloween', 'Griefing Uncle Pumpkin-Head', 175, 'Black Agent Cloak', '', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head',  25, 'Blue Baby Bronto Boots', '', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 100, 'Bodum-Larga NCU', 'QL1-200', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 100, 'Candied Fruit Armband', 'QL1-200', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 100, 'Candy Cord', 'QL1-200', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 100, 'Capsule of Thin Blood', 'QL1-200', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 100, 'Carrier Craft', 'QL1-200', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 100, 'Counterfeit Omni Epaulet', 'QL1-200', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 200, 'Extreme Low Light Targeting Scope', '', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 100, 'Jones Energized Carbonan Helmet', 'QL1-200', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 100, 'Metallic Hoop', 'QL1-200', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 100, 'Nano Targeting Helper', 'QL1-200', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 100, 'NCU Robot Reed', 'QL1-200', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 100, 'Neural Interpreting Nball - Handguns', 'QL1-200', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 100, 'Omni Epaulet', 'QL1-200', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 175, 'Real Knickers Stockings', '', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head',  10, 'Reinforced Blackpants', '', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 100, 'Ring of Endurance', 'QL1-300', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 100, 'Ring of Essence', 'QL1-300', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head',  40, 'Starched Armbands', '', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 100, 'Sunglasses of Syncopated Heartbeats', '', 1, 163631),
('Halloween', 'Griefing Uncle Pumpkin-Head', 100, 'Support Wire', 'QL1-200', 1, null),
('Halloween', 'Griefing Uncle Pumpkin-Head', 175, 'White Agent Cloak', '', 1, null);

-- HUPHs
INSERT INTO raid_loot (raid, category, ql, name, comment, multiloot, aoid) VALUES
('Halloween', 'Harvesting Uncle Pumpkin-Head',   1, 'Nano Crystal (Junior Pumpkin-Head)', '', 6, null);

-- HUPHs
INSERT INTO raid_loot (raid, category, ql, name, comment, multiloot, aoid) VALUES
('Halloween', 'Solo Instance', 1, 'Freedom Arms 3927 Chameleon', 'Can drop from all special mobs', 1, null),
('Halloween', 'Solo Instance', 1, 'Rabbit Ears - Black', 'Drops from boss mobs', 1, null),
('Halloween', 'Solo Instance', 1, 'Rabbit Ears - Blue', 'Drops from boss mobs', 1, null),
('Halloween', 'Solo Instance', 1, 'Scythe of the Harvester', 'Drops from end boss', 1, null),
('Halloween', 'Solo Instance', 1, 'Beacon of the Harvester', 'Drops from end boss', 1, null);

CREATE INDEX idx_raid_loot_raid ON raid_loot(raid);
CREATE INDEX idx_raid_loot_category ON raid_loot(category);