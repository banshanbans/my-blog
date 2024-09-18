<?php

class TagUtil
{

    private $tag;
    private $relationships;

    public function __construct($check_db = true)
    {
        include_once dirname(__DIR__) . '/dao/tag.php';
        include_once dirname(__DIR__) . '/dao/relationships.php';

        $this->tag = new HandsomeTag($check_db);
        $this->relationships = new HandsomeRelationships($check_db);
    }

    public function getOrCreateTag($name)
    {
        $_tag = $this->tag->get($name);
        if ($_tag) {
            return array("tid" => $_tag, "new" => true);
        } else {
            return array("tid" => $this->tag->add($name), "new" => false);
        }
    }

    public function refreshTagCount(){
        $tag_list = $this->getList();
        foreach ($tag_list as $tag){
            $this->updateTagCommentCount($tag['tid']);
        }
    }

    public function getList(){
        return $this->tag->get_list();
    }

    public function bindTagWithComment($tid, $coid)
    {
        return $this->relationships->add($tid, $coid);
    }

    //删除一条relationship
    public function unbindTagWithComment($tid,$coid){
        $this->relationships->delete($tid,$coid);
    }

    // 删除与该评论相关的tag_relationship
    public function deleteRelationByCoid($coid){
        $this->relationships->delete_by_coid($coid);
    }


    public function updateTagCommentCount($tid){
        $this->tag->set_comment_count($tid, $this->relationships->get_comment_count($tid));
    }

    public function deleteTag($tid){
        $this->tag->delete($tid);
        $this->relationships->delete_by_tid($tid);
    }

    public function editTag($tid,$name){
        // 查看是否存在该名称的tag
        $target_tid = $this->tag->get($name);
        if (!$target_tid){
            $this->tag->edit($tid,$name);
        }else{
            if ($target_tid == $tid){
                return;
            }
            // 合并两个tag
            $relation_list = $this->relationships->get($tid);
            foreach ($relation_list as $relation){
                // 修改$relation["coid"]的评论，把$tid改成对应的$target_tid
                $db = Typecho_Db::get();
                $commentSelect = $db->fetchRow($db->select("text")->from ('table.comments')
                    ->where('coid = ?', $relation["coid"])->limit(1));
                $text = @$commentSelect["text"];
                var_dump($text);
                $pattern = CommonContent::get_shortcode_regex(array('tag'));
                $text = preg_replace_callback("/$pattern/", function ($matches) use ($tid,$name,$target_tid) {
                    // $matches[0] 完整匹配内容
                    $attr = htmlspecialchars_decode($matches[3]);//还原转义前的参数列表
                    $attrs = CommonContent::shortcode_parse_atts($attr);//获取短代码的参数
                    $text_tid = @$attrs["id"];
                    if ($text_tid == $tid){
                        return "[tag id='$target_tid']" . $name . "[/tag] ";
                    }else{
                        return  $matches[0];
                    }
                }, $text);
                /** 更新评论内容 */
                $db->query($db->update('table.comments')->rows(array('text' => $text))->where('coid = ?', $relation["coid"]));
                try {
                    // 可能出现commentA 同时和tagA和tagB 关联，此时，如果tagA和tagB 合并的话，会显示两个标签，这个是可以接受的
                    // 此时另一条会绑定失败，这个是正常的
                    $this->bindTagWithComment($target_tid,$relation["coid"]);
                }catch (Exception $e){
                }
            }
            //删掉原始的tid
            $this->deleteTag($tid);
//            更新目标tid
            $this->updateTagCommentCount($target_tid);
        }

    }


    public function check()
    {
        return $this->tag->check() && $this->relationships->check();
    }


}
