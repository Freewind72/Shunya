<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

$installDir = dirname(__DIR__);
$root       = dirname($installDir);
$configFile = $root . '/config/config.php';
$lockFile   = $installDir . '/install.lock';

if (file_exists($configFile) && file_exists($lockFile)) {
    $lock  = file_get_contents($lockFile);
    $lines = [];

    foreach (explode("\n", $lock) as $line) {
        $line = trim($line);
        if ($line === '' || !str_contains($line, ': ')) {
            continue;
        }
        [$key, $val] = explode(': ', $line, 2);
        $lines[trim($key)] = trim($val);
    }

    $sha256File = $installDir . '/assets/sha256';
    $expectHash = file_exists($sha256File) ? trim(file_get_contents($sha256File)) : '';
    $hashOk      = $expectHash !== '' && $expectHash === hash_file('sha256', $lockFile);
    $signatureOk = isset($lines['signature']) && $lines['signature'] === 'msapi';

    if ($hashOk && $signatureOk) {
        /* 已安装 ⇒ 回根目录。
         * ⚠ 必须用「根绝对路径」而不是 `../`：`../` 是相对跳转，遇到
         *   /install/install/install/…（深层路径，每层都会命中本 guard）时，
         *   每跳一层就再 302 一次 `../`，浏览器逐层归一 —— 路径越长请求越多，
         *   实测会叠出几十层 /install/install/… 的连环 302（2026-10-20 用户报过）。
         *   绝对路径一次到位，无论请求路径多深都只跳一次。
         * 根的推导：installDir 相对 DOCUMENT_ROOT 的前缀。部署在 DOCUMENT_ROOT
         *   之外（nginx alias 等）时兜底 '/'。 */
        $__root = dirname($installDir);
        $__doc  = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/') : '';
        $__rootN = str_replace('\\', '/', $__root);
        $__base = '/';
        if ($__doc !== '' && str_starts_with($__rootN . '/', $__doc . '/')) {
            $__sub = substr($__rootN, strlen($__doc));
            $__base = rtrim($__sub, '/') . '/';
        }
        header('Location: ' . $__base);
        exit;
    }
}