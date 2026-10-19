<?php

// Pusher user-offline 上报已于 2026-10-16 随整体移除一并删除。
session_destroy();
header('Location: ' . admin_url('login'));
exit;