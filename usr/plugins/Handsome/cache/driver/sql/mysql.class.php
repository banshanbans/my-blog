<?php

class HandsomeSQL extends SqlInterface
{
    public function is_exist_table()
    {
        $sql = "SHOW TABLES LIKE '%" . $this->ty_db_name . "%'";
        if (count($this->db->fetchAll($sql)) == 0) {
            return false;
        }else{
            return true;
        }
    }

    public function install()
    {
        $sql = $this->get_sql_str();
        $search = array('%dbname%', '%charset%');
        $charset = str_replace('utf-8', 'utf8', strtolower(Helper::options()->charset));
        $charset = "utf8mb4";
        $replace = array($this->ty_db_name, $charset);

        $sql = str_replace($search, $replace, $sql);
        $sqls = explode(';', $sql);
        foreach ($sqls as $sql) {
            if (trim($sql) != ""){
                $this->db->query($sql);
            }
        }
    }
}
