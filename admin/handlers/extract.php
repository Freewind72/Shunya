<?php
$sourceFile = $argv[1] ?? __DIR__ . '/../index.php';
$handlersDir = $argv[2] ?? __DIR__;
$lines = file($sourceFile);
if (!$lines) { echo "无法读取源文件\n"; exit(1); }

// 构建 action -> 起始行号 映射（正则匹配 if ($action === 'xxx'）
$actionStarts = [];
foreach ($lines as $i => $line) {
    if (preg_match("/^\s*if\s*\(\s*\\\$action\s*===?\s*'([a-z_-]+)'/", $line, $m)) {
        $actionStarts[$m[1]] = $i + 1; // 1-based
    }
}

// 已创建的文件
$skipActions = ['js-log','pusher-auth','pusher-online-users',
                'keys-create','keys-delete','playlist-create','playlist-delete',
                'playlist-update','playlist-update-cover','playlist-fetch-cover'];

// 域分组映射：多个action合并到一个handler文件
$domainMap = [
    'logout.php'      => ['logout'],
    'songs.php'       => ['song-add','song-remove'],
    'users_mgmt.php'  => ['user-delete','user-admin'],
    'background.php'  => ['bg-presign','bg-confirm','bg-url-save'],
    'profile.php'     => ['profile'],
    'config_user.php' => ['config'],
    'passkeys.php'    => ['pk-begin','pk-complete','pk-delete'],
    'settings.php'    => ['settings','settings-site','settings-mail','settings-security','settings-api','settings-storage'],
    'system.php'      => ['clear-logs'],
];

$actionToFile = [];
foreach ($domainMap as $file => $actions) {
    foreach ($actions as $a) $actionToFile[$a] = $file;
}

// 提取每个action的代码块:
$sortedActions = array_keys($actionStarts);
// 构建区间: 每个action的结束行是下一个action的起始行-1
$extracted = [];
$skipFromAction = []; // actions to skip already extracted

// 重新构建：使用 action 行作为边界
$actionLines = [];
foreach ($actionStarts as $action => $lineNum) {
    $actionLines[$lineNum] = $action;
}
ksort($actionLines);

$prevLine = 0;
$prevAction = null;
$blockStarts = [];

// 确定每个action的代码块结束位置：到下一个action开始前（不包括login page include后）
foreach ($actionLines as $lineNum => $action) {
    if ($prevAction !== null) {
        $blockStarts[$prevAction] = [$prevLine, $lineNum - 1];
    }
    $prevAction = $action;
    $prevLine = $lineNum;
}

// 最后一个action：到渲染部分前（跳过 include pages/login 和末尾的渲染）
$lastActionEnd = count($lines);
// 找末尾的渲染部分起始 (包含 logout 的代码在 login page 之前)
$excludeStart = null;
foreach ($lines as $i => $line) {
    if (preg_match("/^\s*\\\$tpl\s*=\s*/", $line) || preg_match("/^\s*if\s*\(\s*\\\$action\s*===?\s*''\s*/", $line)) {
        $excludeStart = $i + 1;
        break;
    }
}

if ($prevAction !== null) {
    $endLine = $excludeStart ? $excludeStart - 1 : $lastActionEnd;
    $blockStarts[$prevAction] = [$prevLine, $endLine];
}

// 生成 handler 文件
foreach ($domainMap as $handlerFile => $actions) {
    $output = "<?php\n\n";
    $output .= "// \$action_key 由路由层传入，值为实际的action名\n\n";
    
    foreach ($actions as $action) {
        $block = $blockStarts[$action] ?? null;
        if (!$block) { 
            echo "警告: 未找到 action '$action' 的代码块\n"; 
            continue; 
        }
        [$start, $end] = $block;
        
        // 提取代码块
        for ($l = $start; $l <= min($end, count($lines)); $l++) {
            $line = $lines[$l - 1]; // 0-indexed
            
            // 跳过原有的 if action === 'xxx' 条件行（用域检查替代）
            if ($l === $start) {
                // 用 action_key 变量检查替代原 action 变量
                $output .= "if (\$action_key === '$action') {\n";
                continue;
            }
            
            $output .= $line;
        }
    }
    
    $handlerPath = $handlersDir . '/' . $handlerFile;
    file_put_contents($handlerPath, $output);
    echo "创建: $handlerFile (" . count($actions) . " actions)\n";
}

echo "\n完成!\n";