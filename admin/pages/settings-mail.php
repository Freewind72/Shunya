<?php defined('MAPI_ADMIN') or die('禁止直接访问');
// settings-mail.php — 邮件（SMTP / 邮件模板）
if ((($_SESSION['admin_is_admin'] ?? 99) > 1)) { echo '<div class="card"><div class="empty">无权限</div></div>'; return; }

$smtp = ['host'=>'','port'=>465,'user'=>'','pass'=>'','encrypt'=>'ssl','from'=>'','name'=>''];
$r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='smtp'");
if ($r && $row = $r->fetch_assoc()) {
    $saved = json_decode($row['config_value'], true);
    if (is_array($saved)) $smtp = array_merge($smtp, $saved);
}

// 邮件模板列表 — 从新表加载
$mailTemplates = [];
if (!defined('DB_SQLITE')) {
    $db->query("CREATE TABLE IF NOT EXISTS `mapi_mail_templates` (`id` INT NOT NULL AUTO_INCREMENT, `name` VARCHAR(100) NOT NULL DEFAULT '', `subject` VARCHAR(200) NOT NULL DEFAULT '顺雅音乐 - 验证码邮件', `body` TEXT, `is_html` TINYINT NOT NULL DEFAULT 0, `is_default` TINYINT NOT NULL DEFAULT 0, `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} else {
    $db->query("CREATE TABLE IF NOT EXISTS mapi_mail_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL DEFAULT '', subject VARCHAR(200) NOT NULL DEFAULT '顺雅音乐 - 验证码邮件', body TEXT, is_html INTEGER NOT NULL DEFAULT 0, is_default INTEGER NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
}
$rt = $db->query("SELECT * FROM mapi_mail_templates ORDER BY is_default DESC, id ASC");
if ($rt) { while ($trow = $rt->fetch_assoc()) { $mailTemplates[] = $trow; } }
// 表为空则从旧 config 迁移
if (empty($mailTemplates)) {
    $rold = $db->query("SELECT config_value FROM mapi_config WHERE config_key='mail_template'");
    if ($rold && $oldrow = $rold->fetch_assoc()) {
        $tpl = json_decode($oldrow['config_value'], true);
        if (is_array($tpl)) {
            $nm = trim($tpl['name'] ?? '') ?: '默认模板';
            $sj = trim($tpl['subject'] ?? '') ?: '顺雅音乐 - 验证码邮件';
            $bd = $tpl['body'] ?? '';
            $ht = !empty($tpl['html']) ? 1 : 0;
            $ins = $db->prepare("INSERT INTO mapi_mail_templates (name, subject, body, is_html, is_default) VALUES (?, ?, ?, ?, 1)");
            if ($ins) { $ins->bind_param('sssi', $nm, $sj, $bd, $ht); $ins->execute(); }
            $mailTemplates[] = ['id' => $db->insert_id, 'name' => $nm, 'subject' => $sj, 'body' => $bd, 'is_html' => $ht, 'is_default' => 1];
        }
    }
}
?>
<?php if (($_SESSION['admin_is_admin'] ?? 99) === 0): ?>
<div class="section-header"><span class="section-header-inner"><?= svg('mail') ?> 邮件服务</span></div>
<div class="cards-grid">
<div class="card">
  <div class="card-header"><span class="card-title"><?= svg('srv') ?> 邮件服务</span></div>
  <form method="post" action="?action=settings-mail"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="form-group">
      <label class="form-label">SMTP 服务器地址</label>
      <input class="form-input" name="smtp_host" value="<?= htmlspecialchars($smtp['host']) ?>" placeholder="smtp.example.com">
    </div>
    <div class="form-row">
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">端口</label>
        <input class="form-input" name="smtp_port" value="<?= (int)$smtp['port'] ?>" placeholder="465">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">加密方式</label>
        <select class="form-input" name="smtp_encrypt">
          <option value="ssl"<?= $smtp['encrypt']==='ssl'?' selected':'' ?>>SSL</option>
          <option value="tls"<?= $smtp['encrypt']==='tls'?' selected':'' ?>>TLS</option>
          <option value="none"<?= $smtp['encrypt']==='none'?' selected':'' ?>>无</option>
        </select>
      </div>
    </div>
    <div class="form-group">
        <label class="form-label">SMTP 账号</label>
        <input class="form-input" name="smtp_user" value="<?= htmlspecialchars($smtp['user']) ?>" placeholder="user@example.com">
      </div>
      <div class="form-group">
        <label class="form-label">SMTP 密码</label>
        <input class="form-input" type="password" name="smtp_pass" value="<?= htmlspecialchars($smtp['pass']) ?>" placeholder="留空不修改">
      </div>
      <div class="form-group">
        <label class="form-label">发件人邮箱</label>
        <input class="form-input" name="smtp_from" value="<?= htmlspecialchars($smtp['from']) ?>" placeholder="noreply@example.com">
      </div>
      <div class="form-group">
        <label class="form-label">发件人名称</label>
        <input class="form-input" name="smtp_name" value="<?= htmlspecialchars($smtp['name']) ?>" placeholder="顺雅音乐">
      </div>
    <button class="btn btn-primary btn-block">保存</button>
  </form>
</div>

<div class="card">
  <div class="card-header"><span class="card-title"><?= svg('img') ?> 邮件模板</span></div>
  <div class="tpl-list-wrap" id="tplCardBody">
    <?php if (empty($mailTemplates)): ?>
    <div class="empty">暂无邮件模板</div>
    <?php else: ?>
    <div class="tpl-list-grid">
    <?php foreach ($mailTemplates as $tpl):
      $preview = strip_tags($tpl['body'] ?? ''); $preview = mb_strlen($preview) > 60 ? mb_substr($preview, 0, 60) . '…' : ($preview ?: '空正文');
      $isDef = !empty($tpl['is_default']);
    ?>
      <div class="tpl-item<?= $isDef ? ' tpl-item-default' : '' ?>" id="tplItem<?= (int)$tpl['id'] ?>"
           data-name="<?= htmlspecialchars($tpl['name']) ?>"
           data-subject="<?= htmlspecialchars($tpl['subject']) ?>"
           data-html="<?= !empty($tpl['is_html']) ? '1' : '0' ?>">
        <div class="tpl-item-top">
          <span class="tpl-item-name"><?= htmlspecialchars($tpl['name']) ?></span>
          <?php if ($isDef): ?><span class="tpl-badge-def">默认</span><?php endif; ?>
          <span class="tpl-badge-fmt"><?= !empty($tpl['is_html']) ? 'HTML' : '纯文本' ?></span>
        </div>
        <div class="tpl-item-subject">主题：<?= htmlspecialchars($tpl['subject'] ?: '未设置') ?></div>
        <div class="tpl-item-preview"><?= htmlspecialchars($preview) ?></div>
        <textarea class="tpl-body-data" style="display:none"><?= htmlspecialchars($tpl['body'] ?? '') ?></textarea>
        <div class="tpl-item-actions">
          <button class="btn btn-sm btn-outline" onclick="openMailTemplateModal(<?= (int)$tpl['id'] ?>)">编辑</button>
          <?php if (!$isDef): ?><button class="btn btn-sm btn-outline" onclick="setDefaultTemplate(<?= (int)$tpl['id'] ?>)">设默认</button><?php endif; ?>
          <button class="btn btn-sm btn-outline btn-danger-outline" onclick="deleteTemplate(<?= (int)$tpl['id'] ?>)">删除</button>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <button class="btn btn-primary btn-block tpl-edit-btn" onclick="openMailTemplateModal(0)">新建模板</button>
  </div>
</div>
</div>

<div class="tpl-modal" id="mailTemplateModal" style="display:none">
  <div class="tpl-modal-backdrop" onclick="closeMailTemplateModal()"></div>
  <div class="tpl-modal-panel">
    <div class="tpl-modal-header">
      <span class="tpl-modal-title" id="tplModalTitle">新建邮件模板</span>
      <button class="tpl-modal-close" onclick="closeMailTemplateModal()"><?= svg('close') ?></button>
    </div>
    <form class="tpl-modal-body" id="mailTemplateForm" data-modal="1" action="?action=settings-mail" method="post" onsubmit="return handleMailTemplateSubmit(event)">
      <input type="hidden" name="_mail_tpl_save" value="1">
      <input type="hidden" name="_mail_tpl_id" id="tplId" value="0">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <div class="form-row tpl-modal-row">
        <div class="form-group">
          <label class="form-label">模板名称</label>
          <input class="form-input" name="mail_tpl_name" id="tplName" placeholder="如：验证码邮件、欢迎邮件">
        </div>
        <div class="form-group">
          <label class="form-label">邮件主题</label>
          <input class="form-input" name="mail_tpl_subject" id="tplSubject" placeholder="顺雅音乐 - 验证码邮件">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">发送格式</label>
        <select name="mail_tpl_html" id="tplHtml" class="form-input">
          <option value="0">纯文本格式</option>
          <option value="1">HTML 格式（支持标签样式）</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">邮件正文（支持 HTML）</label>
        <textarea class="form-input tpl-body-editor" name="mail_tpl_body" id="tplBody" rows="12" placeholder="支持 HTML 格式，{code} 会被替换为验证码"></textarea>
        <small style="display:block;margin-top:6px;color:rgba(0,0,0,.38);font-size:12px"><code>{code}</code> 会被替换为实际验证码。勾选 HTML 模式后支持标签和样式。</small>
      </div>
    </form>
    <div class="tpl-modal-footer">
      <button type="button" class="btn btn-outline" onclick="closeMailTemplateModal()">取消</button>
      <button type="submit" class="btn btn-primary" form="mailTemplateForm">保存模板</button>
    </div>
  </div>
</div>
<?php endif; ?>
