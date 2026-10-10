<?php

$phpVersion   = PHP_VERSION;
// 必须 8.5：全项目多处使用 PHP 8.5 才引入的 Uri\Rfc3986\Uri（音乐 API 解析上游地址），
// 放行 8.4 会导致装完一碰音乐接口就 Class "Uri\Rfc3986\Uri" not found。
$phpOk        = version_compare(PHP_VERSION, '8.5.0', '>=');
$requiredExts = ['pdo', 'mbstring', 'openssl', 'fileinfo', 'gd', 'curl', 'xml'];
$extStatus    = [];

foreach ($requiredExts as $ext) {
    $extStatus[$ext] = extension_loaded($ext);
}

$allPass = $phpOk && !in_array(false, $extStatus, true);

return [
    'ok'         => true,
    'php_ok'     => $phpOk,
    'php_version'=> $phpVersion,
    'exts'       => $requiredExts,
    'ext_status' => $extStatus,
    'all_pass'   => $allPass,
];