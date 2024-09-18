<?php

abstract class SqlInterface
{
    public $db = null;
    public $db_name = "";
    public $before_exist = false;

    protected $ty_db_name = "";
    protected $type = "";

    public function __construct($db_name, $type, $check_db = true)
    {
        $this->db = Typecho_Db::get();
        $this->db_name = $db_name;
        $this->ty_db_name = $this->db->getPrefix() . $this->db_name;
        $this->type = $type;
        if ($check_db) {
            $this->before_exist = $this->is_exist_table();
            if (!$this->before_exist) {
                $this->install();
            }
        } else {
            $this->before_exist = true;
        }
    }

    public abstract function is_exist_table();

    public abstract function install();

    public function get_sql_str()
    {
        $sql = file_get_contents(__DIR__ . '/file/' . $this->type . '/' . $this->db_name . '.sql');
        return str_replace('typecho_', $this->db->getPrefix(), $sql);

    }
}

?>
