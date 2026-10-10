<?php

function is_mobile()
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ($ua === '') return false;
    $mobiles = [
        'Mobile','Android','iPhone','iPad','iPod','webOS','BlackBerry',
        'IEMobile','Opera Mini','Opera Mobi','Windows Phone','Kindle','Silk',
        'Symbian','SymbianOS','PlayBook','BB10','Tablet','KFAPWI','KFOT',
        'MicroMessenger','MQQBrowser','UCBrowser','UCWEB','QQ/',
        'BaiduBrowser','baiduboxapp','MiuiBrowser','HuaweiBrowser',
        'SogouMobileBrowser','LieBaoFast','360Browser','AlipayClient',
        'DingTalk','MZBrowser','CoolPad','OppoBrowser','VivoBrowser',
        'Nokia','PlayStation','Nintendo','WAP',
    ];
    foreach ($mobiles as $m) {
        if (stripos($ua, $m) !== false) return true;
    }
    return false;
}

function flash_set($k, $v)
{
    $_SESSION['_flash'][$k] = $v;
}

function flash_get($k)
{
    $v = $_SESSION['_flash'][$k] ?? null;
    unset($_SESSION['_flash'][$k]);
    return $v;
}

function mask_key($key)
{
    $len = strlen($key);
    if ($len <= 12) return $key;
    return substr($key, 0, 8) . '········' . substr($key, -4);
}

// ═══ 歌词条字体（音乐配置）═══
// 字体有两种来源：外部 URL（lrc_font_url）与上传到存储的字体文件（lrc_font，存对象 key）。
// 两者不会同时生效：上传时清掉 URL，填 URL 时删掉上传的文件（见 handlers/lrc_font.php
// 与 handlers/config_user.php），否则「保存一下别的设置就把字体删了 / 用错了源」这类问题很难查。

/** 允许上传的字体扩展名 => MIME */
function lrc_font_types()
{
    return [
        'woff2' => 'font/woff2',
        'woff'  => 'font/woff',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
    ];
}

/** 老库缺列自动补（幂等），沿用 mapi_users_ensure_column */
function lrc_font_ensure_columns($db)
{
    mapi_users_ensure_column($db, 'lrc_font', "VARCHAR(500) DEFAULT ''");
    mapi_users_ensure_column($db, 'lrc_font_url', "VARCHAR(500) DEFAULT ''");
    mapi_users_ensure_column($db, 'lrc_font_name', "VARCHAR(100) DEFAULT ''");
    mapi_users_ensure_column($db, 'lrc_font_size', 'INT DEFAULT 0');
}

/**
 * 读取当前歌词条字体设置。
 * key=上传的对象 key；url=外部 URL；name=字体名；size=字号（0=默认）；
 * effective=真正生效的字体 URL（URL 优先，其次上传的文件）
 */
function lrc_font_get($db, $uid)
{
    $out = ['key' => '', 'url' => '', 'name' => '', 'size' => 0, 'effective' => ''];
    lrc_font_ensure_columns($db);
    try {
        $r = $db->query('SELECT lrc_font, lrc_font_url, lrc_font_name, lrc_font_size FROM mapi_users WHERE id=' . (int)$uid);
        if ($r && $row = $r->fetch_assoc()) {
            $out['key']  = trim((string)($row['lrc_font'] ?? ''));
            $out['url']  = trim((string)($row['lrc_font_url'] ?? ''));
            $out['name'] = trim((string)($row['lrc_font_name'] ?? ''));
            $out['size'] = (int)($row['lrc_font_size'] ?? 0);
        }
    } catch (Throwable $e) { /* 列不存在：当作没设置 */ }
    if ($out['url'] !== '') {
        $out['effective'] = $out['url'];
    } elseif ($out['key'] !== '' && s3_available()) {
        $out['effective'] = s3_get_url($out['key']);
    }
    return $out;
}

/** 字体名：只留下能安全写进 CSS 的字符 */
function lrc_font_clean_name($name)
{
    return trim((string)preg_replace('/[^A-Za-z0-9 _\-\x{4e00}-\x{9fa5}]/u', '', (string)$name));
}

/** 字体 URL：写进 CSS 的 url() 之前去掉会截断规则的字符 */
function lrc_font_clean_url($url)
{
    return (string)preg_replace('/[\\\\\'"()<>]/', '', (string)$url);
}

/**
 * 上传对象 key 的命名空间：<目录>/u<用户id>_…
 * 老写法只用「用户名净化后的串」（backgrounds/ 、fonts/ 两处都是）：
 * 非 ASCII 用户名会被整串替换成 _，两个这种账号会撞成同一个对象 key，互相覆盖、互相删除；
 * 而且 key 是客户端回传的，没有归属校验时可以把别人的对象登记到自己名下，再借「换/删」删掉它。
 */
function upload_key_prefix($dir, $uid)
{
    return $dir . '/u' . (int)$uid . '_';
}

/** 用户名 → 可写进对象 key 的安全片段（字符表与老写法一致，便于认领升级前上传的文件） */
function upload_safe_segment()
{
    $name = preg_replace('/[^a-zA-Z0-9_\x{4e00}-\x{9fa5}-]/u', '_', (string)($_SESSION['admin_user'] ?? ''));
    return function_exists('mb_substr') ? mb_substr($name, 0, 40, 'UTF-8') : substr($name, 0, 40);
}

/**
 * 生成一次上传用的对象 key：带随机串 —— 换文件时 URL 一定变，
 * 不会出现「同名同后缀 → 存储 URL 不变 → 浏览器/CDN 继续拿旧文件」的问题。
 */
function upload_new_key($dir, $uid, $ext)
{
    $safeName = upload_safe_segment();
    if ($safeName === '') $safeName = 'user';
    try {
        $token = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $token = substr(md5(uniqid('', true)), 0, 8);
    }
    $ext = (string)preg_replace('/[^a-z0-9]/', '', strtolower((string)$ext));
    return upload_key_prefix($dir, $uid) . $safeName . '-' . $token . '.' . $ext;
}

/**
 * 这个对象 key 是不是「当前用户自己的」——删除只允许落在自己的对象上。
 * 认两种：新版 <目录>/u<id>_…；老版 <目录>/<自己的用户名>.<后缀>（升级前上传的，仍允许本人换掉/清掉）。
 * 老版那条额外要求用户名不是「全是占位符」，免得撞名的人拿别人的老对象来删。
 */
function upload_key_is_own($key, $dir, $uid)
{
    $key = trim((string)$key);
    if ($key === '') return false;
    if (strpos($key, upload_key_prefix($dir, $uid)) === 0) return true;
    $safeName = upload_safe_segment();
    if ($safeName === '' || !preg_match('/[A-Za-z0-9\x{4e00}-\x{9fa5}]/u', $safeName)) return false;
    return (bool)preg_match('#^' . preg_quote($dir, '#') . '/' . preg_quote($safeName, '#') . '\.[A-Za-z0-9]{2,5}$#u', $key);
}

/** 歌词条字体的对象 key（fonts/ 目录下的同一套规则） */
function lrc_font_key_prefix($uid)
{
    return upload_key_prefix('fonts', $uid);
}

function lrc_font_new_key($uid, $ext)
{
    return upload_new_key('fonts', $uid, $ext);
}

function lrc_font_key_is_own($key, $uid)
{
    return upload_key_is_own($key, 'fonts', $uid);
}
