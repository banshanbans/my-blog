CREATE TABLE `%dbname%`
(
    `tid`    int(10) unsigned NOT NULL auto_increment COMMENT 'tag表主键',
    `name`   varchar(200)     default NULL,
    `icon`   varchar(200)     default NULL COMMENT 'tag图标',
    `count`  int(10) unsigned default '0',
    `order`  int(10) unsigned default '0',
    `parent` int(10) unsigned default '0',
    PRIMARY KEY (`tid`),
    KEY `name` (`name`)
) ENGINE=MyISAM DEFAULT CHARSET =%charset%;