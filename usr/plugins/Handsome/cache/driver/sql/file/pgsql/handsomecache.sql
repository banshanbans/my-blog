DROP TABLE IF EXISTS %dbname%;
CREATE TABLE %dbname% (
    "key" varchar(32) NOT NULL PRIMARY KEY,
    "data" text,
    "time" bigint DEFAULT NULL,
    "type" varchar(10)
);