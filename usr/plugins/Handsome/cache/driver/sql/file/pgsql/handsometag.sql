CREATE SEQUENCE "handsome_tag_seq";

CREATE TABLE "%dbname%"
(
    "tid"    INT NOT NULL DEFAULT nextval('handsome_tag_seq'),
    "name"   VARCHAR(200) NULL DEFAULT NULL,
    "icon"  VARCHAR(200) NULL DEFAULT NULL,
    "count"  INT NULL DEFAULT '0',
    "order"   INT NULL DEFAULT '0',
    "parent" INT NULL DEFAULT '0',
    PRIMARY KEY ("tid")
);

-- CREATE INDEX "handsome_tag_name" ON "%dbname%" ("name");