<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

//error_reporting(E_ALL);
//ini_set("display_errors", 1);

require_once(dirname(__DIR__).'/cache/driver/controller/cache_util.php');
require_once(dirname(__DIR__).'/cache/driver/controller/tag_util.php');

/**
 * 因为后台修改comment的接口类Widget_Comments_Edit  的edit钩子，没有把当前评论的coid传进去
 * 导致无法删除当前评论的tag关系，同时这个接口只传入了修改后的text，没有修改前的评论内容，
 * 导致无法差异化比较评论前后的标签关系变化
 */
class CrossAction extends Widget_Comments_Edit implements Widget_Interface_Do{

    private $old_tag_list = [];
    private $new_tag_list = [];


    public function action(){
        $this->user->pass('contributor');
        $this->security->protect();

        if (Typecho_Widget::widget('Widget_User')->hasLogin()){
            $this->on($this->request->is('do=edit&coid'))->editCross();
            $this->on($this->request->is('do=delete'))->deleteCross();
        }
    }

    // [tag id=xx]xx[/tag] 文本
    private function getScodeTagList($text){
        $tag_list = [];
        $pattern = CommonContent::get_shortcode_regex(array('tag'));
        preg_match_all("/$pattern/",$text,$matches_list);
        for ($i = 0; $i < count($matches_list[0]); $i++) {
            $attr = htmlspecialchars_decode($matches_list[3][$i]);//还原转义前的参数列表
            $attrs = CommonContent::shortcode_parse_atts($attr);//获取短代码的参数
            $tid = @$attrs["id"];
            if ($tid){
                $tag_list[] = $tid;
            }
        }
        return $tag_list;
    }

    // #tag1 xxx 文本
    private function getHashTagList($text){

        return preg_replace_callback("/(?:^|\B)#(.+?) /", function ($matches) {
            // $matches[0] 完整匹配内容
            // $matches[1] 分组1的内容
            $name = $matches[1];
            $tag = new TagUtil(false);
            $ret = $tag->getOrCreateTag($name);
            $tid = $ret["tid"];
            $this->new_tag_list[] = $tid;
            return "[tag id='$tid']" . $name . "[/tag] ";
        }, $text);
    }

    private function editCross(){
        $coid = $this->request->filter('int')->coid;
        $commentSelect = $this->db->fetchRow($this->select()
            ->where('coid = ?', $coid)->limit(1), array($this, 'push'));


        if ($commentSelect && $this->commentIsWriteable()) {
            $oldComment = $commentSelect["text"];
            $request_url = $this->request->filter('url')->request_url;
            $GLOBALS["request_url"] = $request_url;
            $newComment = $this->request->text;
            //
            $this->old_tag_list = $this->getScodeTagList($oldComment);
            $this->new_tag_list = $this->getScodeTagList($newComment);
            $newComment = $this->getHashTagList($newComment);

//            var_dump($this->old_tag_list);
//            var_dump($this->new_tag_list);


            // 3. 操作tag operation 表
            $tag = new TagUtil(false);
            // 3.1 先求两个集合的交集，表示已经存在数据库里面了
            $intersection = array_intersect($this->old_tag_list, $this->new_tag_list);
            $need_delete = array_diff($this->old_tag_list,$intersection);
            $need_add = array_diff($this->new_tag_list,$intersection);

//            var_dump($intersection);
//            var_dump($need_delete);
//            var_dump($need_add);

            foreach ($need_delete as $delete_tid){
                $tag->unbindTagWithComment($delete_tid, $coid);
                $tag->updateTagCommentCount($delete_tid);
            }

            foreach ($need_add as $add_tid){
                $tag->bindTagWithComment($add_tid, $coid);
                $tag->updateTagCommentCount($add_tid);
            }

            //只修改内容，不修改其他的比如用户名的信息
            $comment['text'] = $newComment;
            $comment['author'] = $commentSelect["author"];
            $comment['mail'] = $commentSelect["mail"];
            $comment['url'] = $commentSelect["url"];

            /** 评论插件接口 */
            $this->pluginHandle()->edit($comment, $this);

            /** 更新评论 */
            $this->update($comment, $this->db->sql()->where('coid = ?', $coid));

            $updatedComment = $this->db->fetchRow($this->select()
                ->where('coid = ?', $coid)->limit(1), array($this, 'push'));

            $updatedComment['content'] = CommentContent::postCommentContent(ScodeContent::parseContentPublic($this->content),true,"", "", "", true);

//            $this->response->goBack();

            $this->response->throwJson(array(
                'success'   => 1,
                'comment'   => $updatedComment
            ));
        }

        $this->response->throwJson(array(
            'success'   => 0,
            'message'   => _t('修评论失败')
        ));

    }


    private function deleteCross(){
        $comments = $this->request->filter('int')->getArray('coid');
        $deleteRows = 0;
        $tag = new TagUtil(false);

        foreach ($comments as $coid) {

            $comment = $this->db->fetchRow($this->select()
                ->where('coid = ?', $coid)->limit(1), array($this, 'push'));

            if ($comment && $this->commentIsWriteable()) {
                // 删除某个评论的所有tag 关系
                $tag->deleteRelationByCoid($coid);


                $this->pluginHandle()->delete($comment, $this);

                /** 删除评论 */
                $this->db->query($this->db->delete('table.comments')->where('coid = ?', $coid));

                /** 更新相关内容的评论数 */
                if ('approved' == $comment['status']) {
                    $this->db->query($this->db->update('table.contents')
                        ->expression('commentsNum', 'commentsNum - 1')->where('cid = ?', $comment['cid']));
                }

                $this->pluginHandle()->finishDelete($comment, $this);

                $deleteRows ++;
            }
        }
        // 删除操作之后，自动的更新一下所有tag的数目
        $tag->refreshTagCount();
        $this->response->goBack();
    }
}
