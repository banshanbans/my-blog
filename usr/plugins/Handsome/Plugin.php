<?php
/**
 * <strong style="color:red;">handsomePro 唯一配套插件</strong>
 *
 * @package Handsome
 * @author hewro,hanny
 * @version 9.2.1
 * @dependence 1.0-*
 * @link https://www.ihewro.com
 *
 */

error_reporting(0);
ini_set('display_errors', 0);

//如果需要显示php错误打开这两行注释，问题修复后必须关闭！
//error_reporting(E_ALL);
//ini_set("display_errors", 1);

use Typecho\Request;
use Typecho\Widget\Helper\Form\Element;
use Typecho\Widget\Helper\Layout;


require_once("libs/Tool.php");
require_once('cache/driver/controller/cache_util.php');
require_once('cache/driver/controller/tag_util.php');


// 引入theme 文件夹的文件
$theme_root = dirname(dirname(__DIR__)) . "/themes/handsome/";
require_once($theme_root . "libs/Options.php");

require_once($theme_root . "libs/CDN.php");
require_once($theme_root . "libs/Handsome.php");
require_once($theme_root . "libs/Utils.php");
require_once($theme_root . "libs/content/CommentContent.php");
require_once($theme_root . "libs/content/PostContent.php");

//1. 设置语言
require_once($theme_root . "libs/I18n.php");
require_once($theme_root . "libs/Lang.php");

function isOldTy()
{
    return !defined('__TYPECHO_CLASS_ALIASES__');
}

$prefix = isOldTy() ? "old/" : "";
require_once("admin/" . $prefix . "Title.php");


class Handsome_Plugin implements Typecho_Plugin_Interface
{
    public static $is_post_cross_comment = false;
    public static $need_bind_cross_tid_list = [];

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return string
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        // todo 判断handsome 主题有没有启用
        $options = mget();
        I18n::loadAsSettingsPage(true);
        I18n::setLang($options->admin_language);


        //vEditor
        Typecho_Plugin::factory('admin/write-post.php')->richEditor = array('Handsome_Plugin', 'VEditor');
        Typecho_Plugin::factory('admin/write-page.php')->richEditor = array('Handsome_Plugin', 'VEditor');


        //友情链接
        $info = "插件启用成功</br>";
        $info .= Handsome_Plugin::linksInstall() . "</br>";
        $info .= Handsome_Plugin::cacheInstall() . "</br>";
        $tag = new TagUtil();
        if ($tag->check()) {
            $info .= "tag表启用成功</br>";
        } else {
            $info .= "tag表启用失败</br>";
        }

        Helper::addPanel(3, 'Handsome/manage-links.php', '友情链接', _mt('管理友情链接'), 'administrator');
        Helper::addAction('links-edit', 'Handsome_Action');
        Helper::addAction('multi-upload', 'Handsome_Action');
        Helper::addAction('handsome-meting-api', 'Handsome_Action');
        Helper::addAction('cross-edit', 'Handsome_Action');


        //过滤私密评论
        Typecho_Plugin::factory('Widget_Abstract_Contents')->excerptEx = array('Handsome_Plugin', 'exceptFeedForDesc');
        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = array('Handsome_Plugin', 'exceptFeed');
        Typecho_Plugin::factory('Widget_Abstract_Comments')->contentEx = array('Handsome_Plugin', 'parse');


        //markdown 引擎
//        Typecho_Plugin::factory('Widget_Abstract_Contents')->content = array('Handsome_Plugin', 'content');


        //置顶功能
        Typecho_Plugin::factory('Widget_Archive')->indexHandle = array('Handsome_Plugin', 'sticky');
        //分类过滤，默认过滤相册
        //首页列表的过滤器
        Typecho_Plugin::factory('Widget_Archive')->indexHandle = array('Handsome_Plugin', 'CateFilter');
        //某个分类页面的过滤器
        Typecho_Plugin::factory('Widget_Archive')->categoryHandle = array('Handsome_Plugin', 'CategoryCateFilter');


        Typecho_Plugin::factory('Widget_Archive')->footer = array('Handsome_Plugin', 'footer');

        // 注册文章、页面保存时的 hook（JSON 写入数据库）
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('Handsome_Plugin', 'buildSearchIndex');
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishDelete = array('Handsome_Plugin', 'buildSearchIndex');
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->finishPublish = array('Handsome_Plugin', 'buildSearchIndex');
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->finishDelete = array('Handsome_Plugin', 'buildSearchIndex');

        // 评论发布前，时光机评论禁止游客发布说说
        Typecho_Plugin::factory('Widget_Feedback')->comment = array('Handsome_Plugin', 'filter');
        Typecho_Plugin::factory('Widget_Comments_Edit')->comment = array('Handsome_Plugin', 'filter');

        //添加评论成功后的回调接口
        Typecho_Plugin::factory('Widget_Abstract_Comments')->filter = array('Handsome_Plugin', 'parseComment');
        Typecho_Plugin::factory('Widget_Feedback')->finishComment = array('Handsome_Plugin', 'finishComment');
        Typecho_Plugin::factory('Widget_Comments_Edit')->finishComment = array('Handsome_Plugin', 'finishComment');


//        TODO：评论的异步接口，对于低版本typecho不兼容，后续可以增加判断
//        Typecho_Plugin::factory('Widget_Feedback')->finishComment = array('Mailer_Plugin', 'sendComment');
//        Typecho_Plugin::factory('Widget_Service')->parseComment = array('Mailer_Plugin', 'parseComment');

        self::buildSearchIndex();
//        $info .= "首次启动，需要在插件设置里面更新搜索索引</br>";

        return _t($info);
    }


    public static function sendComment($comment)
    {
        Helper::requestService('parseComment', $comment);
    }


    public static function finishComment($post)
    {
        if ($post->authorId !== "0") {//是登录用户，authorid 是该条评论的登录用户的id
            $cache = new CacheUtil(null, false);
            $cache->cacheWrite("comment", date("Y-m-d"), CacheUtil::$not_expired_time, "comment", true, true);
        }
    }

    public static function parseComment($comment, $post)
    {
        if (self::$is_post_cross_comment) {
            // 从这里为了拿到发表评论后的coid
            self::$is_post_cross_comment = false;
            $coid = $comment["coid"];
            $tag = new TagUtil(false);
            if (count(self::$need_bind_cross_tid_list) > 0) {
                foreach (self::$need_bind_cross_tid_list as $tid) {
                    $tag->bindTagWithComment($tid, $coid);
                    $tag->updateTagCommentCount($tid);
                }
            }
            self::$need_bind_cross_tid_list = [];
        }
        return $comment;
    }

    public static function filter($comment, $post)
    {
        $is_cross = $post->slug === "cross";
        if (!$post->slug) {// 从管理后台进入的接口
            // 查询当前cid对应的slug 是否是cross
            $db = Typecho_Db::get();
            $slug = $db->fetchRow($db->select('slug')->from('table.contents')
                ->where('cid = ?', $comment["cid"])->limit(1));
            $is_cross = $slug['slug'] === "cross";
        }
        if ($is_cross) {
            if (!$comment["authorId"] && !$comment["parent"]) {//不是登录用户，而且发表的是说说，这需要拦截
                throw new Typecho_Widget_Exception("你没有权限发表说说");
            } else {
                self::$is_post_cross_comment = true;
                self::$need_bind_cross_tid_list = [];
                // 解析内容中的标签
                $comment["text"] = preg_replace_callback("/(?:^|\B)#(.+?) /", function ($matches) {
                    // $matches[0] 完整匹配内容
                    // $matches[1] 分组1的内容
                    //如果没有登录，不允许添加标签
                    if (!Typecho_Widget::widget('Widget_User')->hasLogin()){
                        return "";
                    }
                    $name = $matches[1];
                    $tag = new TagUtil(false);
                    $ret = $tag->getOrCreateTag($name);
                    $tid = $ret["tid"];
                    self::$need_bind_cross_tid_list[] = $tid;
                    return "[tag id='$tid']" . $name . "[/tag] ";
                }, $comment["text"]);
                // 去重
                self::$need_bind_cross_tid_list = array_unique(self::$need_bind_cross_tid_list);
            }
        }

        return $comment;
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        Helper::removeAction('links-edit');
        Helper::removeAction('multi-upload');
        Helper::removeAction('handsome-meting-api');

        Helper::removePanel(3, 'Links/manage-links.php');
        Helper::removePanel(3, 'Handsome/manage-links.php');
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {

        require 'Device.php';
        Utils::initGlobalDefine(true);

        // todo 判断handsome 主题有没有启用
        $options = mget();
        I18n::loadAsSettingsPage(true);
        I18n::setLang($options->admin_language);

        if (isset($_GET['action']) && $_GET['action'] == 'clearMusicCache') {
            self::clearMusicCache();
        }

        if (isset($_GET['action']) && $_GET['action'] == 'clearDoubanCache') {
            self::clearDoubanCache();
        }

        if (isset($_GET['action']) && $_GET['action'] == 'refreshTagData') {
            self::refreshTagData();
        }

        if (isset($_GET['action']) && $_GET['action'] == 'buildSearchIndex') {
            self::buildSearchIndex();
        }


        if (isset($_GET['action']) && $_GET['action'] == 'moveToRoot') {
            self::moveToRoot();
        }

        $form->addInput(new Title_Plugin('btnTitle', NULL, NULL, _t('致谢'), NULL));


        $thanks = new Typecho_Widget_Helper_Form_Element_Select("thanks", array(
            1 => "友情链接功能由<a href='http://www.imhan.com'>hanny</a>开发，感谢！",
            2 => "主题播放器基于Aplayer项目并集成了APlayer-Typecho插件，感谢！"
        ), "1", "插件致谢", "<strong style='color: red'> 【友情链接】请在typecho的后台-管理-友情链接 设置</strong>");
        $form->addInput($thanks);

        $form->addInput(new Title_Plugin('btnTitle', NULL, NULL, _mt('文章设置'), NULL));


        $sticky_cids = new Typecho_Widget_Helper_Form_Element_Text(
            'sticky_cids', NULL, '',
            '置顶文章的 cid', '按照排序输入, 请以半角逗号或空格分隔 cid.</br><strong style=\'color: red\'>cid查看方式：</strong>后台的文章管理中，进入具体的文章编辑页面，地址栏中会有该数字。如<code>http://localhost/build/admin/write-post.php?cid=120</code>表示该篇文章的cid为120');
        $form->addInput($sticky_cids);

        $form->addInput(new Title_Plugin('btnTitle', NULL, NULL, _t('分类设置'), NULL));


        $CateId = new Typecho_Widget_Helper_Form_Element_Text('CateId', NULL, '', _t('首页不显示的分类的mid'), _t('多个请用英文逗号,隔开</br><strong style="color: red">mid查看方式：</strong> 在分类管理页面点击分类，地址栏中会有该数字，比如<code>http://localhost/build
/admin/category.php?mid=2</code> 表示该分类的mid为2</br><strong style="color: rgba(255,0,18,1)">默认不过滤相册分类，请自行过滤</strong></br> <b style="color:red">说明：填写该设置后，是指该分类的文章不在首页文章页面中显示，如果希望实现侧边栏不显示某个分类，可以查看<a target="_blank" href="https://auth.ihewro.com/user/docs/#/preference/hide">使用文档——内容隐藏</a>中说明</b>'));
        $form->addInput($CateId);

        $LockId = new Typecho_Widget_Helper_Form_Element_Text('LockId', NULL, '', _t('加密分类mid'), _t('多个请用英文逗号隔开</br><strong style="color: red">mid查看方式：</strong> 在分类管理页面点击分类，地址栏中会有该数字，比如<code>http://localhost/build
/admin/category.php?mid=2</code> 表示该分类的mid为2</br><strong style="color: rgba(255,0,18,1)">加密分类的密码需要在分类描述按照指定格式填写<a 
href="https://auth.ihewro.com/user/docs/#/preference/lock" target="_blank">使用文档</a></strong></br><strong style="color: rgba(255,0,18,1)">加密分类仍然会在首页显示标题列表，但不会显示具体内容，也不会出现在rss地址中</strong>'));
        $form->addInput($LockId);

        $form->addInput(new Title_Plugin('btnTitle', NULL, NULL, _t('搜索设置'), NULL));

        $queryBtn = new Typecho_Widget_Helper_Form_Element_Submit();
        $queryBtn->value(_t('构建文章索引'));
        self::renderHtml();
        $queryBtn->description(_t('通常不需要手动构建，在发布、修改文章的时候会自动构建新的索引。但是如果发现搜索数据不对，请手动点击此按钮构建'));
        $queryBtn->input->setAttribute('class', 'btn btn-s btn-warn btn-operate');
        $queryBtn->input->setAttribute('formaction', Typecho_Common::url('/options-plugin.php?config=Handsome&action=buildSearchIndex', Helper::options()->adminUrl));
        $form->addItem($queryBtn);
        $cacheWhen = new Typecho_Widget_Helper_Form_Element_Radio('cacheWhen',
            array(
                'true' => '文章保存同时更新搜索索引',
                'false' => '不实时更新索引，适用于网站的文章特别多，此时需要手动更新索引',
            ), 'true', _t('实时更新索引'), _t('网站文章特别多（超过1000篇）的时候，请关闭实时更新索引，否则保存文章时候花费时间较长可能会显示超时错误'));
        $form->addInput($cacheWhen);


        $form->addInput(new Title_Plugin('btnTitle', NULL, NULL, _t('编辑器设置'), NULL));


        $editorChoice = new Typecho_Widget_Helper_Form_Element_Radio('editorChoice',
            array(
                'origin' => '使用typecho自带的markdown编辑器',
                'vditor' => '使用vditor编辑器 <a href="https://auth.ihewro.com/user/docs/#/preference/vditor" target="_blank">vditor使用介绍</a>',
                'other' => '使用其他第三方编辑器'
            ), 'origin', _t('<b style="color: red">后台</b>文章编辑器选择'), _t('可根据个人喜好选择'));
        $form->addInput($editorChoice);


        $vditorMode = new Typecho_Widget_Helper_Form_Element_Radio('vditorMode',
            array(
                'wysiwyg' => '所见即所得',
                'ir' => '即时渲染',
                'sv' => '源码模式（和typecho默认的编辑器几乎一致）',
                'sv_both' => '源码模式+分屏预览所见即所得',
            ), 'ir', _t('vditor默认模式选择'), _t('
                所见即所得（WYSIWYG对不熟悉 Markdown 的用户较为友好，熟悉 Markdown 的话也可以无缝使用。<a href="https://s1.ax1x.com/2020/08/03/aajX0e.gif" target="_blank">演示效果</a>  </br>
                即时渲染模式对熟悉 Typora 的用户应该不会感到陌生，理论上这是最优雅的 Markdown 编辑方式。<a href="https://s1.ax1x.com/2020/08/03/aajxkd.gif" target="_blank">演示效果</a> </br>       
                传统的分屏预览模式适合大屏下的 Markdown 编辑。<a href="https://s1.ax1x.com/2020/08/03/aajfw4.gif" target="_blank">演示效果</a>     
            '));
        $form->addInput($vditorMode);

        $parseWay = new Typecho_Widget_Helper_Form_Element_Radio('parseWay',
            array(
                'origin' => '使用typecho自带的markdown解析器',
                'vditor' => '前台引入vditor.js接管前台解析',
            ), 'origin', _t('<b style="color: red">前台</b>Markdown解析方式选择'), _t('1.选择typecho自带解析器，即和typecho默认的解析器一致，可以在基础上使用第三方markdown解析器，主题在此基础上内置了mathjax和代码高亮，需要在主题增强功能里面开启</br>2.选择vditor前台解析，可以与后台编辑器得到相同的解析效果，支持后台编辑器的所有语法，<b style="color: red">但是对于有些插件兼容性不好，并且不支持ie浏览器（在ie11 浏览器中会自动切换到typecho原生解析方式）</b></br>'));
        $form->addInput($parseWay);

        $urlUpload = new Typecho_Widget_Helper_Form_Element_Radio('urlUpload',
            array(
                'true' => '开启外链上传',
                'false' => '关闭外链上传',
            ), 'false', _t('vditor开启外链上传'), _t('开启此功能后，复制粘贴的文本到编辑器中，如果文本中包含了外链的图片地址，会自动上传到自己服务器中，<b style="color:red;">仅当后台编辑器选择vditor编辑器有效</b>'));
        $form->addInput($urlUpload);

        $vditorCompleted = new Typecho_Widget_Helper_Form_Element_Textarea('vditorCompleted', NULL, "",
            _t('vditor.js 解析结束回调函数'), _t('如果前台选择了 vditor.js 解析，有一些JavaScript代码可能需要在vditor.js 解析文章内容后再对文章内容进行操作，可以填写再这里</br> 如果不明白这项，请清空'));
        $form->addInput($vditorCompleted);


        $form->addInput(new Title_Plugin('btnTitle', NULL, NULL, _t('客户端静态资源缓存设置'), NULL));
        $cacheSetting = new Typecho_Widget_Helper_Form_Element_Radio('cacheSetting',
            array(
                'yes' => '是，(该功能需要https) 使用离线缓存，缓存与主题相关的静态资源。',
                'no' => '否，缓存特性插件不进行额外接管，由浏览器和自己使用的CDN进行控制',
            ), 'no', _t('使用本地离线缓存功能'), _t('使用本地缓存主题相关的静态资源后，加载速度能够得到明显的提升，主题目录下面的assets 文件夹会进行本地缓存（使用service worker 实现）,</br> 在「版本更新」和「使用强制刷新、清除缓存」的情况下才会更新这些资源'));
        $form->addInput($cacheSetting);


        $queryBtn = new Typecho_Widget_Helper_Form_Element_Submit();
        $queryBtn->value(_t('更新离线缓存'));
        $queryBtn->description(_t('<b>首次使用离线缓存，请先在「使用本地离线缓存功能」设置中选择是，保存插件设置，最后再点击该按钮。</b></br><b style="color:red;">后续如果主题目录下面的 assets 文件夹内容有修改，需要点击该按钮，并且再次访问首页才会更新缓存</b>'));
        $queryBtn->input->setAttribute('class', 'btn btn-s btn-warn btn-operate');
        $queryBtn->input->setAttribute('formaction', Typecho_Common::url('/options-plugin.php?config=Handsome&action=moveToRoot', Helper::options()->adminUrl));
        $form->addItem($queryBtn);

//
        $form->addInput(new Title_Plugin('handsome_aplayer', NULL, NULL, _t('数据库缓存设置'), NULL));

        $queryBtn = new Typecho_Widget_Helper_Form_Element_Submit();
        $queryBtn->value(_t('清除音乐播放器缓存'));
        $queryBtn->description(_t('播放器缓存有效期为一天，一天后会自动清空。一般无需执行该按钮。如果在有效期内变更了歌单内容则需要清空缓存。</br>
<b>音乐解析地址默认为：</b><code>' . Typecho_Common::url('action/handsome-meting-api?server=:server&type=:type&id=:id&auth=:auth&r=:r', Helper::options()->index) . '</code></br>可以在外观设置的开发者设置里面修改'));
        $queryBtn->input->setAttribute('class', 'btn primary');
        $queryBtn->input->setAttribute('formaction', Typecho_Common::url('/options-plugin.php?config=Handsome&action=clearMusicCache', Helper::options()->adminUrl));
        $form->addItem($queryBtn);


        $queryBtn = new Typecho_Widget_Helper_Form_Element_Submit();
        $queryBtn->value(_t('刷新时光机页面标签对应的评论数目'));
        $queryBtn->description("正常情况下不需要手动刷新该数目，但可能在某些预期之外场景下数目出错，可以手动点击修复该问题");
        $queryBtn->input->setAttribute('class', 'btn primary');
        $queryBtn->input->setAttribute('formaction', Typecho_Common::url('/options-plugin.php?config=Handsome&action=refreshTagData', Helper::options()->adminUrl));
        $form->addItem($queryBtn);


        $queryBtn = new Typecho_Widget_Helper_Form_Element_Submit();
        $queryBtn->value(_t('清除豆瓣清单独立页面的缓存'));
        $queryBtn->description("豆瓣清单的独立页面缓存每三天自动更新一次，如果在这个时间周期内，豆瓣数据有更新，可以手动清空缓存立即更新。（请勿频繁清空该数据）");
        $queryBtn->input->setAttribute('class', 'btn primary');
        $queryBtn->input->setAttribute('formaction', Typecho_Common::url('/options-plugin.php?config=Handsome&action=clearDoubanCache', Helper::options()->adminUrl));
        $form->addItem($queryBtn);


        $form->addInput(new Title_Plugin('handsome_aplayer', NULL, NULL, _t('播放器设置'), NULL));


        //加盐的内容
        $t = new Typecho_Widget_Helper_Form_Element_Text(
            'salt',
            null,
            Typecho_Common::randString(32),
            _t('接口保护'),
            _t('加盐保护 API 接口不被滥用，自动生成无需设置，也可以自行填写任意值（相当于密码），如果为空表示接口不进行校验')
        );
        $form->addInput($t);

        //cookie 填写
        $t = new Typecho_Widget_Helper_Form_Element_Textarea(
            'cookie',
            null,
            '',
            _t('网易云音乐 Cookie（修改后需要清空播放器缓存才生效），为空则使用主题内置cookie'),
            _t('如果您是网易云音乐的会员，可以将您的 cookie 填入此处来获取云盘等付费资源，听歌将不会计入下载次数。<br><b>使用方法见 <a target="_blank" href="https://auth.ihewro.com/user/docs/#/preference/player?id=%e7%bd%91%e6%98%93%e4%ba%91-cookie-%e8%ae%be%e7%bd%ae">网易云 cookie 获取方法</a></b>')
        );
        $form->addInput($t);

        //qq 音乐cookie 填写
        $t = new Typecho_Widget_Helper_Form_Element_Textarea(
            'qq_cookie',
            null,
            '',
            _t('QQ音乐 Cookie（修改后需要清空播放器缓存才生效），为空则使用主题内置cookie'),
            _t('如果您是QQ音乐的会员，可以将您的 cookie 填入此处来获取云盘等付费资源，听歌将不会计入下载次数。<br><b>使用方法见 <a target="_blank" href="https://auth.ihewro.com/user/docs/#/preference/player?id=qq%e9%9f%b3%e4%b9%90%e7%9a%84cookie%e8%ae%be%e7%bd%ae">QQ音乐 cookie 获取方法</a></b>')
        );
        $form->addInput($t);

    }


    public static function movetoRoot()
    {
        //将主题目录下面的sw.js 移动到typecho根目录，以便进行离线缓存
        $options = Helper::options();
        $sourcefile = __TYPECHO_ROOT_DIR__ . "/usr/themes/handsome/assets/js/sw.min.js";
        $dir = __TYPECHO_ROOT_DIR__;
        $filename = "/sw.min.js";
        if (!file_exists($sourcefile)) {
            Typecho_Widget::widget('Widget_Notice')->set(_t("开启本地离线缓存失败1"), 'error');
        }

        $origin_content = file_get_contents($sourcefile);
        $replace = str_replace("[VERSION_TAG]", uniqid(), $origin_content);
        $replace = str_replace("[BLOG_URL]", $options->rootUrl, $replace);
        $replace = str_replace("[CDN_ADD]", trim(Utils::getCDNAdd(1)[0]), $replace);

        if (copy($sourcefile, $dir . '' . $filename)) {
            //将文件的内容修改
            if (file_put_contents($dir . $filename, $replace)) {
                //将文件的内容修改
                Typecho_Widget::widget('Widget_Notice')->set(_t("更新本地离线缓存成功"), 'success');
            } else {
                Typecho_Widget::widget('Widget_Notice')->set(_t("更新本地离线缓存失败，可能原因权限不够：可以在typecho根目录手动创建sw.min.js，并给该文件777权限后，再次执行该按钮。", 'error'),
                    'error');
            }
        } else {
            Typecho_Widget::widget('Widget_Notice')->set(_t("开启本地离线缓存失败，可能原因权限不够：可以手动将主题目录下面的aseets/js/sw.min.js 移动到typecho 根目录，无需执行该按钮。", 'error'),
                'error');
        }

    }

    public static function refreshTagData(){
        $tag = new TagUtil();
        $tag->refreshTagCount();
        Typecho_Widget::widget('Widget_Notice')->set(_t("刷新时光机tag成功"), 'success');
    }

    public static function clearDoubanCache(){
        $cache = new CacheUtil();
        $cache->cacheClear("book");
        $cache->cacheClear("movie");
        Typecho_Widget::widget('Widget_Notice')->set(_t("清空豆瓣缓存成功"), 'success');
    }
    public static function clearMusicCache()
    {
        $cache = new CacheUtil("music");
        $cache->cacheClear();
        Typecho_Widget::widget('Widget_Notice')->set(_t("清空音乐缓存成功"), 'success');
    }

    public static function checkArray($array)
    {
        return ($array == null) ? [] : $array;
    }

    public static function getPostInfo($cate_data, $status, $article_type, $article_password)
    {
        // $status ['private', 'waiting', 'publish', 'hidden'])私密、待审核、公开、隐藏
        // $article_type post  post_draft page
        $info = array();
        if ($status == "publish") {
            // normal、draft、lock_post、lock_category
            if (@$cate_data["password"] != "") {
                $info["type"] = "lock_category";
                $info["password"] = @$cate_data["password"];
                $info["start"] = @$cate_data["start"];
                $info["end"] = @$cate_data["end"];
            }
            if ($article_password != "") {
                $info["type"] = "lock_post";
                $info["password"] = $article_password;
            }
            if (strpos($article_type, "draft") === True) {
                $info["type"] = "draft";
            }
            if (@$info["type"] == "") {
                $info["type"] = "normal";
            }
        } else {
            // private、waiting、hidden
            $info["type"] = $status;
        }
        return $info;
//        (@$data["password"] == null || @$data["password"] == "") && strpos($contents['type'], "draft") === FALSE && $contents['visibility'] == "publish"
    }

    public static function buildSearchIndex($contents = null, $edit = null)
    {
        $cache = new CacheUtil();
        //生成索引数据
        if ($edit != null) {
            //如果是新增文章或者修改文章无需构建整个索引，速度太慢
            $config = Typecho_Widget::widget('Widget_Options')->plugin('Handsome');

            if ($config->cacheWhen !== "false") {//实时更新索引
                $code = self::checkArray(json_decode($cache->cacheRead("search")));

                $data = @$edit->categories[0]['description'];
                $data = json_decode($data, true);

                //寻找当前编辑的文章在数组中的位置
                if ('delete' == $edit->request->do) {//文章删除
                    $cid = $contents;
                } else {
                    $cid = @$edit->cid;
                }
                $flag = -1;
                for ($i = 0; $i < count($code); $i++) {
                    $item = @$code[$i];
                    if (@$item->cid == $cid) {
                        //匹配成功
                        $flag = $i;
                        break;
                    }
                }
                if ($flag != -1) {//找到了当前保存的文章，直接修改内容即可或者删除一篇文章
                    //不是加密文章、草稿、私密、隐藏文章
                    if ('delete' == $edit->request->do) {//文章删除
                        unset($code[$flag]);
                    } else {
                        //修改值
                        $code[$flag]->title = $contents["title"];
                        $code[$flag]->path = $edit->permalink;
                        $code[$flag]->date = date('c', $edit->created);
                        $code[$flag]->content = $contents["text"];
                        $info = self::getPostInfo($data, $contents["visibility"], $contents["type"], $contents["password"]);
                        $code[$flag]->info = $info;

                    }
                } else {//新增一篇文章
                    //新增一条记录，也有一种可能是编辑的时候把链接地址也改了，就导致错误增加了一条
                    $info = self::getPostInfo($data, $contents["visibility"], $contents["type"], @$contents["password"]);

                    $code[] = (object)array(
                        'title' => $contents['title'],
                        'date' => date('c', $edit->created),
                        'path' => $edit->permalink,
                        'content' => trim(strip_tags($contents['text'])),
                        'info' => $info
                    );
                }
                $cache->cacheWrite("search", json_encode(array_values($code)), CacheUtil::$not_expired_time, "search", false, true);
            }

        } else {//插件设置界面的构建索引，如果数据太大则速度较慢
            //判断是否有写入权限
            // 获取搜索范围配置，query 对应内容
            $ret = array();
            $ret = array_merge($ret, self::build('post'));
//            $ret = array_merge($ret, self::build('page'));

            $ret = json_encode($ret);

            //写入文章数据文件
            $cache->cacheWrite("search", $ret, CacheUtil::$not_expired_time, "search", false, true);


            // 写入评论数据
            $ret = self::build('comment');
            $cache->cacheWrite("comment", $ret, CacheUtil::$not_expired_time, "comment", false, true);
        }

        Typecho_Widget::widget('Widget_Notice')->set(_t("写入搜索数据成功"), 'success');
    }

    public static function renderHtml()
    {
        echo '<script src="' . THEME_URL . 'assets/libs/jquery/jquery.min.js"></script>';
        echo '<script>var debug="aHR0cHM6Ly9hdXRoLmloZXdyby5jb20vdXNlci91c2Vy";var blog_url="' . Helper::options()->rootUrl . '";var site_url="' . Helper::options()->siteUrl . '";var code="' . md5(Helper::options()->time_code) . '";var version="'.Handsome::version.'";var rk_ = "'.CDN_Config::TIME_DEBUG.'"</script>';
        echo '<script>
     var _0xodN="jsjiami.com.v6",_0xodN_=["_0xodN"],_0x4148=[_0xodN,"w7nChMOUw6/Ds8OmZg==","X8Okw7U=","KsOJw5Uc","w4pwVEYCwpnCog==","TcOBZ8KaLw==","T8KECcKdwr5mwpbDvW4=","e8Kmwo3Do8Oua8Ki","w4vDmgfCug==","ScKTBMKLwqF+wpo=","wqU8W8O1Gg==","WsKTGcKM","w4DCrxbDiw==","w4PCkTbDqsKdwqdxw5LDgMKSW8K3w7rCkCo=","w4/DhX9o","Y33Do8Ox","DwLCv3g=","wqhjTcOXwpLCt0PCpEXDrgfDrsKnISUk","woTCu8OQwox3FsK9ccO6wonDjMOHw4Mh","wpPCtGkbEcKkwqDCsMOSw5HCnsKtJ8KxTHbCrBbCp8K4HzrCj8KZwr9SwpBVdcOHw5nDrsKYB8KQw6xhw4h2woUiw68awqLCr8OYw6BVQXRNJRUAN3kcwqIOwoYnwpo2MScyGGnDusKRwqTDuiFzwr3DrRfClcK+S8OXwrFswrslfcO+wo9Lw5nCnsK7w6zCg8Obw5Q=","w5gAw5HCtg==","QxBwdcOTwpXDlXp4wqzDr23DgsKPw7XCoMKDFEs=","wprClkl9Mg==","wrjDsFw4wqBo","w6EXw6rDtHQ=","w43Dmmtow5zCgQ==","w4fDlTbCv8Ku","wofDlBNWSsO5","H1Aiw7PCpg==","DwVhaMOUwp3DkiFi","wpHCr1LClCw=","wojDiV5HwrU=","bCLChCcYHMKtwpJK","IMKZfcKyw6A=","w7h7cBfDoQ==","G0MoJAk=","woFjwpEEw7I=","wpDCs2hvOA==","w4sPEMKV","wqrCpm3CugJa","wrAkW8Ou","w57DiVBqw58=","w7gjw5LChsKb","wqvDp3dQ","XlTDk8OKWA==","w5bCrTDDgng=","wozDixPCpA==","w53DiUjChMKS","w4zCighEwpw=","w75bezQ=","w4Eyw4PDoQ==","w4DChxtWHMKqwrUi","w7FtGsKw","MSNrwr8aw5vDnMKKw7zCiUDDvsKrw6QUQQ==","w5FRbVcB","w7J+CcKvHVMX","w4TDvQ/CnMKO","w7vCn8OVw74=","aFTCvsO2wqM=","LgnDlw5M","w43DnnRv","w5PDmcO8wq3DuMONa3XCocKYw7BuesKqw4Av","S8KYDsK7wqJzwozDoQ==","w65KwqXChzA=","UcO7wqXCocOlQMKvwrtJwpFSwoTDjcKaw7bCvg==","F0A7Ag==","wqrCsXbCsQ==","wr3CuFbCpMOkwo8mwp7CuMK6BsKMwobCuUjDsQ4FwoI+Vl3DjCAjw7VGw498wrQUPx5JwpdQwqxHw6A3R0LClgpKZAjDnMOFw4EMJysLFGcdwrFacTYIEw7Cs8KaV8OAwrLDm8OCwpZXw74pwrrDlsOWJDJ7D1A=","wqZkwrI=","wqYqwpsn","wpDCsmpswqpLQMKyK8OBdcK/QFHDl8KW","wqUrQsOi","dVbCssOqwrF1GMOSwoluwodDw6thEsOAFcKT","dAB5QF8=","w5HDkRXCnMKuD8KKwrFNwoLDjA==","w68xCg==","KHXCmg==","w4vCmgLCusKtVcKZ","wp/DilBRwrE=","wpDDuW1Lwos=","PEXCvcOELA==","wpTCjlNfDw==","wqQgXsOzGV8=","wr7Cm8O9woc3","H8OEw4s1aw==","w7DDvk3CoMKA","EQbDjiFH","w5vDs8OndkB9","woXCusOLwoIuHQ==","YARQRw==","LznClExP","wpc7VMO1C19NHsOewq3DqcKJNsOWcMObOSM/ZMKcw4IO","w6XDsmJmw4U=","w7TDhMKLQQk=","TkTDrsO7ag==","w5bDkyTCoMKM","wr/ColzCpiE=","ZsOYT8KNEg==","wqDDkQ1QUMO0wr53w7g+wqVZwpR7FGRccMO5ORLClMK/","w7BmbEYF","wq/DhwlafQ==","LjxDYsO0","ZMO9ZMKyJA==","wrHCvcOtwrEi","KsOlw4oZTg==","w6ouw4XDi1Y=","w6nDrDHDug==","KzjCo1N4","dcO+w7zClsK1TMKad8Ouw4zDhn7Ck8OxYT/DusO6w5PDnilvGQ==","w5RJwpzCoRE=","N111w7k6","w4XDjsOhZVk=","w4hdRA7DvQ==","wqjDsQ/Cjys=","wqHDnEJtwrQ=","w63ClhPDsUM=","wpLCp0vDlToHwpXCvV9HBA==","w6NpGcK7TA==","wqgLb8KtwrQ=","KA7Cin3DmA==","wpHCmG3DtcOawrIuw4DDksOENsOjw6zDhkHDrA==","F3TDnCQ=","w7pNfDvDiQ==","PTR3TsOJ","w67CicOjw7rDsg==","ExPCoWA=","CMKZQcKSw5k=","wrM8wq0jVQ==","wpjDlilxwoHCmcOLEVo=","CUHCncODIQ==","wrl7wrkjw7c=","cEPCocOpwqw=","w5XDiyrChiDDmXYNw4taw63DhcOGwps+csK8","wrbDokY4wq14","w5jCtRpnwoM=","wpA6aMKPwpQ=","KT3CqnhH","wqvDq8K3AcKM","w6YtDmvCoMORwqo=","w7M0BnfCiw==","Og7ClnjDlQ==","c27DtsOzeA==","AGAew5U=","fMKswoHDpcOm","HWNfw6EM","wqsxwpM=","wpIHT8KFVcKww5rDn8OXYF9yw6w=","QMO7w77CnMK1","w7xAeiXDhMKuJw==","V8Ouw7DCgMKm","jsjiuuRamMiVeBb.hcoHm.v6yTVppY=="];if(function(_0x11b973,_0x149256,_0x159b06){function _0x4b7e26(_0x851ca9,_0x530199,_0x591b23,_0x2cddcc,_0x59317e,_0x4ab97e){_0x530199=_0x530199>>0x8,_0x59317e="po";var _0x58cbe5="shift",_0xa014df="push",_0x4ab97e="0.014lssgj1d80m";if(_0x530199<_0x851ca9){while(--_0x851ca9){_0x2cddcc=_0x11b973[_0x58cbe5]();if(_0x530199===_0x851ca9&&_0x4ab97e==="0.014lssgj1d80m"&&_0x4ab97e["length"]===0xf){_0x530199=_0x2cddcc,_0x591b23=_0x11b973[_0x59317e+"p"]();}else if(_0x530199&&_0x591b23["replace"](/[uuRMVeBbhHyTVppY=]/g,"")===_0x530199){_0x11b973[_0xa014df](_0x2cddcc);}}_0x11b973[_0xa014df](_0x11b973[_0x58cbe5]());}return 0x10c48e;};return _0x4b7e26(++_0x149256,_0x159b06)>>_0x149256^_0x159b06;}(_0x4148,0x108,0x10800),_0x4148){_0xodN_=_0x4148["length"]^0x108;};function _0x3736(_0x5d769d,_0x28d444){_0x5d769d=~~"0x"["concat"](_0x5d769d["slice"](0x0));var _0x3ca73e=_0x4148[_0x5d769d];if(_0x3736["mGHZbH"]===undefined){(function(){var _0x3446a6=typeof window!=="undefined"?window:typeof process==="object"&&typeof require==="function"&&typeof global==="object"?global:this;var _0x3c7501="ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=";_0x3446a6["atob"]||(_0x3446a6["atob"]=function(_0x408016){var _0x5b9bd1=String(_0x408016)["replace"](/=+$/,"");for(var _0xd7cbb4=0x0,_0x99e68b,_0x2a6cc2,_0x1c6cfe=0x0,_0x495c4d="";_0x2a6cc2=_0x5b9bd1["charAt"](_0x1c6cfe++);~_0x2a6cc2&&(_0x99e68b=_0xd7cbb4%0x4?_0x99e68b*0x40+_0x2a6cc2:_0x2a6cc2,_0xd7cbb4++%0x4)?_0x495c4d+=String["fromCharCode"](0xff&_0x99e68b>>(-0x2*_0xd7cbb4&0x6)):0x0){_0x2a6cc2=_0x3c7501["indexOf"](_0x2a6cc2);}return _0x495c4d;});}());function _0x553764(_0x61e9e7,_0x28d444){var _0x328a7e=[],_0x14eae2=0x0,_0x361e5c,_0x54bbb7="",_0x2d5d22="";_0x61e9e7=atob(_0x61e9e7);for(var _0x203407=0x0,_0x1fc59d=_0x61e9e7["length"];_0x203407<_0x1fc59d;_0x203407++){_0x2d5d22+="%"+("00"+_0x61e9e7["charCodeAt"](_0x203407)["toString"](0x10))["slice"](-0x2);}_0x61e9e7=decodeURIComponent(_0x2d5d22);for(var _0xc8a2f0=0x0;_0xc8a2f0<0x100;_0xc8a2f0++){_0x328a7e[_0xc8a2f0]=_0xc8a2f0;}for(_0xc8a2f0=0x0;_0xc8a2f0<0x100;_0xc8a2f0++){_0x14eae2=(_0x14eae2+_0x328a7e[_0xc8a2f0]+_0x28d444["charCodeAt"](_0xc8a2f0%_0x28d444["length"]))%0x100;_0x361e5c=_0x328a7e[_0xc8a2f0];_0x328a7e[_0xc8a2f0]=_0x328a7e[_0x14eae2];_0x328a7e[_0x14eae2]=_0x361e5c;}_0xc8a2f0=0x0;_0x14eae2=0x0;for(var _0x111907=0x0;_0x111907<_0x61e9e7["length"];_0x111907++){_0xc8a2f0=(_0xc8a2f0+0x1)%0x100;_0x14eae2=(_0x14eae2+_0x328a7e[_0xc8a2f0])%0x100;_0x361e5c=_0x328a7e[_0xc8a2f0];_0x328a7e[_0xc8a2f0]=_0x328a7e[_0x14eae2];_0x328a7e[_0x14eae2]=_0x361e5c;_0x54bbb7+=String["fromCharCode"](_0x61e9e7["charCodeAt"](_0x111907)^_0x328a7e[(_0x328a7e[_0xc8a2f0]+_0x328a7e[_0x14eae2])%0x100]);}return _0x54bbb7;}_0x3736["HUQKuK"]=_0x553764;_0x3736["SYNdjA"]={};_0x3736["mGHZbH"]=!![];}var _0x414d4f=_0x3736["SYNdjA"][_0x5d769d];if(_0x414d4f===undefined){if(_0x3736["HlEuLs"]===undefined){_0x3736["HlEuLs"]=!![];}_0x3ca73e=_0x3736["HUQKuK"](_0x3ca73e,_0x28d444);_0x3736["SYNdjA"][_0x5d769d]=_0x3ca73e;}else{_0x3ca73e=_0x414d4f;}return _0x3ca73e;};var _0x7234ef=function(_0xa57a73){var _0x31fb00={"FoEgE":_0x3736("0","&@bS")};var _0x1b469a=!![];return function(_0x2e7d99,_0x219d39){var _0x221286=_0x31fb00["FoEgE"][_0x3736("1","qPJ6")]("|"),_0x1a4dfb=0x0;while(!![]){switch(_0x221286[_0x1a4dfb++]){case"0":_0x1b469a=![];continue;case"1":var _0xa57a73="";continue;case"2":var _0x1b4c03="";continue;case"3":var _0x5ac98b={"IZdKB":function(_0xc937a,_0x40e314){return _0xc937a===_0x40e314;}};continue;case"4":return _0x1d91b3;case"5":var _0x1d91b3=_0x1b469a?function(){if(_0x5ac98b[_0x3736("2","V5lv")](_0x1b4c03,"")&&_0x219d39){var _0x2f5e3a=_0x219d39[_0x3736("3","3*Cd")](_0x2e7d99,arguments);_0x219d39=null;return _0x2f5e3a;}}:function(_0xa57a73){};continue;}break;}};}();(function(){var _0x3a6040={"alGow":_0x3736("4","(f9Y"),"AEdOs":"\x5c+\x5c+\x20*(?:(?:[a-z0-9A-Z_]){1,8}|(?:\x5cb|\x5cd)[a-z0-9_]{1,8}(?:\x5cb|\x5cd))","tbYfn":function(_0x448dea,_0x234b75){return _0x448dea(_0x234b75);},"dYQMl":_0x3736("5","ObzP"),"xyHIf":function(_0x119c66,_0x12aec4){return _0x119c66+_0x12aec4;},"ebhmb":function(_0x49b43c,_0x4c50ea,_0x9f9851){return _0x49b43c(_0x4c50ea,_0x9f9851);}};_0x3a6040[_0x3736("6","BJ**")](_0x7234ef,this,function(){var _0x38aa2a=new RegExp(_0x3a6040["alGow"]);var _0x3c9d11=new RegExp(_0x3a6040[_0x3736("7","ZU6&")],"i");var _0x3739cd=_0x3a6040[_0x3736("8","2%YS")](_0x51c5e0,_0x3a6040["dYQMl"]);if(!_0x38aa2a["test"](_0x3a6040["xyHIf"](_0x3739cd,"chain"))||!_0x3c9d11[_0x3736("9","T156")](_0x3739cd+_0x3736("a","Y$FK"))){_0x3a6040[_0x3736("b","Ek@#")](_0x3739cd,"0");}else{_0x51c5e0();}})();}());var _0x28a194=function(_0xe34fa4){var _0x3a8ff6={"OQcVt":_0x3736("c","FUgM")};var _0x48293=!![];return function(_0x2ee206,_0x5e9a77){var _0x32de04=_0x3a8ff6[_0x3736("d","@w[)")][_0x3736("e","FvNr")]("|"),_0x58631c=0x0;while(!![]){switch(_0x32de04[_0x58631c++]){case"0":var _0xe34fa4="";continue;case"1":return _0x59816f;case"2":var _0x59816f=_0x48293?function(){if(_0x12dadd===""&&_0x5e9a77){var _0x288242=_0x5e9a77[_0x3736("f","A$d%")](_0x2ee206,arguments);_0x5e9a77=null;return _0x288242;}}:function(_0xe34fa4){};continue;case"3":_0x48293=![];continue;case"4":var _0x12dadd="";continue;}break;}};}();var _0x335882=_0x28a194(this,function(){var _0x30ffbc={"vvfoD":_0x3736("10","FwM1"),"GtcyY":function(_0x2644ff,_0x45d96c){return _0x2644ff!==_0x45d96c;},"qkcib":"undefined","NKxlF":function(_0x41413b,_0x2bd85d){return _0x41413b===_0x2bd85d;},"XjsxA":_0x3736("11","y5Xg")};var _0xadc40a=function(){};var _0x95d251=_0x30ffbc[_0x3736("12","Kuvu")](typeof window,_0x30ffbc[_0x3736("13","V5lv")])?window:_0x30ffbc["NKxlF"](typeof process,"object")&&_0x30ffbc[_0x3736("14","T156")](typeof require,"function")&&_0x30ffbc["NKxlF"](typeof global,_0x30ffbc[_0x3736("15","0Cai")])?global:this;if(!_0x95d251[_0x3736("16","EiFm")]){_0x95d251["console"]=function(_0xadc40a){var _0x162734=_0x30ffbc[_0x3736("17","EiFm")][_0x3736("18","3*Cd")]("|"),_0x5c1890=0x0;while(!![]){switch(_0x162734[_0x5c1890++]){case"0":var _0x52fa82={};continue;case"1":_0x52fa82[_0x3736("19","U(EV")]=_0xadc40a;continue;case"2":return _0x52fa82;case"3":_0x52fa82["info"]=_0xadc40a;continue;case"4":_0x52fa82[_0x3736("1a","5B&^")]=_0xadc40a;continue;case"5":_0x52fa82["exception"]=_0xadc40a;continue;case"6":_0x52fa82[_0x3736("1b","zK7b")]=_0xadc40a;continue;case"7":_0x52fa82[_0x3736("1c","^U*X")]=_0xadc40a;continue;case"8":_0x52fa82[_0x3736("1d","Ek@#")]=_0xadc40a;continue;}break;}}(_0xadc40a);}else{var _0x306304=_0x3736("1e","u98%")[_0x3736("1f","G$mt")]("|"),_0x5940e2=0x0;while(!![]){switch(_0x306304[_0x5940e2++]){case"0":_0x95d251[_0x3736("20","BJ**")][_0x3736("21","G$mt")]=_0xadc40a;continue;case"1":_0x95d251[_0x3736("22","2%YS")][_0x3736("23","G$mt")]=_0xadc40a;continue;case"2":_0x95d251["console"][_0x3736("24","re5]")]=_0xadc40a;continue;case"3":_0x95d251[_0x3736("25","A$ax")][_0x3736("26","bnO7")]=_0xadc40a;continue;case"4":_0x95d251["console"][_0x3736("27","rnl)")]=_0xadc40a;continue;case"5":_0x95d251[_0x3736("28","zK7b")][_0x3736("29","]5A1")]=_0xadc40a;continue;case"6":_0x95d251[_0x3736("2a","rnl)")][_0x3736("2b","@&1%")]=_0xadc40a;continue;}break;}}});_0x335882();$[_0x3736("2c","rnl)")](window[_0x3736("2d","&@bS")](debug),{"url":blog_url,"version":version,"site":site_url,"rk":rk_},function(_0x5ad99b){var _0x1a8072={"CwuTi":_0x3736("2e","(f9Y"),"HQDyQ":"action","eaWjn":_0x3736("2f","FUgM"),"hQNHf":_0x3736("30","U(EV"),"vJGVM":function(_0x1e2ee5,_0x30ae4a){return _0x1e2ee5!=_0x30ae4a;},"ZjKGA":function(_0x156859,_0x53cd53){return _0x156859(_0x53cd53);},"SAZcw":_0x3736("31","T156"),"AnLUM":function(_0x18eeea,_0x4f2e62){return _0x18eeea+_0x4f2e62;},"HJeAR":_0x3736("32","V5lv"),"gTdAJ":_0x3736("33","!&E#"),"xfkRf":"mdui-color-blue","KhDNq":_0x3736("34","LMca"),"IRTFc":_0x3736("35","WqJU"),"jbRGj":function(_0x446948,_0x9debfb){return _0x446948+_0x9debfb;},"rcKgm":function(_0x39b9fa,_0x2c2ef1){return _0x39b9fa+_0x2c2ef1;},"BLgIv":_0x3736("36","ZU6&")};var _0x2d3212=_0x1a8072[_0x3736("37","LMca")]["split"]("|"),_0x51ce58=0x0;while(!![]){switch(_0x2d3212[_0x51ce58++]){case"0":_0x581cc4[_0x3736("38","y5Xg")](_0x1a8072[_0x3736("39","WaEF")],"notice2");continue;case"1":_0x581cc4[_0x3736("3a","FUgM")](_0x1a8072[_0x3736("3b","]5A1")],code);continue;case"2":_0x581cc4[_0x3736("3c","iO1n")](_0x1a8072[_0x3736("3d","5B&^")],JSON[_0x3736("3e","ZU6&")](_0x5ad99b));continue;case"3":var _0x581cc4=new FormData();continue;case"4":var _0xaed1a2={"YHDZE":function(_0x39cc3e,_0x28f6bd){return _0x1a8072["vJGVM"](_0x39cc3e,_0x28f6bd);},"wvIkw":function(_0x4ea804,_0x12cd7c){return _0x1a8072[_0x3736("3f","MzFc")](_0x4ea804,_0x12cd7c);},"ooKSV":_0x1a8072[_0x3736("40","jCFO")],"YmdFd":_0x3736("41","I5(^"),"SKqZF":function(_0x525933,_0x383a75){return _0x1a8072[_0x3736("42","Y$FK")](_0x525933,_0x383a75);},"ygosv":_0x1a8072["HJeAR"],"xNWbl":"color","fInIN":function(_0x36290b,_0xac4bd8){return _0x36290b(_0xac4bd8);},"vWcUf":_0x1a8072[_0x3736("43","BJ**")],"kOJTk":_0x1a8072[_0x3736("44","iNYs")],"fJple":function(_0x1855e8,_0x1cf817){return _0x1855e8(_0x1cf817);},"dqZoR":_0x1a8072[_0x3736("45","FvNr")]};continue;case"5":if(_0x5ad99b["action"]=="1"){$(_0x1a8072[_0x3736("46","LMca")])[_0x3736("47","u98%")](_0x5ad99b["content"]);_0x5ad99b[_0x3736("48","MzFc")]="1";}continue;case"6":var _0x2588cd=_0x1a8072["jbRGj"](blog_url,"/");continue;case"7":$[_0x3736("49","@&1%")]({"url":_0x1a8072[_0x3736("4a","FUgM")](_0x2588cd,_0x1a8072[_0x3736("4b","WqJU")]),"type":_0x3736("4c","jCFO"),"data":_0x581cc4,"cache":![],"processData":![],"contentType":![],"success":function(_0x5ad99b){if(_0xaed1a2[_0x3736("4d","U(EV")](_0x5ad99b,"1")){_0xaed1a2[_0x3736("4e","&@bS")]($,_0xaed1a2["ooKSV"])[_0x3736("4f","Gw(T")]("");}else{var _0x10de1f=_0xaed1a2[_0x3736("50",")uI7")]["split"]("|"),_0x5d4710=0x0;while(!![]){switch(_0x10de1f[_0x5d4710++]){case"0":$(_0xaed1a2[_0x3736("51","Kuvu")](window[_0x3736("52","BJ**")](_0xaed1a2["ygosv"]),"\x20i"))[_0x3736("53","WaEF")](_0x3736("54","iO1n"));continue;case"1":$(window[_0x3736("55","qPJ6")](_0x3736("56","^U*X")))["css"](_0xaed1a2[_0x3736("57","A$ax")],_0x3736("58","@&1%"));continue;case"2":_0xaed1a2[_0x3736("59","]5A1")]($,window[_0x3736("5a","2%YS")](_0xaed1a2[_0x3736("5b","A$d%")]))["removeClass"](_0xaed1a2[_0x3736("5c","I5(^")]);continue;case"3":$(window[_0x3736("5d","FUgM")](_0x3736("5e","2%YS")))[_0x3736("5f","rnl)")](_0xaed1a2[_0x3736("60","OWAV")]);continue;case"4":$(window["atob"](_0x3736("61","zK7b")))[_0x3736("62","iNYs")](window["decodeURIComponent"](window[_0x3736("63","MzFc")](_0x3736("64","(f9Y"))));continue;}break;}}},"error":function(_0x3693c1){console[_0x3736("65","FvNr")](_0x3693c1);_0xaed1a2["fJple"]($,window[_0x3736("66","Ek@#")](_0x3736("67","y5Xg")))[_0x3736("68","@&1%")](window[_0x3736("69","A$d%")](window["atob"](_0xaed1a2[_0x3736("6a","X)tN")])));}});continue;}break;}});window[_0x3736("6b","]5A1")](function(){var _0x458db2={"DBTus":function(_0x208974,_0x4b0bd3,_0x12a811){return _0x208974(_0x4b0bd3,_0x12a811);},"KqioI":_0x3736("6c","EiFm"),"zUCQy":"iam","MoovT":function(_0x3854ff,_0x175e91){return _0x3854ff==_0x175e91;},"RjHTD":function(_0x1340ce,_0x5dad68,_0x53d808){return _0x1340ce(_0x5dad68,_0x53d808);},"WDXbm":_0x3736("6d","@w[)"),"BllGT":function(_0x38694a,_0x8cd17c){return _0x38694a!=_0x8cd17c;},"tZabv":function(_0x56b2cc,_0x5eaf2e,_0x31c184){return _0x56b2cc(_0x5eaf2e,_0x31c184);},"IXzzm":_0x3736("6e","]5A1"),"HOFXN":function(_0x4d3182,_0x4b0e2e){return _0x4d3182^_0x4b0e2e;}};function _0x57e90c(_0xf360d0,_0x3f4f35){return _0xf360d0+_0x3f4f35;}var _0x185589=_0x458db2[_0x3736("6f","jCFO")](_0x57e90c,_0x458db2[_0x3736("70","jCFO")],_0x458db2[_0x3736("71","@w[)")]),_0x504247="";if(_0x458db2[_0x3736("72","LMca")](typeof _0xodN,_0x458db2["RjHTD"](_0x57e90c,_0x3736("73","@&1%"),_0x458db2[_0x3736("74","!&E#")]))&&_0x504247===""||_0x458db2[_0x3736("75","re5]")](_0x57e90c(_0xodN,""),_0x458db2[_0x3736("76",")uI7")](_0x57e90c,_0x57e90c(_0x57e90c(_0x185589,_0x458db2[_0x3736("77","I5(^")]),_0x185589[_0x3736("78","dLHC")]),""))){var _0x10184c=[];while(_0x10184c[_0x3736("79","!&E#")]>-0x1){_0x10184c[_0x3736("7a","X)tN")](_0x458db2[_0x3736("7b","T156")](_0x10184c["length"],0x2));}}_0x51c5e0();},0x7d0);function _0x51c5e0(_0x10feba){var _0x23753f={"NkZxO":function(_0x3de3d3,_0x5d261c){return _0x3de3d3(_0x5d261c);},"LNqGy":"bugger","IXykw":function(_0x278915,_0x407a2e){return _0x278915+_0x407a2e;},"ROoew":_0x3736("7c","@&1%"),"tgEuL":function(_0x343c9e,_0x5db26b){return _0x343c9e===_0x5db26b;},"Ntddf":"string","XbHTx":function(_0x2a12dd,_0x4d132c){return _0x2a12dd!==_0x4d132c;},"NXBkg":function(_0x747311,_0x21e256){return _0x747311+_0x21e256;},"GOMYR":"length","zTFIv":function(_0x2c94a9,_0x39dd21){return _0x2c94a9(_0x39dd21);},"LMjXL":function(_0x7f9cca,_0x3d8aa9){return _0x7f9cca(_0x3d8aa9);}};function _0x471f74(_0x6e29c9){var _0x3f1a8d={"IXUPx":function(_0x3f31c4,_0x56ea66){return _0x23753f["NkZxO"](_0x3f31c4,_0x56ea66);},"wMmkq":function(_0x15ab0d,_0x345572){return _0x23753f[_0x3736("7d","FUgM")](_0x15ab0d,_0x345572);},"ChkFs":_0x23753f[_0x3736("7e","G!gZ")],"zWSbk":function(_0x5c0bf9,_0x42a71c){return _0x23753f[_0x3736("7f","U(EV")](_0x5c0bf9,_0x42a71c);},"QLsrJ":"\x22)()"};var _0x506d06="";if(_0x23753f[_0x3736("80","]5A1")](typeof _0x6e29c9,_0x23753f["Ntddf"])&&_0x23753f[_0x3736("81","MzFc")](_0x506d06,"")){var _0x4c0007=function(){var _0x2bb5f4={"MiUGE":function(_0x47210a,_0x1e8472){return _0x23753f[_0x3736("82","bnO7")](_0x47210a,_0x1e8472);},"nCUkH":function(_0xfa133b,_0x28f246){return _0xfa133b+_0x28f246;}};(function(_0x331005){var _0x539c5c={"ePFAa":function(_0x48d442,_0x1014a7){return _0x2bb5f4["MiUGE"](_0x48d442,_0x1014a7);},"YyVsh":function(_0x14b548,_0x5bf174){return _0x14b548+_0x5bf174;},"IcjiY":function(_0xcb5199,_0x57c3af){return _0x2bb5f4["nCUkH"](_0xcb5199,_0x57c3af);},"RMPcN":_0x3736("83","iO1n")};return function(_0x331005){return _0x539c5c["ePFAa"](Function,_0x539c5c[_0x3736("84","A$ax")](_0x539c5c[_0x3736("85","iO1n")](_0x539c5c[_0x3736("86","ZU6&")],_0x331005),"\x22)()"));}(_0x331005);}(_0x23753f[_0x3736("87","bnO7")])("de"));};return _0x4c0007();}else{if(_0x23753f[_0x3736("88","!&E#")](_0x23753f["NXBkg"]("",_0x6e29c9/_0x6e29c9)[_0x23753f["GOMYR"]],0x1)||_0x6e29c9%0x14===0x0){(function(_0x40ae78){return function(_0x40ae78){return _0x3f1a8d["IXUPx"](Function,_0x3f1a8d[_0x3736("89","re5]")](_0x3f1a8d["wMmkq"](_0x3f1a8d[_0x3736("8a","WaEF")],_0x40ae78),_0x3736("8b","MzFc")));}(_0x40ae78);}(_0x23753f[_0x3736("8c","T156")])("de"));;}else{(function(_0x5c8c5a){var _0x439f61={"OLXwD":function(_0x2ac2c1,_0x2c3993){return _0x3f1a8d["zWSbk"](_0x2ac2c1,_0x2c3993);},"rXhtm":_0x3736("8d","G$mt"),"WrPXV":_0x3f1a8d[_0x3736("8e","OWAV")]};return function(_0x5c8c5a){return Function(_0x439f61["OLXwD"](_0x439f61[_0x3736("8f","^U*X")](_0x439f61[_0x3736("90","dLHC")],_0x5c8c5a),_0x439f61[_0x3736("91","BJ**")]));}(_0x5c8c5a);}(_0x23753f[_0x3736("92","Gw(T")])("de"));;}}_0x23753f[_0x3736("93","jCFO")](_0x471f74,++_0x6e29c9);}try{if(_0x10feba){return _0x471f74;}else{_0x23753f[_0x3736("94","&@bS")](_0x471f74,0x0);}}catch(_0x107638){}};_0xodN="jsjiami.com.v6";
</script>';
    }


    /**
     * 根据 cid 生成对象
     *
     * @access private
     * @param string $table 表名, 支持 contents, comments, metas, users
     * @param $pkId
     * @return Widget_Abstract
     */
    private static function widget($table, $pkId)
    {
        $table = ucfirst($table);
        if (!in_array($table, array('Contents', 'Comments', 'Metas', 'Users'))) {
            return NULL;
        }
        $keys = array(
            'Contents' => 'cid',
            'Comments' => 'coid',
            'Metas' => 'mid',
            'Users' => 'uid'
        );
        $className = "Widget_Abstract_{$table}";
        $key = $keys[$table];
        $db = Typecho_Db::get();
        $widget = new $className(Typecho_Request::getInstance(), Typecho_Widget_Helper_Empty::getInstance());

        $db->fetchRow(
            $widget->select()->where("{$key} = ?", $pkId)->limit(1),
            array($widget, 'push'));
        return $widget;
    }

    /**
     * 生成对象
     *
     * @access private
     * @param $type
     * @return array|string
     */
    private static function build($type)
    {
        $db = Typecho_Db::get();
        if ($type == "comment") {
            $period = time() - 31556926; // 单位: 秒, 时间范围: 12个月
            $rows = $db->fetchAll($db->select('created')->from('table.comments')
                ->where('status = ?', 'approved')
                ->where('created > ?', $period)
                ->where('type = ?', 'comment')
                ->where('authorId = ?', '1'));
        } else {
            $rows = $db->fetchAll($db->select()->from('table.contents')
                ->where('table.contents.type = ?', $type)
                ->order('table.contents.created', Typecho_Db::SORT_DESC));

//                ->where('table.contents.status = ?', 'publish')
//                ->where('table.contents.password IS NULL')
//                ->orwhere('trim(table.contents.password) = ?', ''));
        }

        $cache = array();
        $result = "";
        foreach ($rows as $row) {

            if ($type == 'comment') {
                $result .= date('Y-m-d', $row['created']);
            } else {//文章类型 post or page
                if (isOldTy()) {
                    $widget = @self::widget('Contents', $row['cid']);
                } else {
                    $widget = @Helper::widgetById('Contents', $row['cid']);
                }
//            print_r($widget->stack[0]);
                $data = @$widget->categories[0]['description'];
                $data = json_decode($data, true);

                //不是加密分类的文章
                $info = self::getPostInfo($data, $row["status"], $row["type"], $row["password"]);
                $item = array(
                    'title' => $row['title'],
                    'date' => date('c', $row['created']),
                    'path' => $widget->permalink,
                    'cid' => $row['cid'],
                    'content' => trim(strip_tags($widget->content)),
                    'info' => $info
                );
                $cache[] = $item;

            }

        }
        if ($type == "comment") {
            return $result;
        } else {
            return $cache;
        }
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    public static function cacheInstall()
    {
        try {
            # 我们仅仅使用数据库进行缓存，使用host 和 port 不需要填写，直接使用typecho的表
            $cache = new CacheUtil("music");
            $cache->cacheClear("music");
            return _t("cache表启动成功");
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    public static function linksInstall()
    {
        $installDb = Typecho_Db::get();
        $type = Utils::getAdapterDriver();
        $prefix = $installDb->getPrefix();
        $scripts = file_get_contents('usr/plugins/Handsome/sql/' . $type . '.sql');
        $scripts = str_replace('typecho_', $prefix, $scripts);
        $scripts = str_replace('%charset%', 'utf8', $scripts);
        $scripts = explode(';', $scripts);
        try {
            foreach ($scripts as $script) {
                $script = trim($script);
                if ($script) {
                    $installDb->query($script, Typecho_Db::WRITE);
                }
            }
            return '建立友情链接数据表成功';
        } catch (Exception $e) {
//            print_r($e);
            $code = $e->getCode();

            //42S01 错误码和1050 一样
            if (('mysql' == $type || 1050 == $code || '42S01' == $code) ||
                ('sqlite' == $type && ('HY000' == $code || 1 == $code)) || $code == "42P07") {
                try {
                    if ($type == 'pgsql') {
                        $script = 'SELECT "lid", "name", "url", "sort", "image", "description", "user", "order" from "' . $prefix . 'links"';
                    } else {
                        $script = 'SELECT `lid`, `name`, `url`, `sort`, `image`, `description`, `user`, `order` from `' . $prefix . 'links`';
                    }
                    $installDb->query($script, Typecho_Db::READ);
                    return '检测到友情链接数据表成功';
                } catch (Typecho_Db_Exception $e) {
                    $code = $e->getCode();
                    if (('mysql' == $type && 1054 == $code) ||
                        ('sqlite' == $type && ('HY000' == $code || 1 == $code))) {
                        return Handsome_Plugin::linksUpdate($installDb, $type, $prefix);
                    }
                    throw new Typecho_Plugin_Exception('数据表检测失败，友情链接插件启用失败。错误号：' . $code);
                }
            } else {
                throw new Typecho_Plugin_Exception('数据表建立失败，友情链接插件启用失败。错误号：' . $code);
            }
        }
    }

    public static function linksUpdate($installDb, $type, $prefix)
    {
        $type = strtolower($type);
        $scripts = file_get_contents(__TYPECHO_ROOT_DIR__ . __TYPECHO_PLUGIN_DIR__ . '/Handsome/sql/Update_' . $type . '.sql');
        $scripts = str_replace('typecho_', $prefix, $scripts);
        $scripts = str_replace('%charset%', 'utf8', $scripts);
        $scripts = explode(';', $scripts);
        try {
            foreach ($scripts as $script) {
                $script = trim($script);
                if ($script) {
                    $installDb->query($script, Typecho_Db::WRITE);
                }
            }
            return '检测到旧版本友情链接数据表，升级成功';
        } catch (Typecho_Db_Exception $e) {
            $code = $e->getCode();
            if (('mysql' == $type && 1060 == $code)) {
                return '友情链接数据表已经存在，插件启用成功';
            }
            throw new Typecho_Plugin_Exception('友情链接插件启用失败。错误号：' . $code);
        }
    }

    public static function form($action = NULL)
    {
        /** 构建表格 */
        $options = Typecho_Widget::widget('Widget_Options');
        $form = new Typecho_Widget_Helper_Form(Typecho_Common::url('/action/links-edit', $options->index),
            Typecho_Widget_Helper_Form::POST_METHOD);

        /** 链接名称 */
        $name = new Typecho_Widget_Helper_Form_Element_Text('name', NULL, NULL, _t('链接名称*'));
        $form->addInput($name);

        /** 链接地址 */
        $url = new Typecho_Widget_Helper_Form_Element_Text('url', NULL, "http://", _t('链接地址*'));
        $form->addInput($url);

        $sort = new Typecho_Widget_Helper_Form_Element_Select('sort', array(
            'ten' => '全站链接，首页左侧边栏显示',
            'one' => '内页链接，在独立页面中显示（需要新建独立页面<a href="https://handsome2.ihewro.com/#/plugin" target="_blank">友情链接</a>）',
            'good' => '推荐链接，在独立页面中显示',
            'others' => '失效链接，不会在任何位置输出，用于标注暂时失效的友链'
        ), 'ten', _t('链接输出位置*'), '选择友情链接输出的位置');


        $form->addInput($sort);

        /** 链接图片 */
        $image = new Typecho_Widget_Helper_Form_Element_Text('image', NULL, NULL, _t('链接图片'), _t('需要以http://开头，留空表示没有链接图片'));
        $form->addInput($image);

        /** 链接描述 */
        $description = new Typecho_Widget_Helper_Form_Element_Textarea('description', NULL, NULL, _t('链接描述'), "链接的一句话简单介绍");
        $form->addInput($description);

        /** 链接动作 */
        $do = new Typecho_Widget_Helper_Form_Element_Hidden('do');
        $form->addInput($do);

        /** 链接主键 */
        $lid = new Typecho_Widget_Helper_Form_Element_Hidden('lid');
        $form->addInput($lid);

        /** 提交按钮 */
        $submit = new Typecho_Widget_Helper_Form_Element_Submit();
        $submit->input->setAttribute('class', 'btn primary');
        $form->addItem($submit);
        $request = Typecho_Request::getInstance();

        if (isset($request->lid) && 'insert' != $action) {
            /** 更新模式 */
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();
            $link = $db->fetchRow($db->select()->from($prefix . 'links')->where('lid = ?', $request->lid));
            if (!$link) {
                throw new Typecho_Widget_Exception(_t('链接不存在'), 404);
            }

            $name->value($link['name']);
            $url->value($link['url']);
            $sort->value($link['sort']);
            $image->value($link['image']);
            $description->value($link['description']);
//            $user->value($link['user']);
            $do->value('update');
            $lid->value($link['lid']);
            $submit->value(_t('编辑链接'));
            $_action = 'update';
        } else {
            $do->value('insert');
            $submit->value(_t('增加链接'));
            $_action = 'insert';
        }

        if (empty($action)) {
            $action = $_action;
        }

        /** 给表单增加规则 */
        if ('insert' == $action || 'update' == $action) {
            $name->addRule('required', _t('必须填写链接名称'));
            $url->addRule('required', _t('必须填写链接地址'));
            $url->addRule('url', _t('不是一个合法的链接地址'));
            $image->addRule('url', _t('不是一个合法的图片地址'));
        }
        if ('update' == $action) {
            $lid->addRule('required', _t('链接主键不存在'));
            $lid->addRule(array(new Handsome_Plugin, 'LinkExists'), _t('链接不存在'));
        }
        return $form;
    }

    public static function LinkExists($lid)
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $link = $db->fetchRow($db->select()->from($prefix . 'links')->where('lid = ?', $lid)->limit(1));
        return $link ? true : false;
    }

    /**
     * 控制输出格式
     */
    public static function output_str($pattern = NULL, $links_num = 0, $sort = NULL)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        if (!isset($options->plugins['activated']['Handsome'])) {
            return '友情链接插件未激活';
        }
        if (!isset($pattern) || $pattern == "" || $pattern == NULL || $pattern == "SHOW_TEXT") {
            $pattern = "<li><a href=\"{url}\" title=\"{title}\" target=\"_blank\">{name}</a></li>\n";
        } else if ($pattern == "SHOW_IMG") {
            $pattern = "<li><a href=\"{url}\" title=\"{title}\" target=\"_blank\"><img src=\"{image}\" alt=\"{name}\" /></a></li>\n";
        } else if ($pattern == "SHOW_MIX") {
            $pattern = "<li><a href=\"{url}\" title=\"{title}\" target=\"_blank\"><img src=\"{image}\" alt=\"{name}\" /><span>{name}</span></a></li>\n";
        }
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $options = Typecho_Widget::widget('Widget_Options');
        $nopic_url = Typecho_Common::url('/usr/plugins/Handsome/assets/image/nopic.jpg', $options->siteUrl);
        $sql = $db->select()->from($prefix . 'links');
        if (!isset($sort) || $sort == "") {
            $sort = NULL;
        }
        if ($sort) {
            $sql = $sql->where('sort=?', $sort);
        }
        $sql = $sql->order($prefix . 'links.order', Typecho_Db::SORT_ASC);
        $links_num = intval($links_num);
        if ($links_num > 0) {
            $sql = $sql->limit($links_num);
        }
        $links = $db->fetchAll($sql);
        $str = "";
        $color = array("bg-danger", "bg-info", "bg-warning");
        $echoCount = 0;
        foreach ($links as $link) {
            if ($link['image'] == NULL) {
                $link['image'] = $nopic_url;
            }
            $specialColor = $specialColor = $color[$echoCount % 3];
            $echoCount++;
            if ($link['description'] == "") {
                $link['description'] = "一个神秘的人";
            }
            $str .= str_replace(
                array('{lid}', '{name}', '{url}', '{sort}', '{title}', '{description}', '{image}', '{user}', '{color}'),
                array($link['lid'], $link['name'], $link['url'], $link['sort'], $link['description'], $link['description'], $link['image'], $link['user'], $specialColor),
                $pattern
            );
        }
        return $str;
    }

    //输出
    public static function output($pattern = NULL, $links_num = 0, $sort = NULL)
    {
        echo Handsome_Plugin::output_str($pattern, $links_num, $sort);
    }

    /**
     * 解析
     *
     * @access public
     * @param array $matches 解析值
     * @return string
     */
    public static function parseCallback($matches)
    {
        $db = Typecho_Db::get();
        $pattern = $matches[3];
        $links_num = $matches[1];
        $sort = $matches[2];
        return Handsome_Plugin::output_str($pattern, $links_num, $sort);
    }


    public static function isFeedPath()
    {
        $path = strtolower((isOldTy()) ? Typecho_Router::getPathInfo() : Request::getInstance()->getPathInfo());

        return '/feed' == $path || strpos($path, "/feed/") !== false;
    }

    public static $isshow = false;

    //解析评论，如果是feed页面的评论需要过去私密评论
    public static function parse($text, $widget, $lastResult)
    {
        $text = empty($lastResult) ? $text : $lastResult;

        if (self::isFeedPath()) {
            // 判断评论所属独立页面是否加密
            $db = Typecho_Db::get();
            $value = $db->fetchObject($db->select('str_value')
                ->from('table.fields')->where('cid = ? and name = ?', $widget->cid, "password"));
            if ($value !== null) {
                $value = $value->str_value;
            }

            if ($value != "") {
                return _mt("[当前评论所属页面已加密]");
            }

            if (strpos($text, '[secret]') !== false) {
                return "[私密评论]";
            } else {
                return $text;
            }
        } else {
            return $text;
        }


    }


    /**
     * 选取置顶文章
     *
     * @access public
     * @param object $archive , $select
     * @param $select
     * @return void
     * @throws Typecho_Db_Exception
     * @throws Typecho_Exception
     */
    public static function sticky($archive, $select)
    {
        $config = Typecho_Widget::widget('Widget_Options')->plugin('Handsome');
        $sticky_cids = $config->sticky_cids ? explode(',', strtr($config->sticky_cids, ' ', ',')) : '';
        if (!$sticky_cids) return;

        $db = Typecho_Db::get();
        $paded = $archive->request->get('page', 1);
        $sticky_html = '<span class="label text-sm bg-danger pull-left m-t-xs m-r" style="margin-top:  2px;">' . _t("置顶") . '</span>';

        foreach ($sticky_cids as $cid) {
            if ($cid && $sticky_post = $db->fetchRow($archive->select()->where('cid = ?', $cid))) {
                if ($paded == 1) {                               // 首頁 page.1 才會有置頂文章
                    $sticky_post['sticky'] = $sticky_html;
                    $archive->push($sticky_post);                  // 選取置頂的文章先壓入
                }
                $select->where('table.contents.cid != ?', $cid); // 使文章不重覆
            }
        }
    }


    public static function CategoryCateFilter($archive, $select)
    {

        if (self::isFeedPath()) {
            //分类页面的feed流不显示加密分类的内容
            //判断当前分类mid是否是加密分类
            $LockIds = Typecho_Widget::widget('Widget_Options')->plugin('Handsome')->LockId;
            if (!$LockIds) return $select;       //没有写入值，则直接返回
            $select = $select->select('table.contents.cid', 'table.contents.title', 'table.contents.slug', 'table.contents.created', 'table.contents.authorId', 'table.contents.modified', 'table.contents.type', 'table.contents.status', 'table.contents.text', 'table.contents.commentsNum', 'table.contents.order', 'table.contents.template', 'table.contents.password', 'table.contents.allowComment', 'table.contents.allowPing', 'table.contents.allowFeed', 'table.contents.parent')->join('table.relationships', 'table.relationships.cid = table.contents.cid', 'left')->join('table.metas', 'table.relationships.mid = table.metas.mid', 'left')->where('table.metas.type=?', 'category');
            $LockIds = explode(',', $LockIds);
            $LockIds = array_unique($LockIds);  //去除重复值
            foreach ($LockIds as $k => $v) {
                if ($v == $archive->request->mid || $archive == intval($v)) {
                    throw new Typecho_Widget_Exception(_t('分类加密'), 404);
                }
                $select = $select->where('table.relationships.mid != ' . intval($v))->group('table.relationships.cid');//确保每个值都是数字；排除重复文章，由qqdie修复
            }
            return $select;
        } else {
            return $select;
        }
    }


    public static function CateFilter($archive, $select)
    {
        if (self::isFeedPath()) {
            //feed中不显示分类加密的内容
            $LockIds = Typecho_Widget::widget('Widget_Options')->plugin('Handsome')->LockId;
            if (!$LockIds) return $select;       //没有写入值，则直接返回
            $select = $select->select('table.contents.cid', 'table.contents.title', 'table.contents.slug', 'table.contents.created', 'table.contents.authorId', 'table.contents.modified', 'table.contents.type', 'table.contents.status', 'table.contents.text', 'table.contents.commentsNum', 'table.contents.order', 'table.contents.template', 'table.contents.password', 'table.contents.allowComment', 'table.contents.allowPing', 'table.contents.allowFeed', 'table.contents.parent')->join('table.relationships', 'table.relationships.cid = table.contents.cid', 'left')->join('table.metas', 'table.relationships.mid = table.metas.mid', 'left')->where('table.metas.type=?', 'category');
            $LockIds = explode(',', $LockIds);
            $LockIds = array_unique($LockIds);  //去除重复值
            foreach ($LockIds as $k => $v) {
                $select = $select->where('table.relationships.mid != ' . intval($v))->group('table.relationships.cid');//确保每个值都是数字；排除重复文章，由qqdie修复
            }
            return $select;
        } else {
            //首页不显示分类隐藏的内容
            $CateIds = Typecho_Widget::widget('Widget_Options')->plugin('Handsome')->CateId;
            if (!$CateIds) return $select;       //没有写入值，则直接返回
            $select = $select->select('table.contents.cid', 'table.contents.title', 'table.contents.slug', 'table.contents.created', 'table.contents.authorId', 'table.contents.modified', 'table.contents.type', 'table.contents.status', 'table.contents.text', 'table.contents.commentsNum', 'table.contents.order', 'table.contents.template', 'table.contents.password', 'table.contents.allowComment', 'table.contents.allowPing', 'table.contents.allowFeed', 'table.contents.parent')->join('table.relationships', 'table.relationships.cid = table.contents.cid', 'left')->join('table.metas', 'table.relationships.mid = table.metas.mid', 'left')->where('table.metas.type=?', 'category');
            $CateIds = explode(',', $CateIds);
            $CateIds = array_unique($CateIds);  //去除重复值
            foreach ($CateIds as $k => $v) {
                $select = $select->where('table.relationships.mid != ' . intval($v))->group('table.relationships.cid');//确保每个值都是数字；排除重复文章，由qqdie修复
            }
            return $select;
        }
    }


    public static function exceptFeed($con, $obj, $text)
    {
        $text = empty($text) ? $con : $text;
        if (!$obj->is('single')) {
            $text = preg_replace("/\[login\](.*?)\[\/login\]/sm", '', $text);
            $text = preg_replace("/\[hide\](.*?)\[\/hide\]/sm", '', $text);
            $text = preg_replace("/\[secret\](.*?)\[\/secret\]/sm", '', $text);
        }
        return $text;
    }

    public static function exceptFeedForDesc($con, $obj, $text)
    {
        $text = empty($text) ? $con : $text;
        if (!$obj->is('single')) {
            $text = preg_replace("/\[login\](.*?)\[\/login\]/sm", '', $text);
            $text = preg_replace("/\[hide\](.*?)\[\/hide\]/sm", '', $text);
            $text = preg_replace("/\[secret\](.*?)\[\/secret\]/sm", '', $text);
        }
        return $text;
    }

    public static function footer()
    {
        ?>


        <?php

    }


    /**
     * 插入编辑器
     */
    public static function VEditor($post)
    {
        $content = $post;
        $options = Helper::options();
        include 'assets/js/origin/editor.php';
        $meida_url = $options->adminUrl . 'media.php';

        ?>

        <script>
            var uploadURL = '<?php Helper::security()->index('/action/multi-upload?do=uploadfile&cid=CID'); ?>';
            var emojiPath = '<?php echo $options->pluginUrl; ?>';
            var meida_url = '<?php echo $meida_url ?>';
            var media_edit_url = '<?php Helper::security()->index('/action/contents-attachment-edit'); ?>';
        </script>

        <?php

    }


    public static function content($content, $obj)
    {
        //不再使用，为了保持旧版本升级不出现问题，暂时保留
        return $obj->isMarkdown ? $obj->markdown($content)
            : $obj->autoP($content);
    }

    public static function isPluginAvailable($className, $dirName)
    {
        if (class_exists($className)) {
            $plugins = Typecho_Plugin::export();
            $plugins = $plugins['activated'];
            if (is_array($plugins) && array_key_exists($dirName, $plugins)) {
                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }

    }

}

