CREATE TABLE `%dbname%`
(
    `coid` int(10) unsigned NOT NULL comment '评论的id',
    `tid`  int(10) unsigned NOT NULL comment 'tag id',
    PRIMARY KEY (`coid`, `tid`)
) ENGINE=MyISAM  DEFAULT CHARSET =%charset%;