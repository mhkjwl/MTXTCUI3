<?php
declare(strict_types=1);
/**
 * API 对接文档页面
 *
 * @file        api_doc.php
 * @description 提供给第三方用户的 API 上传对接文档
 *              包含鉴权方式、接口说明、请求/响应格式、错误码、代码示例
 * @author      eecms
 * @version     1.3.0-dev
 * @date        2026-08-04
 * @see         docs/AI开发规范.md § 5.4（API 数据传输 / Header 鉴权）
 */

require __DIR__ . '/inc/common.php';

$siteName = isset($conf['site_name']) ? $conf['site_name'] : '图床系统';
$siteUrl  = isset($conf['site_url']) && $conf['site_url'] !== ''
    ? rtrim($conf['site_url'], '/')
    : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

// 图床接口配置（与 index.php / api_upload.php $apiConfig 一致）
$apiConfig = [
    'cfbed'=>['name'=>'自建图床','max_size'=>100],
    '360'=>['name'=>'360图床','max_size'=>10],
    'local'=>['name'=>'本地上传','max_size'=>10],
    'chevereto'=>['name'=>'PlcGO图床','max_size'=>10],
    'zhongzhuan'=>['name'=>'凌云图床','max_size'=>10],
    'phototourl'=>['name'=>'PHOTO图床','max_size'=>10],
    'imgloc'=>['name'=>'IMGLOC图床','max_size'=>10],
    'locimg'=>['name'=>'LOC图床','max_size'=>10],
    'jisu'=>['name'=>'极速图床','max_size'=>3],
    'yopngs'=>['name'=>'有图床','max_size'=>10],
    'feria'=>['name'=>'风筝图床','max_size'=>10],
    'gurl'=>['name'=>'Telegraph图床','max_size'=>5],
    'ljpic'=>['name'=>'云间图床','max_size'=>3],
    'nickyam'=>['name'=>'Telegraph2图床','max_size'=>5],
    'dogimg'=>['name'=>'狗狗图床','max_size'=>10],
    'matu'=>['name'=>'宝马图床','max_size'=>10],
    'pnglog'=>['name'=>'盘络图床','max_size'=>5],
    'lvse'=>['name'=>'绿色图床','max_size'=>5],
    'fatcat'=>['name'=>'肥喵图床','max_size'=>10],
    '131img'=>['name'=>'131图床','max_size'=>20],
    'feimg'=>['name'=>'FE图床','max_size'=>64],
    'yootn'=>['name'=>'友藤图床','max_size'=>20],
    'czl'=>['name'=>'CZL图床','max_size'=>0.5],
    'tutu'=>['name'=>'TUTU图床','max_size'=>5],
    'uuimg'=>['name'=>'悠悠图床','max_size'=>4997],
    'tuwu'=>['name'=>'图屋图床','max_size'=>20],
    'urusai'=>['name'=>'UR图床','max_size'=>64],
    'imgcc'=>['name'=>'云图床','max_size'=>10],
    'imgdata'=>['name'=>'ImgURL图床','max_size'=>10],
    'pngcdn'=>['name'=>'云朵图床','max_size'=>10],
    'naixiai'=>['name'=>'奶昔图床','max_size'=>10],
    'yiyunt'=>['name'=>'怡云图床','max_size'=>10],
    'scdn'=>['name'=>'SCDN图床','max_size'=>20],
    'imgbb'=>['name'=>'ImgBB图床','max_size'=>32],
    'imgurla'=>['name'=>'Imgur.LA图床','max_size'=>10],
    'helloimg'=>['name'=>'Hello图床','max_size'=>10],
    'stardots'=>['name'=>'StarDots图床','max_size'=>10],
    'remit'=>['name'=>'Remit图床','max_size'=>10],
    'alibaba'=>['name'=>'阿里巴巴图床','max_size'=>10],
    'beeimg'=>['name'=>'蜜蜂图床','max_size'=>10],
    'meituan'=>['name'=>'美团创作图床','max_size'=>10],
    'suning'=>['name'=>'苏宁易购图床','max_size'=>10],
    'meipai'=>['name'=>'美拍网图床','max_size'=>10],
    'alipay'=>['name'=>'支付宝图床','max_size'=>10],
    'youzan'=>['name'=>'有赞图床','max_size'=>10],
    'wentian'=>['name'=>'WENTIAN','max_size'=>5],
    'imgw'=>['name'=>'图网图床','max_size'=>30],
    'xwyue'=>['name'=>'星跃图床','max_size'=>20],
    'keye'=>['name'=>'珂艺云图床','max_size'=>100],
    'shaitu'=>['name'=>'晒图床','max_size'=>10],
    'guaigua'=>['name'=>'乖乖图床','max_size'=>10],
    'imgtolink'=>['name'=>'LINK图床','max_size'=>5],
];

// ========== 认证检测：Session 或 Bearer Token ==========
// API 文档页面根据当前查看者的身份（Session 登录或 Bearer API 密钥），
// 动态展示其套餐允许使用的图床接口列表（非展示全部接口）
$docUserId    = 0;
$docUserName  = '';
$docAuthMethod = ''; // 'session' / 'bearer' / ''
$docPkgName   = '';
$docPkgLevel  = 0;
$docPkgExpired = false;

// 优先检查 Bearer Token（第三方 API 用户通过 Header 鉴权查看文档）
$docAuthHeader = '';
if(isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $docAuthHeader = $_SERVER['HTTP_AUTHORIZATION'];
} elseif(isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $docAuthHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
} elseif(function_exists('getallheaders')) {
    $docHdrs = getallheaders();
    foreach($docHdrs as $docK => $docV) {
        if(strcasecmp($docK, 'Authorization') === 0) { $docAuthHeader = $docV; break; }
    }
}
if($docAuthHeader !== '' && preg_match('/^Bearer\s+(sk-[0-9a-f]{32})$/i', $docAuthHeader, $docM)) {
    $docKeyInfo = api_key_verify($DB, $docM[1]);
    if($docKeyInfo) {
        $docUserRow = $DB->get_row('SELECT * FROM eecms_users WHERE id = ' . intval($docKeyInfo['user_id']));
        if($docUserRow && (int)$docUserRow['status'] === 1) {
            $docUserId   = (int)$docUserRow['id'];
            $docUserName = $docUserRow['username'];
            $docAuthMethod = 'bearer';
        }
    }
}

// 回退到 Session 认证（网站登录用户浏览文档）
if($docUserId === 0 && isset($isUserLoggedIn) && $isUserLoggedIn && isset($currentUserId) && $currentUserId > 0) {
    $docUserId   = (int)$currentUserId;
    $docUserName = isset($currentUser['username']) ? $currentUser['username'] : '';
    $docAuthMethod = 'session';
}

// 获取用户套餐信息 + 可用接口列表（套餐过期自动回退默认套餐，接口同步更新）
$docAllowedApis = ['api_keys'=>[], 's3_ids'=>[], 'group_name'=>'', 'has_group'=>false];
if($docUserId > 0) {
    $docAllowedApis = get_user_allowed_apis($DB, $docUserId);
    $docPkgInfo = get_user_effective_package($DB, $docUserId);
    if($docPkgInfo) {
        $docPkgName    = $docPkgInfo['package_name'];
        $docPkgLevel   = (int)$docPkgInfo['level'];
        $docPkgExpired = $docPkgInfo['is_expired'];
    }
}

// 构建当前用户可见的图床接口列表（仅套餐允许 + 已开启的接口）
$docVisibleApis = [];  // [key => ['name'=>.., 'max_size'=>..]]
$docVisibleS3   = [];  // [s3Idx => ['name'=>.., 'max_size'=>..]]

if($docUserId > 0) {
    if(!empty($docAllowedApis['api_keys']) || !empty($docAllowedApis['s3_ids']) || !empty($docAllowedApis['has_group'])) {
        // 已绑定分组：仅显示分组内的已启用接口
        foreach($apiConfig as $docKey => $docInfo) {
            if(in_array($docKey, $docAllowedApis['api_keys'], true)) {
                $docVisibleApis[$docKey] = $docInfo;
            }
        }
        // S3 存储配置
        if(isset($conf['s3_storage_configs']) && $conf['s3_storage_configs'] !== '') {
            $docS3Configs = json_decode($conf['s3_storage_configs'], true);
            if(is_array($docS3Configs)) {
                foreach($docAllowedApis['s3_ids'] as $docS3Id) {
                    if(isset($docS3Configs[$docS3Id])) {
                        $docVisibleS3[$docS3Id] = $docS3Configs[$docS3Id];
                    }
                }
            }
        }
    } else {
        // 未配置分组：显示所有已启用的接口（can_user_use_api 在此场景放行全部已启用接口）
        foreach($apiConfig as $docKey => $docInfo) {
            if(is_api_enabled($conf, $docKey)) {
                $docVisibleApis[$docKey] = $docInfo;
            }
        }
        if(isset($conf['s3_storage_configs']) && $conf['s3_storage_configs'] !== '') {
            $docS3Configs = json_decode($conf['s3_storage_configs'], true);
            if(is_array($docS3Configs)) {
                foreach($docS3Configs as $docS3Idx => $docS3Cfg) {
                    if(isset($docS3Cfg['enabled']) && $docS3Cfg['enabled'] === '1') {
                        $docVisibleS3[$docS3Idx] = $docS3Cfg;
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>API 对接文档 - <?php echo htmlspecialchars($siteName);?></title>
  <link href="admin/style/css/materialdesignicons.min.css" rel="stylesheet">
  <link href="bd/qd.css" rel="stylesheet">
  <style>
    [data-theme="dark"]{--bg:#0d0b16;--panel:rgba(30,26,46,0.72);--panel-strong:rgba(30,26,46,0.92);--line:rgba(123,140,255,0.16);--text:#f0edf8;--muted:#8a85a0;--shadow:0 20px 60px rgba(0,0,0,0.3);}
    body{margin:0;background:var(--bg-base);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei","Helvetica Neue",Arial,sans-serif;color:var(--text);}
    .doc-shell{max-width:960px;margin:0 auto;padding:32px 24px 64px;}
    .doc-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:32px;flex-wrap:wrap;gap:12px;}
    .doc-header h1{font-size:1.6rem;font-weight:800;margin:0;}
    .doc-header .back-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;font-size:0.85rem;font-weight:600;text-decoration:none;background:var(--panel);color:var(--primary);border:1px solid var(--border-2);transition:all .2s;}
    .doc-header .back-btn:hover{background:var(--primary);color:#fff;}
    .doc-card{background:var(--panel);backdrop-filter:blur(20px);border:1px solid var(--border-2);border-radius:16px;padding:28px;margin-bottom:20px;box-shadow:var(--shadow);}
    .doc-card h2{font-size:1.15rem;font-weight:700;margin:0 0 16px;display:flex;align-items:center;gap:8px;}
    .doc-card h2 .mdi{color:var(--primary);}
    .doc-card h3{font-size:0.95rem;font-weight:600;margin:20px 0 10px;color:var(--text);}
    .doc-card p{font-size:0.88rem;line-height:1.7;color:var(--text-2);margin:8px 0;}
    .doc-card ul{margin:8px 0;padding-left:20px;}
    .doc-card li{font-size:0.86rem;line-height:1.8;color:var(--text-2);}
    .doc-card code{font-family:"SF Mono",Consolas,Monaco,Menlo,"Courier New",monospace;font-size:0.82rem;background:rgba(123,140,255,0.1);padding:2px 8px;border-radius:5px;color:var(--primary);}
    .code-block{background:#1a1825;border-radius:10px;padding:18px 20px;margin:12px 0;overflow-x:auto;position:relative;}
    .code-block pre{margin:0;font-family:"SF Mono",Consolas,Monaco,Menlo,"Courier New",monospace;font-size:0.8rem;line-height:1.65;color:#e0dce8;white-space:pre;}
    .code-block .copy-code{position:absolute;top:10px;right:10px;background:rgba(255,255,255,0.1);border:none;color:#aaa;padding:4px 10px;border-radius:6px;font-size:0.72rem;cursor:pointer;transition:all .2s;}
    .code-block .copy-code:hover{background:rgba(255,255,255,0.2);color:#fff;}
    .code-block .lang-label{position:absolute;top:10px;left:14px;font-size:0.68rem;color:#6a6580;text-transform:uppercase;letter-spacing:1px;font-weight:600;}
    .code-block pre{padding-top:22px;}
    .method-badge{display:inline-block;padding:3px 10px;border-radius:6px;font-size:0.72rem;font-weight:700;font-family:"SF Mono",Consolas,Monaco,Menlo,"Courier New",monospace;margin-right:8px;}
    .method-post{background:rgba(34,197,94,0.15);color:#22c55e;border:1px solid rgba(34,197,94,0.3);}
    .param-table{width:100%;border-collapse:collapse;margin:12px 0;font-size:0.84rem;}
    .param-table th{text-align:left;padding:10px 12px;background:rgba(123,140,255,0.08);border-bottom:2px solid var(--border-2);font-weight:600;color:var(--text);}
    .param-table td{padding:10px 12px;border-bottom:1px solid var(--border-2);color:var(--text-2);}
    .param-table td:first-child{font-family:"SF Mono",Consolas,Monaco,Menlo,"Courier New",monospace;font-size:0.8rem;color:var(--primary);}
    .param-table .req{color:#ef4444;font-weight:600;font-size:0.72rem;}
    .param-table .opt{color:var(--muted);font-size:0.72rem;}
    .error-table th{background:rgba(239,68,68,0.06);}
    .note-box{background:rgba(123,140,255,0.08);border-left:3px solid var(--primary);border-radius:0 8px 8px 0;padding:12px 16px;margin:12px 0;}
    .note-box p{margin:0;font-size:0.82rem;color:var(--text-2);}
    .warn-box{background:rgba(245,158,11,0.08);border-left:3px solid #f59e0b;border-radius:0 8px 8px 0;padding:12px 16px;margin:12px 0;}
    .warn-box p{margin:0;font-size:0.82rem;color:var(--text-2);}
    .toc{position:sticky;top:20px;}
    .toc a{display:block;padding:6px 14px;font-size:0.82rem;color:var(--text-2);text-decoration:none;border-radius:6px;transition:all .15s;border-left:2px solid transparent;}
    .toc a:hover{color:var(--primary);background:rgba(123,140,255,0.06);border-left-color:var(--primary);}
    .doc-layout{display:grid;grid-template-columns:180px 1fr;gap:24px;}
    @media(max-width:768px){.doc-layout{grid-template-columns:1fr;}.toc{display:none;}.doc-shell{padding:16px;}}
  </style>
</head>
<body>
  <div class="doc-shell">
    <div class="doc-header">
      <h1><i class="mdi mdi-file-document-outline" style="color:var(--primary)"></i> API 对接文档</h1>
      <a class="back-btn" href="index.php"><i class="mdi mdi-arrow-left"></i> 返回首页</a>
    </div>

    <div class="doc-layout">
      <!-- 目录 -->
      <aside class="toc">
        <a href="#overview">概览</a>
        <a href="#auth">鉴权方式</a>
        <a href="#upload">上传接口</a>
        <a href="#response">响应格式</a>
        <a href="#errors">错误码</a>
        <a href="#examples">代码示例</a>
        <a href="#notes">注意事项</a>
      </aside>

      <!-- 内容 -->
      <div>
        <!-- 概览 -->
        <section class="doc-card" id="overview">
          <h2><i class="mdi mdi-information-outline"></i> 概览</h2>
          <p><?php echo htmlspecialchars($siteName);?> 提供基于 HTTP 的图片上传 API，允许第三方应用通过编程方式上传图片到本站托管的图床。</p>
          <ul>
            <li><strong>Base URL</strong>：<code><?php echo htmlspecialchars($siteUrl);?>/api/api_upload.php</code></li>
            <li><strong>请求方式</strong>：POST 上传图片（<code>multipart/form-data</code>）/ GET 查询可用接口</li>
            <li><strong>鉴权方式</strong>：Bearer Token（API 密钥）</li>
            <li><strong>返回格式</strong>：JSON</li>
            <li><strong>字符编码</strong>：UTF-8</li>
          </ul>
          <div class="note-box">
            <p><i class="mdi mdi-lightbulb-on-outline" style="color:var(--primary)"></i> 使用 API 前需先在「个人中心 - API 密钥管理」创建密钥，密钥格式为 <code>sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx</code>。上传时需同时提供 API 密钥和图床接口标识（<code>api</code> 参数）。可用的图床接口取决于您的套餐所绑定的接口分组，套餐到期后接口列表会自动同步更新。</p>
          </div>
        </section>

        <!-- 鉴权方式 -->
        <section class="doc-card" id="auth">
          <h2><i class="mdi mdi-shield-key-outline"></i> 鉴权方式</h2>
          <p>所有 API 请求必须携带 API 密钥，通过 HTTP Header 传递：</p>
          <div class="code-block">
            <span class="lang-label">Header</span>
            <button class="copy-code" onclick="copyCode(this)">复制</button>
            <pre>Authorization: Bearer sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx</pre>
          </div>
          <h3>获取 API 密钥</h3>
          <ul>
            <li>登录个人中心 → API 密钥管理 → 点击「创建密钥」</li>
            <li>密钥明文仅在创建时展示一次，请妥善保存</li>
            <li>数据库仅存储 SHA-256 哈希，无法反查明文</li>
            <li>每个用户最多可创建 20 个密钥</li>
            <li>密钥可随时启用/禁用/删除/重新生成</li>
          </ul>
          <div class="warn-box">
            <p><i class="mdi mdi-alert-outline" style="color:#f59e0b"></i> 密钥泄露后请立即在个人中心重新生成或禁用，旧密钥将立即失效。</p>
          </div>
        </section>

        <!-- 上传接口 -->
        <section class="doc-card" id="upload">
          <h2><i class="mdi mdi-cloud-upload-outline"></i> 上传接口</h2>

          <h3>查询可用接口（GET）</h3>
          <p><span class="method-badge method-post" style="background:rgba(59,130,246,0.15);color:#3b82f6;border-color:rgba(59,130,246,0.3);">GET</span> <code>/api/api_upload.php</code></p>
          <p>上传前可通过 GET 请求查询当前用户可用的图床接口列表，获取每个接口的唯一标识（<code>id</code>），用于 POST 上传时传 <code>api</code> 参数。</p>
          <div class="code-block">
            <span class="lang-label">bash</span>
            <button class="copy-code" onclick="copyCode(this)">复制</button>
            <pre>curl <?php echo htmlspecialchars($siteUrl);?>/api/api_upload.php \
  -H "Authorization: Bearer sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"</pre>
          </div>
          <p>响应示例：</p>
          <div class="code-block">
            <span class="lang-label">JSON</span>
            <button class="copy-code" onclick="copyCode(this)">复制</button>
            <pre>{
  "code": 200,
  "msg": "获取成功",
  "data": {
    "interfaces": [
      { "id": "cfbed", "name": "自建图床", "max_size": 104857600 },
      { "id": "360", "name": "360图床", "max_size": 10485760 },
      { "id": "s3_0", "name": "我的S3存储", "max_size": 52428800, "s3_id": 0 }
    ]
  }
}</pre>
          </div>

          <h3>上传图片（POST）</h3>
          <p><span class="method-badge method-post">POST</span> <code>/api/api_upload.php</code></p>
          <h3>请求头</h3>
          <table class="param-table">
            <thead><tr><th>Header</th><th>必填</th><th>说明</th></tr></thead>
            <tbody>
              <tr><td>Authorization</td><td><span class="req">是</span></td><td>Bearer Token，格式 <code>Bearer sk-xxx</code></td></tr>
              <tr><td>Content-Type</td><td>自动</td><td>使用 FormData 时浏览器自动设置</td></tr>
            </tbody>
          </table>
          <h3>请求参数（multipart/form-data）</h3>
          <table class="param-table">
            <thead><tr><th>参数</th><th>必填</th><th>说明</th></tr></thead>
            <tbody>
              <tr><td>file</td><td><span class="req">是</span></td><td>图片文件，支持 GIF/JPEG/PNG/WEBP/BMP</td></tr>
              <tr><td>api</td><td><span class="req">是*</span></td><td>图床接口标识（字符串），如 <code>cfbed</code>、<code>360</code>、<code>local</code>。使用 S3 时无需传此参数</td></tr>
              <tr><td>s3_id</td><td><span class="opt">否</span></td><td>S3 存储配置 ID（数字）。使用 S3 存储时传此参数代替 <code>api</code></td></tr>
            </tbody>
          </table>
          <p style="font-size:0.78rem;color:var(--muted);">* <code>api</code> 和 <code>s3_id</code> 二选一，必须传其中一个。</p>

          <h3>您的可用图床接口</h3>
          <p style="font-size:0.8rem;">以下是根据您当前套餐所绑定的接口分组、已开启的图床接口列表。套餐到期后可用接口会自动同步更新。调用 GET 接口可获取相同的列表用于程序对接。</p>
          <?php if($docUserId > 0): ?>
            <div class="note-box" style="background:rgba(34,197,94,0.08);border-left-color:#22c55e;">
              <p><i class="mdi mdi-account-check" style="color:#22c55e"></i> 当前身份：<strong><?php echo htmlspecialchars($docUserName);?></strong>
              <?php if($docPkgName !== ''):?> | 套餐：<strong><?php echo htmlspecialchars($docPkgName);?></strong><?php endif;?>
              <?php if($docPkgExpired):?> <span style="color:#ef4444;">（已过期，已回退默认套餐）</span><?php endif;?>
              | 认证方式：<code><?php echo $docAuthMethod === 'bearer' ? 'API 密钥' : 'Session 登录';?></code></p>
            </div>
            <?php if(!empty($docVisibleApis) || !empty($docVisibleS3)): ?>
            <table class="param-table">
              <thead><tr><th>标识</th><th>名称</th><th>状态</th><th>单文件限制</th></tr></thead>
              <tbody>
                <?php foreach($docVisibleApis as $key => $info): ?>
                <tr>
                  <td><?php echo htmlspecialchars((string)$key);?></td>
                  <td><?php echo htmlspecialchars((string)$info['name']);?></td>
                  <td><span style="color:#22c55e;font-weight:600;">可用</span></td>
                  <td><?php echo $info['max_size'];?>MB</td>
                </tr>
                <?php endforeach;?>
                <?php foreach($docVisibleS3 as $s3Idx => $s3Cfg): ?>
                <tr>
                  <td>s3_<?php echo (int)$s3Idx;?></td>
                  <td><?php echo isset($s3Cfg['name']) ? htmlspecialchars((string)$s3Cfg['name']) : 'S3存储#'.(int)$s3Idx;?></td>
                  <td><span style="color:#22c55e;font-weight:600;">可用</span></td>
                  <td><?php echo isset($s3Cfg['max_size']) ? (int)$s3Cfg['max_size'] : 0;?>MB</td>
                </tr>
                <?php endforeach;?>
              </tbody>
            </table>
            <?php else: ?>
            <div class="warn-box">
              <p><i class="mdi mdi-alert-outline" style="color:#f59e0b"></i> 当前套餐未绑定任何可用图床接口。请升级套餐或联系管理员。</p>
            </div>
            <?php endif;?>
          <?php else: ?>
            <div class="warn-box">
              <p><i class="mdi mdi-lock-outline" style="color:#f59e0b"></i> 请先<a href="user/login.php" style="color:var(--primary);font-weight:600;">登录</a>或在请求头中携带 API 密钥（<code>Authorization: Bearer sk-xxx</code>）以查看您当前套餐可用的图床接口列表。</p>
            </div>
          <?php endif;?>
          <div class="note-box">
            <p><i class="mdi mdi-lightbulb-on-outline" style="color:var(--primary)"></i> 可用接口取决于您的套餐所绑定的接口分组。上传时若图床接口处于关闭状态，服务端将返回 <code>403 图床接口已关闭</code>。请通过 GET 请求确认接口可用性后再上传。</p>
          </div>
        </section>

        <!-- 响应格式 -->
        <section class="doc-card" id="response">
          <h2><i class="mdi mdi-code-json"></i> 响应格式</h2>
          <p>所有响应均为 JSON 格式，统一结构如下：</p>
          <div class="code-block">
            <span class="lang-label">JSON</span>
            <button class="copy-code" onclick="copyCode(this)">复制</button>
            <pre>{
  "code": 200,
  "msg": "上传成功",
  "data": {
    "url": "https://example.com/image.png",
    "filename": "photo.png",
    "size": 102400,
    "api_type": "360"
  }
}</pre>
          </div>
          <h3>字段说明</h3>
          <table class="param-table">
            <thead><tr><th>字段</th><th>类型</th><th>说明</th></tr></thead>
            <tbody>
              <tr><td>code</td><td>int</td><td>状态码，200 表示成功</td></tr>
              <tr><td>msg</td><td>string</td><td>状态描述信息</td></tr>
              <tr><td>data.url</td><td>string</td><td>上传后的图片访问地址（成功时返回）</td></tr>
              <tr><td>data.filename</td><td>string</td><td>原始文件名</td></tr>
              <tr><td>data.size</td><td>int</td><td>文件大小（字节）</td></tr>
              <tr><td>data.api_type</td><td>string</td><td>使用的图床类型标识</td></tr>
            </tbody>
          </table>
        </section>

        <!-- 错误码 -->
        <section class="doc-card" id="errors">
          <h2><i class="mdi mdi-alert-circle-outline"></i> 错误码</h2>
          <table class="param-table error-table">
            <thead><tr><th>HTTP 状态码</th><th>code</th><th>说明</th></tr></thead>
            <tbody>
              <tr><td>200</td><td>200</td><td>上传成功</td></tr>
              <tr><td>400</td><td>400</td><td>请求参数错误（缺少 file / api 接口标识 / 文件类型不支持）</td></tr>
              <tr><td>401</td><td>401</td><td>鉴权失败（未提供密钥 / 密钥格式错误 / 密钥无效或已禁用）</td></tr>
              <tr><td>403</td><td>403</td><td>禁止访问（账户被禁用 / 图床接口已关闭 / 套餐无权使用此接口）</td></tr>
              <tr><td>405</td><td>405</td><td>请求方法不允许（仅支持 POST / GET）</td></tr>
              <tr><td>413</td><td>413</td><td>存储空间不足，超出配额限制</td></tr>
              <tr><td>500</td><td>500</td><td>服务端错误（图床上游返回失败）</td></tr>
            </tbody>
          </table>
          <h3>错误响应示例</h3>
          <div class="code-block">
            <span class="lang-label">JSON</span>
            <button class="copy-code" onclick="copyCode(this)">复制</button>
            <pre>{
  "code": 401,
  "msg": "API 密钥无效或已被禁用"
}</pre>
          </div>
        </section>

        <!-- 代码示例 -->
        <section class="doc-card" id="examples">
          <h2><i class="mdi mdi-code-tags"></i> 代码示例</h2>

          <h3>cURL</h3>
          <div class="code-block">
            <span class="lang-label">bash</span>
            <button class="copy-code" onclick="copyCode(this)">复制</button>
            <pre>curl -X POST <?php echo htmlspecialchars($siteUrl);?>/api/api_upload.php \
  -H "Authorization: Bearer sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" \
  -F "file=@/path/to/image.png" \
  -F "api=360"</pre>
          </div>

          <h3>PHP</h3>
          <div class="code-block">
            <span class="lang-label">php</span>
            <button class="copy-code" onclick="copyCode(this)">复制</button>
            <pre>&lt;?php
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, '<?php echo htmlspecialchars($siteUrl);?>/api/api_upload.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'file' => new CURLFile('/path/to/image.png'),
    'api'  => '360',
]);
$response = curl_exec($ch);
unset($ch);

$result = json_decode($response, true);
if ($result['code'] === 200) {
    echo '图片地址：' . $result['data']['url'];
} else {
    echo '上传失败：' . $result['msg'];
}</pre>
          </div>

          <h3>Python</h3>
          <div class="code-block">
            <span class="lang-label">python</span>
            <button class="copy-code" onclick="copyCode(this)">复制</button>
            <pre>import requests

url = '<?php echo htmlspecialchars($siteUrl);?>/api/api_upload.php'
headers = {'Authorization': 'Bearer sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'}
files = {'file': open('/path/to/image.png', 'rb')}
data = {'api': '360'}

resp = requests.post(url, headers=headers, files=files, data=data)
result = resp.json()

if result['code'] == 200:
    print('图片地址：', result['data']['url'])
else:
    print('上传失败：', result['msg'])</pre>
          </div>

          <h3>JavaScript (Fetch)</h3>
          <div class="code-block">
            <span class="lang-label">javascript</span>
            <button class="copy-code" onclick="copyCode(this)">复制</button>
            <pre>const formData = new FormData();
formData.append('file', fileInput.files[0]);
formData.append('api', '360');

const resp = await fetch('<?php echo htmlspecialchars($siteUrl);?>/api/api_upload.php', {
  method: 'POST',
  headers: { 'Authorization': 'Bearer sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' },
  body: formData,
});

const result = await resp.json();
if (result.code === 200) {
  console.log('图片地址：', result.data.url);
} else {
  console.error('上传失败：', result.msg);
}</pre>
          </div>

          <h3>JavaScript (axios)</h3>
          <div class="code-block">
            <span class="lang-label">javascript</span>
            <button class="copy-code" onclick="copyCode(this)">复制</button>
            <pre>import axios from 'axios';

const formData = new FormData();
formData.append('file', file);
formData.append('api', '360');

const { data: result } = await axios.post(
  '<?php echo htmlspecialchars($siteUrl);?>/api/api_upload.php',
  formData,
  { headers: { 'Authorization': 'Bearer sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' } }
);

if (result.code === 200) {
  console.log('图片地址：', result.data.url);
} else {
  console.error('上传失败：', result.msg);
}</pre>
          </div>
        </section>

        <!-- 注意事项 -->
        <section class="doc-card" id="notes">
          <h2><i class="mdi mdi-flag-outline"></i> 注意事项</h2>
          <ul>
            <li><strong>密钥安全</strong>：切勿将密钥硬编码到前端代码或公开仓库中，建议通过环境变量管理</li>
            <li><strong>接口标识</strong>：<code>api</code> 参数使用字符串标识（如 <code>cfbed</code>、<code>360</code>），非数字索引。完整列表见上方「您的可用图床接口」或调用 GET 接口查询</li>
            <li><strong>套餐绑定</strong>：可用的图床接口由您的套餐所绑定的接口分组决定，并非所有已开启的接口都可使用。套餐到期后接口列表会自动同步更新为默认套餐的接口</li>
            <li><strong>接口开关</strong>：上传前检测图床接口是否已开启，关闭状态返回 <code>403 图床接口已关闭</code>。建议先调用 GET 接口确认可用性</li>
            <li><strong>文件限制</strong>：仅支持真实图片文件（通过服务端 getimagesize 校验），禁止上传可执行文件</li>
            <li><strong>文件重命名</strong>：服务端会强制重写文件名（随机字符 + 安全后缀），杜绝伪造扩展名</li>
            <li><strong>套餐限制</strong>：API 上传受用户套餐约束，无权使用的图床接口会返回 <code>403 当前套餐无权使用此上传接口</code></li>
            <li><strong>存储配额</strong>：上传文件大小计入用户存储配额，超额返回 413</li>
            <li><strong>CORS</strong>：API 支持跨域调用，可直接在浏览器端使用</li>
            <li><strong>并发限制</strong>：建议控制并发数在 5 以内，避免触发上游图床限流</li>
            <li><strong>密钥管理</strong>：密钥可在个人中心随时创建/查看/重新生成/禁用/删除</li>
          </ul>
          <div class="warn-box">
            <p><i class="mdi mdi-alert-outline" style="color:#f59e0b"></i> 滥用 API（大量无效请求、恶意上传等）可能导致密钥被禁用或账户被封禁。</p>
          </div>
        </section>
      </div>
    </div>
  </div>

  <script>
  function copyCode(btn) {
    var pre = btn.parentElement.querySelector('pre');
    if (!pre) return;
    var text = pre.textContent;
    navigator.clipboard.writeText(text).then(function() {
      btn.textContent = '已复制';
      setTimeout(function() { btn.textContent = '复制'; }, 1500);
    }).catch(function() {
      btn.textContent = '复制失败';
      setTimeout(function() { btn.textContent = '复制'; }, 1500);
    });
  }
  </script>
</body>
</html>
