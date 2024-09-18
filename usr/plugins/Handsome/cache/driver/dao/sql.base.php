<?php

abstract class SqlBase
{

    public $db = null; // 语法糖
    public $db_name = "";
    public $before_exist = false;
    public $table_db_name = "";

    protected $db_base = null;

    public function __construct($biz_name, $check_db = true)
    {
        // 创建对应的数据库
        $db_type = self::getAdapterDriver();
        if ($db_type == "sqlite" || $db_type == "mysql" || $db_type == "pgsql") {
//                var_dump(dirname(__DIR__));
            include_once dirname(__DIR__) . '/sql/sql.inteface.php';
            include_once dirname(__DIR__) . '/sql/' . $db_type . '.class.php';
        } else {
            echo '<h1>你的数据库不支持，请联系作者</h1>' . $db_type;
            return null;
        }
        $this->db_base = new HandsomeSQL("handsome" . $biz_name, $db_type, $check_db);

        // 语法糖，给上层业务使用
        $this->db = $this->db_base->db;
        $this->db_name = $this->db_base->db_name;
        $this->table_db_name = "table.".$this->db_name;
        $this->before_exist = $this->db_base->before_exist;

        // 如果刚刚创建了数据库，则增加一条测试数据测试
        if (!$this->before_exist) {
            $this->check();
        }
    }

    public static function getAdapterDriver()
    {
        $installDb = Typecho_Db::get();
        $type = explode('_', $installDb->getAdapterName());
        $type = array_pop($type);
        $type = strtolower($type);
        return ($type == "mysqli") ? "mysql" : $type;
    }

    public abstract function check();


}
