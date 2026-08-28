INSERT INTO `table` (`id`, `language`, `revision`, `name`, `valid`)
VALUES ('a1', 'en-US', 3, 'yeah', 0),
('b2', 'fr-FR', 5, 'he', 1),
('c3', 'de-DE', 5, 'he', 0),
('d4', 'nl-NL', 5, 'she', 1)
AS `new`
ON DUPLICATE KEY UPDATE `id` = `new`.`id`,
`language` = `new`.`language`,
`revision` = `new`.`revision`,
`name` = `new`.`name`,
`valid` = `new`.`valid`
