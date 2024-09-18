CREATE TABLE "%dbname%"
(  "coid" int(10) NOT NULL ,
   "tid" int(10) NOT NULL
);

CREATE UNIQUE INDEX handsome_relationships_coid_tid ON "%dbname%" ("coid", "tid");
