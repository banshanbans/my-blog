DROP TABLE IF EXISTS `%dbname%`;
CREATE TABLE `%dbname%`
(
    `key`  char(32) NOT NULL,
    `data` longtext,
    `time` bigint(20) DEFAULT NULL,
    `type` char(10),
    PRIMARY KEY (`key`)
) ENGINE=MyISAM  DEFAULT CHARSET =%charset%;