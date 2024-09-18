<?php

class HandsomeRelationships extends SqlBase
{
    public function __construct($check_db = true)
    {
        parent::__construct("relationships", $check_db);
    }


    public function get($tid)
    {
        $select = $this->db->select()->from('table.' . $this->db_name)->where("tid=?",$tid);
        return $this->db->fetchAll($select);
    }

    public function add($tid, $coid)
    {
        return $this->db->query($this->db->insert('table.' . $this->db_name)->rows(array(
            'tid' => $tid,
            'coid' => $coid
        )));
    }

    public function delete_by_tid($tid){
        $this->db->query($this->db->delete($this->table_db_name)->where('tid = ?', $tid));
    }

    public function delete_by_coid($coid){
        $this->db->query($this->db->delete($this->table_db_name)->where('coid = ?', $coid));
    }

    public function delete($tid,$coid){
        $this->db->query($this->db->delete($this->table_db_name)->where('coid = ? and tid = ?', $coid,$tid));
    }

    public function get_comment_count($tid)
    {
        return $this->db->fetchObject($this->db->select(array('COUNT(coid)' => 'num'))->from($this->table_db_name)
            ->where('tid = ?', $tid))->num;
    }

    public function check()
    {
        return $this->db_base->is_exist_table();
    }
}
