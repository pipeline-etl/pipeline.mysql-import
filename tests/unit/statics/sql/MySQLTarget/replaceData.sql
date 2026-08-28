INSERT INTO `table` (`id`, `language`, `revision`, `name`, `valid`)
VALUES ('a1', 'en-US', 3, 'yeah', 0)
AS `new`
ON DUPLICATE KEY UPDATE `id` = `new`.`id`,
`language` = `new`.`language`,
`revision` = `new`.`revision`,
`name` = `new`.`name`,
`valid` = `new`.`valid`
