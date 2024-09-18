<?php

class HandsomeSQL extends SqlInterface{

    public function is_exist_table()
    {
        $sql = "select 1 from pg_class where relname='".$this->ty_db_name."'::name and relkind='r'";
        if (count($this->db->fetchAll($sql)) == 0) {
            return false;
        }else{
            return true;
        }
    }

    public function install()
    {
        $sql = $this->get_sql_str();
        $search = array('%dbname%');
        $replace = array($this->ty_db_name);

        $sql = str_replace($search, $replace, $sql);
        $sqls = explode(';', $sql);
        foreach ($sqls as $sql) {
            if (trim($sql) != ""){
                $this->db->query($sql);
            }
        }
    }
}
