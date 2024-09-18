<?php

include_once dirname(__DIR__) . '/dao/sql.base.php';

class HandsomeTag extends SqlBase
{
    public function __construct($check_db = true)
    {
        parent::__construct("tag", $check_db);
    }


    public function get($name)
    {
        $rs = $this->db->fetchRow($this->db->select('tid')->from('table.' . $this->db_name)->where('name = ?', $name));
        if ($rs == null || count($rs) == 0) {
            return false;
        } else {
            return $rs['tid'];
        }
    }

    /**
     * 添加
     * @param $tid
     * @return void
     */
    public function set_comment_count($tid,$count){
        $this->db->query($this->db->update($this->table_db_name)->rows(array('count' => $count))
            ->where('tid = ?', $tid));
    }

    public function get_list(){
        $select = $this->db->select('tid','name','count')->from('table.' . $this->db_name);
        return $this->db->fetchAll($select);
    }

    public function add($name)
    {
        if ($name) {
            return $this->db->query($this->db->insert('table.' . $this->db_name)->rows(array(
                'name' => $name
            )));
        } else {
            return -1;
        }
    }

    public function delete($tid){
        if ($tid>=0){
            $this->db->query($this->db->delete($this->table_db_name)->where('tid = ?', $tid));
        }
    }

    public function edit($tid,$name){
        if ($tid>=0 && $name){
            $this->db->query($this->db->update($this->table_db_name)->rows(array('name' => $name))
                ->where('tid = ?', $tid));        }
    }


    public function check()
    {
        return $this->db_base->is_exist_table();
    }
}
