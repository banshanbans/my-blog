<?php

/**
 * cache 对外再封装一层，这样好处是即使cache不工作，仍然能获取到最新的数据
 */
class CacheUtil
{
    public $cache = null;
    private $type = null;

    public static $comment_key = "comment";
    public static $search_key = "search";
    public static $not_expired_time = 1577880000; #过期时间设置为50年


    public function __construct($type = null, $check_db = true)
    {
        $this->type = $type;
        include_once dirname(__DIR__) . '/dao/cache.php';
        $this->cache = new HandsomeCache($check_db);
    }


    public function cacheWrite($key, $value, $time, $type = null, $isAppend = false, $isUnique = false)
    {
        if (!isset($this->cache)) {
            return;
        }
        if ($isAppend) {//追加内容
            $value = $this->cacheRead($key) . $value;
        }
        if ($isUnique) {//如果key是独一的，则先清空对应的key 的数据，再插入
            $this->cacheClearByKey($key);
        }
        if ($type == null) {
            $this->cache->set($key, $value, $this->type, $time);
        } else {
            $this->cache->set($key, $value, $type, $time);
        }
    }

    public function cacheRead($k)
    {
        if (!isset($this->cache)) {
            return false;
        }
        return $this->cache->get($k);
    }


    public function cacheClear($type = null)
    {
        if (!isset($this->cache)) {
            return;
        }
        if ($type == null) {
            $this->cache->flush($this->type);
        } else {
            $this->cache->flush($type);
        }
    }

    public function cacheClearByKey($key)
    {
        if (!isset($this->cache)) {
            return;
        }
        $this->cache->flush_by_key($key);
    }


}


?>
