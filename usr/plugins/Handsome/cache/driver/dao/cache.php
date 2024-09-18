<?php
include_once __DIR__ . '/sql.base.php';

class HandsomeCache extends SqlBase
{
    public function __construct($check_db = true)
    {
        parent::__construct("cache", $check_db);
        if ($this->before_exist) {
            // 删除过期的记录
            $this->db->query($this->db->delete('table.' . $this->db_name)->where('time <= ?', time()));
        }
    }

    public function set($key, $value, $type, $expire = 86400)
    {
        $this->db->query($this->db->insert('table.' . $this->db_name)->rows(array(
            'key' => md5($key),
            'data' => $value,
            'time' => time() + $expire,
            'type' => $type
        )));
    }

    public function get($key)
    {
        $rs = $this->db->fetchRow($this->db->select('data')->from('table.' . $this->db_name)->where('key = ?', md5($key)));
        if ($rs == null || count($rs) == 0) {
            return false;
        } else {
            return $rs['data'];
        }
    }

    public function flush($type = null)
    {
        return $this->db->query($this->db->delete('table.' . $this->db_name)->where('type = ?', $type));
    }

    public function flush_by_key($key)
    {
        return $this->db->query($this->db->delete('table.' . $this->db_name)->where('key = ?', md5($key)));
    }

    public function check()
    {
        $number = uniqid();
        $this->set('check', $number, "", 60);
        $cache = $this->get('check');
        if ($number != $cache) {
            throw new Exception('Cache Test Fall!');
        }
    }
}
