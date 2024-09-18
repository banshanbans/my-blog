CREATE TABLE "%dbname%"
(
    `tid`    INTEGER NOT NULL PRIMARY KEY,
    `name`   varchar(200) default NULL ,
    `icon`   varchar(200) default NULL ,
    `count`  int(10) default '0' ,
    `order`  int(10) default '0' ,
    `parent` int(10) default '0'
);