<?php
/**
 * @file api.php
 * @description API接口设置页面，管理各图床接口的启用/禁用、配置凭据、批量操作及默认接口选择，含敏感字段加密存储
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

ini_set("display_errors", 0);
require('../inc/lang.php');
require('../inc/common.php');
require('../inc/icon.php');
if(!isset($islogin) || $islogin!=1) exit('<script>parent.location.href="login.php";</script>');

// 敏感字段白名单（禁止通过本页保存）
$sensitiveFields = ['admin_user', 'admin_pwd', 'security_key', 'smtp_pass', 's3_secret_key', 's3_storage_configs'];

// 各图床默认单文件大小限制（MB），后台留空时前端使用此值
// 统一配置源：从 get_api_config() 派生，避免与接口列表不同步
$apiMaxSizes = array_map(function($cfg) { return $cfg['max_size']; }, get_api_config());

/**
 * 接口定义表：每个图床接口的元数据与字段配置。
 * - name: 显示名称
 * - icon: mdi 图标类
 * - info: 提示信息（HTML 允许），为空则不显示 alert
 * - default_enable: 未配置时的默认启用状态（'1'=启用, '0'=禁用）
 * - fields: 该接口特有的配置字段（除公共的 enable/alias/maxsize 外）
 *     每个字段：name(后缀), label, type(text/password/number/textarea/select), placeholder, col(栅格宽,默认12), height(textarea行高px), options(select选项 value=>label), required, disabled(只读说明)
 */
$apiInterfaces = array(
    'local'=>array('name'=>'本地上传','icon'=>'mdi-monitor-screenshot','info'=>'','default_enable'=>'1','fields'=>array()),
    '360'=>array('name'=>'360图床','icon'=>'mdi-cloud-upload','info'=>'登录 <a href="https://dev.360.cn" target="_blank">360开发者平台</a> 获取 Cookie。','default_enable'=>'0','fields'=>array(
        array('name'=>'cookie','label'=>'360 Cookie','type'=>'textarea','height'=>120,'placeholder'=>'粘贴 360 开发者平台的 Cookie'),
    )),
    'cfbed'=>array('name'=>'自建图床','icon'=>'mdi-cloud-braces','info'=>'此图床需要自行使用 CloudFlare Pages 部署 <strong><a href="https://github.com/MarSeventh/CloudFlare-ImgBed" target="_blank">CloudFlare ImgBed</a></strong> 项目，在管理后台的 "系统设置" → "安全设置" → "API Token管理" 中获取 API Token 即可对接。部署教程：<a href="https://cfbed.sanyue.de/deployment/pages.html" target="_blank">点击查看</a>。','default_enable'=>'0','fields'=>array(
        array('name'=>'url','label'=>'API 链接','type'=>'text','required'=>true,'col'=>6,'placeholder'=>'https://your-domain.com（不含 /upload 路径）'),
        array('name'=>'token','label'=>'API Token','type'=>'password','required'=>true,'col'=>6,'placeholder'=>'在 CloudFlare ImgBed 后台生成的 API Token'),
    )),
    'scdn'=>array('name'=>'SCDN图床','icon'=>'mdi-flash-outline','info'=>'<strong>注意：该图床服务器位于海外，但是上传时自动获取CDN，国内也基本秒传。</strong>服务器端通过 <code>img.scdn.io</code> 中转，支持图片+短视频（mp4/webm/mov/avi），单张限制约 20MB。','default_enable'=>'0','fields'=>array(
        array('name'=>'cdn','label'=>'CDN 域名','type'=>'select','col'=>4,'options'=>array(''=>'系统自动选择','img.scdn.io'=>'失控的防御系统-海外','cloudflareimg.cdn.sn'=>'CloudFlare-海外','edgeoneimg.cdn.sn'=>'EdgeOne-海外','esaimg.cdn1.vip'=>'ESA-大陆','edgeoneimg.cdn1.vip'=>'EdgeOne-大陆')),
        array('name'=>'format','label'=>'输出格式','type'=>'select','col'=>4,'options'=>array(''=>'auto（自动）','jpg'=>'JPG','png'=>'PNG','webp'=>'WebP 静态','gif'=>'GIF','webp_animated'=>'WebP 动态')),
        array('name'=>'storage','label'=>'存储节点（可选）','type'=>'text','col'=>4,'placeholder'=>'留空则自动选择'),
    )),
    'alibaba'=>array('name'=>'阿里巴巴图床','icon'=>'mdi-shopping-outline','info'=>'阿里巴巴图床无需Key，图片存储于阿里云 OSS，限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'基于ffapi.cn，存储于阿里云OSS，无需Key','col'=>6),
    )),
    'remit'=>array('name'=>'Remit图床','icon'=>'mdi-image-outline','info'=>'Remit 图床支持CORS，无需Key，速率限制 <strong>10次/分钟</strong>，单张限制约 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'支持CORS，10次/分钟，无需Key','col'=>6),
    )),
    'zhongzhuan'=>array('name'=>'凌云图床','icon'=>'mdi-server','info'=>'凌云图床无需API Key，单张限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'凌云图床，无需Key，链接长期有效','col'=>6),
    )),
    'phototourl'=>array('name'=>'PHOTO图床','icon'=>'mdi-camera-outline','info'=>'PHOTO图床免注册匿名上传，每天 <strong>10次</strong>，单文件限制 <strong>2MB</strong>。图片存储于 Cloudflare R2。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'匿名上传，存储于Cloudflare R2','col'=>6),
    )),
    'imgloc'=>array('name'=>'IMGLOC图床','icon'=>'mdi-image-outline','info'=>'IMGLOC图床免注册匿名上传，两步验证（获取Token→上传），Token 有效期 1 小时。限制 <strong>60张/每小时</strong>，单文件限制 <strong>10MB</strong>，所有图片默认保存 1 年。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'两步验证，60张/每小时','col'=>6),
    )),
    'locimg'=>array('name'=>'LOC图床','icon'=>'mdi-map-marker-outline','info'=>'LOC图床免注册匿名上传，无需 Token/Cookie。最大可上传 <strong>100.00 MB</strong> 的图片。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'无需Token，匿名上传','col'=>6),
    )),
    'jisu'=>array('name'=>'极速图床','icon'=>'mdi-speedometer','info'=>'极速图床免注册匿名上传，无需 Token/Cookie。最大可上传 <strong>2.91 MB</strong> 的图片。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'无需Token，匿名上传，最大2.91MB','col'=>6),
    )),
    'yopngs'=>array('name'=>'有图床','icon'=>'mdi-image-outline','info'=>'有图床免注册匿名上传，使用 Backblaze 存储节点，支持 CORS。单文件限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'匿名上传，Backblaze存储，支持CORS','col'=>6),
    )),
    'feria'=>array('name'=>'风筝图床','icon'=>'mdi-kite-outline','info'=>'风筝图床无需注册，直接上传原始图片二进制数据，支持 CORS 跨域。单文件限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'原始二进制上传，支持CORS，无需Token','col'=>6),
    )),
    'gurl'=>array('name'=>'Telegraph图床','icon'=>'mdi-telegraph','info'=>'Telegraph图床基于 Telegraph 程序，免注册匿名上传，支持 CORS。单文件限制 <strong>5MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'Telegraph图床，匿名上传，支持CORS，5MB限制','col'=>6),
    )),
    'ljpic'=>array('name'=>'云间图床','icon'=>'mdi-weather-cloudy','info'=>'云间图床基于 Lsky Pro 程序，免注册匿名上传。单文件限制 <strong>3.00 MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'Lsky Pro程序，匿名上传，3MB限制','col'=>6),
    )),
    'nickyam'=>array('name'=>'Telegraph2图床','icon'=>'mdi-telegraph','info'=>'Telegraph2图床基于 Telegraph 程序，免注册匿名上传，支持 CORS。单文件限制 <strong>5MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'Telegraph图床2号，匿名上传，支持CORS，5MB限制','col'=>6),
    )),
    'dogimg'=>array('name'=>'狗狗图床','icon'=>'mdi-dog','info'=>'狗狗图床基于 Imgur API，免注册匿名上传，支持 CORS，速率限制 1250次/分钟。单文件限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'基于Imgur API，支持CORS，1250次/分钟','col'=>6),
    )),
    'matu'=>array('name'=>'宝马图床','icon'=>'mdi-car-sports','info'=>'宝马图床免注册匿名上传，需动态生成 uuid 和 sign 签名参数。单文件限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'动态签名验证，匿名上传，10MB限制','col'=>6),
    )),
    'pnglog'=>array('name'=>'盘络图床','icon'=>'mdi-image-outline','info'=>'盘络图床基于兰空图床（Lsky Pro），匿名上传，图片存储于 <code>cdn-us.imgs.moe</code>。单文件限制 <strong>5MB</strong>。注意：该站点有 Cloudflare 防护，若遇到 403 可能需在服务器同网络环境浏览器中访问获取 cf_clearance Cookie。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'兰空图床匿名上传，cdn-us.imgs.moe存储，5MB限制','col'=>6),
    )),
    'lvse'=>array('name'=>'绿色图床','icon'=>'mdi-leaf','info'=>'绿色图床免注册匿名上传，支持 CORS 跨域。直链为国外服务器，<strong>国内访问不了</strong>。单文件限制 <strong>5MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'国外直链，国内无法访问，支持CORS，5MB限制','col'=>6),
    )),
    'fatcat'=>array('name'=>'肥喵图床','icon'=>'mdi-cat','info'=>'肥喵图床免注册匿名上传，需动态生成 uuid 和 sign（Unix 时间戳）。单文件限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'动态签名验证，匿名上传，10MB限制','col'=>6),
    )),
    '131img'=>array('name'=>'131图床','icon'=>'mdi-image-multiple-outline','info'=>'131图床免注册上传，无需 Token/Cookie。单文件限制 <strong>20MB</strong>，支持 JPG/PNG/GIF/WEBP。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'免注册上传，无需Token，20MB限制','col'=>6),
    )),
    'feimg'=>array('name'=>'FE图床','icon'=>'mdi-cloud-upload-outline','info'=>'FE图床基于 freeimage.host（Chevereto V4 程序）。单文件限制 <strong>64MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'免注册上传，无需Token，64MB限制','col'=>6),
    )),
    'yootn'=>array('name'=>'友藤图床','icon'=>'mdi-vector-triangle','info'=>'友藤图床免注册上传，无需 Token/Cookie。单文件限制 <strong>20MB</strong>，支持 JPG/PNG/GIF/WEBP。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'免注册上传，无需Token，20MB限制','col'=>6),
    )),
    'czl'=>array('name'=>'CZL图床','icon'=>'mdi-image-outline','info'=>'CZL图床基于 Lsky Pro 程序，免注册匿名上传，自动获取 CSRF Token 和 Session。最大可上传 <strong>512KB</strong> 的图片。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'Lsky Pro程序，自动CSRF验证，512KB限制','col'=>6),
    )),
    'tutu'=>array('name'=>'TUTU图床','icon'=>'mdi-image-multiple-outline','info'=>'TUTU图床支持 PNG/JPG/GIF/WEBP，单文件限制 5MB。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'Telegraph图床，免登录免Token，5MB限制','col'=>6),
    )),
    'uuimg'=>array('name'=>'悠悠图床','icon'=>'mdi-cloud-upload-outline','info'=>'悠悠图床支持 PNG/JPG/GIF/WEBP，单文件限制 4.88GB。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'Lsky Pro，免登录免Token，4.88GB限制','col'=>6),
    )),
    'tuwu'=>array('name'=>'图屋图床','icon'=>'mdi-home-outline','info'=>'图屋图床支持 JPG/PNG/GIF/WEBP，单文件限制 20MB。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'免登录免Token，20MB限制','col'=>6),
    )),
    'urusai'=>array('name'=>'UR图床','icon'=>'mdi-image-filter-drama','info'=>'UR图床（urusai.cc）支持匿名/登录上传。无 Token 限制 <strong>1MB</strong>，使用 Token 限制 <strong>64MB</strong>。Token 可在官网用户中心获取。','default_enable'=>'0','fields'=>array(
        array('name'=>'token','label'=>'Token（可选）','type'=>'password','col'=>6,'placeholder'=>'留空则为匿名上传，限制1MB'),
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'urusai.cc v1，匿名1MB，Token 64MB','col'=>6),
    )),
    'imgcc'=>array('name'=>'云图床','icon'=>'mdi-cloud-check-outline','info'=>'云图床基于 Chevereto V4 程序。前往 <a href="https://imgcc.cloud/" target="_blank">云图床官网</a> 注册后在「设置 → API」中生成 Key（必填）。单文件限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'key','label'=>'API Key（必填）','type'=>'password','col'=>6,'placeholder'=>'chv_key_xxxxxxxx'),
    )),
    'imgdata'=>array('name'=>'ImgURL图床','icon'=>'mdi-database-outline','info'=>'ImgURL图床使用 Cookie 认证。前往 <a href="https://www.imgdata.cn/" target="_blank">ImgURL官网</a> 登录后 F12 → 网络 → 复制完整 Cookie（含 PHPSESSID/EMAIL/USER_SIGN/CID/UID）。每天 <strong>15张</strong>，每月 <strong>450张</strong>，总容量 <strong>5G</strong>。单文件限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'cookie','label'=>'Cookie（必填）','type'=>'password','placeholder'=>'PHPSESSID=xxx; EMAIL=xxx; USER_SIGN=xxx; CID=xxx; UID=xxx'),
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'需UID+Token，每天15张，每月450张，总容量5G','col'=>6),
    )),
    'pngcdn'=>array('name'=>'云朵图床','icon'=>'mdi-cloud-outline','info'=>'云朵图床基于 Lsky Pro 程序。前往 <a href="https://www.pngcdn.cn/" target="_blank">云朵图床官网</a> 注册后在个人中心获取 Token（必填），总容量 50MB。单文件限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'token','label'=>'Token（必填）','type'=>'password','col'=>6,'placeholder'=>'1|xxxxxxxxxxxxxxxx'),
    )),
    'naixiai'=>array('name'=>'奶昔图床','icon'=>'mdi-cup-outline','info'=>'奶昔图床基于 Chevereto V4 程序。前往 <a href="https://naixiai.cn/" target="_blank">奶昔图床官网</a> 注册后在「设置 → API」中生成 Key。单文件限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'key','label'=>'API Key（必填）','type'=>'password','col'=>6,'placeholder'=>'chv_key_xxxxxxxx'),
    )),
    'yiyunt'=>array('name'=>'怡云图床','icon'=>'mdi-cloud-outline','info'=>'怡云图床接口由 <a href="https://imgbed.yiyunt.cn" target="_blank">怡云图床</a> 提供，Token 为可选（在用户中心获取），留空则以游客身份上传。单张限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'token','label'=>'Token（可选）','type'=>'password','col'=>6,'placeholder'=>'留空则以游客身份上传'),
    )),
    'stardots'=>array('name'=>'StarDots图床','icon'=>'mdi-star-circle-outline','info'=>'<strong>注意：免费用户最多存储 200 张图片，每月流量限制 10GB（每月1号重置）。</strong>前往 <a href="https://dashboard.stardots.io/" target="_blank">StarDots官网</a> 注册后在控制台获取 Key 和 Secret，并创建空间。限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'key','label'=>'Key','type'=>'password','col'=>4,'placeholder'=>'2dcded8e-xxxx-xxxx-xxxx-d10ef0639eb3'),
        array('name'=>'secret','label'=>'Secret','type'=>'password','col'=>4,'placeholder'=>'Ey1JNRCiJO...'),
        array('name'=>'space','label'=>'空间名称','type'=>'text','col'=>4,'placeholder'=>'stardots'),
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'HMAC签名鉴权，需先在官网创建空间，PUT方式上传','col'=>6),
    )),
    'beeimg'=>array('name'=>'蜜蜂图床','icon'=>'mdi-bee','info'=>'<strong>注意：默认总容量限制 100MB。</strong>前往 <a href="https://www.beeimg.cn/" target="_blank">蜜蜂图床官网</a> 注册获取 Token（可选，支持匿名上传）。接口支持 V1/V2，限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'version','label'=>'API 版本','type'=>'select','col'=>3,'options'=>array('v2'=>'V2','v1'=>'V1')),
        array('name'=>'key','label'=>'API Key（可选）','type'=>'password','col'=>3,'placeholder'=>'3929|BMGuSbcUHI0WuiYfF7...'),
        array('name'=>'storage_id','label'=>'储存 ID (V2)','type'=>'number','col'=>3,'placeholder'=>'1','default'=>'1'),
        array('name'=>'public','label'=>'公开状态','type'=>'select','col'=>3,'options'=>array('0'=>'私有','1'=>'公开')),
        array('name'=>'remove_exif','label'=>'移除 EXIF (V2)','type'=>'select','col'=>3,'options'=>array('1'=>'是','0'=>'否')),
    )),
    'chevereto'=>array('name'=>'PlcGO图床','icon'=>'mdi-cloud-upload','info'=>'PlcGO 图床前往 <a href="https://www.picgo.net/" target="_blank">PlcGO官网</a> 注册后在「设置 → API」中生成 Key，限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'key','label'=>'API Key','type'=>'password','col'=>6,'placeholder'=>'chv_key_xxxxxxxx'),
    )),
    'imgbb'=>array('name'=>'ImgBB图床','icon'=>'mdi-image-area','info'=>'前往 <a href="https://imgbb.com/" target="_blank">ImgBB官网</a> 注册获取 API Key。限制 <strong>32MB</strong>。提示：ImgBB 服务器在海外，可能需要海外网络环境。','default_enable'=>'0','fields'=>array(
        array('name'=>'key','label'=>'API Key','type'=>'password','col'=>4,'placeholder'=>'YOUR_CLIENT_API_KEY'),
        array('name'=>'expiration','label'=>'过期时间（秒，可选）','type'=>'number','col'=>4,'placeholder'=>'留空为永久，范围60-15552000'),
    )),
    'imgurla'=>array('name'=>'Imgur.LA图床','icon'=>'mdi-link-variant','info'=>'前往 <a href="https://imgur.la/" target="_blank">Imgur.LA官网</a> 注册获取 API Key。限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'key','label'=>'API Key','type'=>'password','col'=>6,'placeholder'=>'chv_key_xxxxxxxx'),
    )),
    'helloimg'=>array('name'=>'Hello图床','icon'=>'mdi-hand-wave-outline','info'=>'前往 <a href="https://www.helloimg.com/" target="_blank">Hello图床官网</a> 注册后在个人中心获取 Token。总存储容量 <strong>1GB</strong>，单张限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'token','label'=>'Bearer Token','type'=>'password','col'=>6,'placeholder'=>'1|xxxxxxxxxxxxxxxx'),
    )),
    'meituan'=>array('name'=>'美团创作图床','icon'=>'mdi-food-outline','info'=>'美团创作图床获取方式：登录 <a href="https://czz.meituan.com/" target="_blank">美团创作者平台</a> → 点击发布视频 → F12 → 找到文件 → 往下翻找到 Cookie 值里的 <code>token</code>。限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'token','label'=>'Token','type'=>'password','col'=>6,'placeholder'=>'美团创作者平台Token'),
    )),
    'suning'=>array('name'=>'苏宁易购图床','icon'=>'mdi-store-outline','info'=>'苏宁易购图床获取方式：登录 <a href="https://www.suning.com/" target="_blank">苏宁易购</a> → F12 → 网络 → 任意请求标头 Cookie 中找到 <code>authId</code> 值。限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'authid','label'=>'authId','type'=>'password','col'=>6,'placeholder'=>'苏宁Cookie中的authId值'),
    )),
    'meipai'=>array('name'=>'美拍网图床','icon'=>'mdi-camera-outline','info'=>'美拍网图床获取方式：登录 <a href="https://www.meipai.com/" target="_blank">美拍网</a> → F12 → 网络 → 任意请求标头 Cookie 中找到 <code>__mapp_access_token__</code> 值。限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'token','label'=>'Token','type'=>'password','col'=>6,'placeholder'=>'美拍Cookie中的__mapp_access_token__值'),
    )),
    'alipay'=>array('name'=>'支付宝图床','icon'=>'mdi-credit-card-outline','info'=>'支付宝图床获取方式：登录 <a href="https://www.alipay.com/" target="_blank">支付宝</a> → F12 → 网络 → 任意请求标头 Cookie 中找到 <code>ALIPAYJSESSIONID</code> 值。限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'cookie','label'=>'Cookie（ALIPAYJSESSIONID）','type'=>'password','col'=>6,'placeholder'=>'支付宝Cookie中的ALIPAYJSESSIONID值'),
    )),
    'youzan'=>array('name'=>'有赞图床','icon'=>'mdi-storefront-outline','info'=>'有赞商城图床基于七牛云。需登录 <a href="https://www.youzan.com/v4/materials/attachment" target="_blank">有赞素材管理</a> → Cookie 中提取 <strong>sid</strong>（YZ开头31位）。限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'sid','label'=>'SID（会话ID）','type'=>'password','required'=>true,'col'=>6,'placeholder'=>'YZ开头31位'),
    )),
    'wentian'=>array('name'=>'WENTIAN图床','icon'=>'mdi-cloud-outline','info'=>'WENTIAN图床基于兰空图床（Lsky Pro），免注册匿名上传，自动获取 CSRF Token 和 Session。单文件限制 <strong>5MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'note','label'=>'说明','type'=>'text','disabled'=>'兰空图床(Lsky Pro)，免登录，自动CSRF验证，5MB限制','col'=>6),
    )),
    'imgw'=>array('name'=>'图网图床','icon'=>'mdi-cloud-upload-outline','info'=>'前往 <a href="https://www.imgw.cc/" target="_blank">图网图床官网</a> 注册后，在「授权相关 → 生成 Token」获取 Bearer Token。总存储容量 <strong>1GB</strong>，单文件限制 <strong>30MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'token','label'=>'Bearer Token','type'=>'password','col'=>6,'placeholder'=>'1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'),
        array('name'=>'alias','label'=>'别名（可选）','type'=>'text','col'=>6,'placeholder'=>'留空显示默认名称'),
    )),
    'xwyue'=>array('name'=>'星跃图床','icon'=>'mdi-cloud-upload','info'=>'免登录匿名上传，无需 Token。单文件限制 <strong>20MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'alias','label'=>'别名（可选）','type'=>'text','col'=>6,'placeholder'=>'留空显示默认名称'),
    )),
    'keye'=>array('name'=>'珂艺云图床','icon'=>'mdi-cloud-sync','info'=>'免登录匿名上传，内部自动生成 JWT 鉴权。单文件限制 <strong>100MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'alias','label'=>'别名（可选）','type'=>'text','col'=>6,'placeholder'=>'留空显示默认名称'),
    )),
    'shaitu'=>array('name'=>'晒图床','icon'=>'mdi-image-multiple','info'=>'基于 Chevereto 程序，免登录匿名上传，无需 Token/Cookie。单文件限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'alias','label'=>'别名（可选）','type'=>'text','col'=>6,'placeholder'=>'留空显示默认名称'),
    )),
    'guaigua'=>array('name'=>'乖乖图床','icon'=>'mdi-emoticon-happy','info'=>'免登录匿名上传，无需 Token/Cookie，支持 JPG/PNG/GIF/WEBP/AVIF。单文件限制 <strong>10MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'alias','label'=>'别名（可选）','type'=>'text','col'=>6,'placeholder'=>'留空显示默认名称'),
    )),
    'imgtolink'=>array('name'=>'LINK图床','icon'=>'mdi-link-variant','info'=>'基于腾讯云COS的免登录匿名图床，采用预签名URL直传。只需填写 anonymousId（浏览器访问 imgto.link 免费图床页面 → F12 → Application → Local Storage → 读取 <code>imgto_link_anonymous_id</code>），directoryId 将<strong>自动获取</strong>。单文件限制 <strong>5MB</strong>。','default_enable'=>'0','fields'=>array(
        array('name'=>'anonymous_id','label'=>'匿名用户ID（anonymousId）','type'=>'text','required'=>true,'col'=>6,'placeholder'=>'浏览器 Local Storage 中的 imgto_link_anonymous_id 值'),
        array('name'=>'directory_id','label'=>'目录ID（directoryId）','type'=>'text','required'=>true,'col'=>6,'placeholder'=>'自动获取，或手动填写纯数字'),
        array('name'=>'alias','label'=>'别名（可选）','type'=>'text','col'=>6,'placeholder'=>'留空显示默认名称'),
    )),
);

$api_default = isset($conf['api_default']) ? $conf['api_default'] : 'local';

// ========== AJAX 接口（统一返回 JSON）==========
if(isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    if(!csrf_verify()) {
        echo json_encode(['code'=>1, 'msg'=>'安全校验失败，请刷新页面后重试！']);
        exit;
    }
    $action = $_POST['action'];

    // ---- save：保存接口配置（兼容旧的 action=1）----
    if($action === 'save' || $action === '1') {
        $saved = 0;
        foreach ($_POST as $name => $value) {
            if($name === 'action' || $name === 'csrf_token' || $name === 'api_key') continue;
            // key 只允许字母数字下划线，且必须以 api_ 开头
            if(!preg_match('/^api_[a-zA-Z0-9_]+$/', $name)) continue;
            if(in_array($name, $sensitiveFields)) continue;
            // 敏感凭据（Cookie/Token/Key/Secret/authId/SID）加密存储，ct_encrypt 幂等（已加密不重复加密）
            if(is_string($value) && $value !== '' && preg_match('/^api_.+_(cookie|token|key|secret|authid|sid|pwd|pass|password)$/i', $name)) {
                $value = ct_encrypt($value);
            }
            $ok = $DB->query_prepared("INSERT INTO eecms_config SET `name`=?, `main`=? ON DUPLICATE KEY UPDATE `main`=?", 'sss', [$name, $value, $value]);
            if($ok === false) {
                echo json_encode(['code'=>1, 'msg'=>'配置项 '.$name.' 保存失败']);
                exit;
            }
            $saved++;
        }
        // 刷新内存中的配置
        $rs = $DB->query("select * from eecms_config");
        $conf = array();
        while($rs && ($row = $DB->fetch($rs))) { $conf[$row['name']] = $row['main']; }
        echo json_encode(['code'=>0, 'msg'=>'设置保存成功', 'saved'=>$saved]);
        exit;
    }

    // ---- toggle_enable：切换单个接口的启用状态 ----
    if($action === 'toggle_enable') {
        $key = isset($_POST['key']) ? $_POST['key'] : '';
        if(!isset($apiInterfaces[$key])) {
            echo json_encode(['code'=>1, 'msg'=>'接口不存在']);
            exit;
        }
        $cfgKey = 'api_'.$key.'_enable';
        $cur = isset($conf[$cfgKey]) ? $conf[$cfgKey] : $apiInterfaces[$key]['default_enable'];
        $new = ($cur === '1') ? '0' : '1';
        $ok = $DB->query_prepared("INSERT INTO eecms_config SET `name`=?, `main`=? ON DUPLICATE KEY UPDATE `main`=?", 'sss', [$cfgKey, $new, $new]);
        if($ok === false) {
            echo json_encode(['code'=>1, 'msg'=>'接口状态切换失败，请重试！']);
            exit;
        }
        $conf[$cfgKey] = $new;
        echo json_encode(['code'=>0, 'msg'=>($new==='1'?'接口已启用':'接口已禁用'), 'enable'=>$new]);
        exit;
    }

    // ---- batch_set_enable：批量设置接口启用状态 ----
    if($action === 'batch_set_enable') {
        $keys = isset($_POST['keys']) && is_array($_POST['keys']) ? $_POST['keys'] : array();
        $value = (isset($_POST['value']) && $_POST['value']==='1') ? '1' : '0';
        $cnt = 0;
        foreach($keys as $key) {
            if(!isset($apiInterfaces[$key])) continue;
            $cfgKey = 'api_'.$key.'_enable';
            $ok = $DB->query_prepared("INSERT INTO eecms_config SET `name`=?, `main`=? ON DUPLICATE KEY UPDATE `main`=?", 'sss', [$cfgKey, $value, $value]);
            if($ok === false) continue;
            $conf[$cfgKey] = $value;
            $cnt++;
        }
        echo json_encode(['code'=>0, 'msg'=>'已'.($value==='1'?'启用':'禁用').' '.$cnt.' 个接口', 'count'=>$cnt]);
        exit;
    }

    // ---- set_default：设置默认接口 ----
    if($action === 'set_default') {
        $key = isset($_POST['key']) ? $_POST['key'] : '';
        if(!isset($apiInterfaces[$key])) {
            echo json_encode(['code'=>1, 'msg'=>'接口不存在']);
            exit;
        }
        // M10 修复：禁止将已禁用的接口设为默认，否则前台用户上传会走不可用接口
        $enableVal = isset($conf['api_'.$key.'_enable']) ? $conf['api_'.$key.'_enable'] : '0';
        if($enableVal !== '1') {
            echo json_encode(['code'=>1, 'msg'=>'该接口当前已禁用，请先启用再设为默认']);
            exit;
        }
        $ok = $DB->query_prepared("INSERT INTO eecms_config SET `name`=?, `main`=? ON DUPLICATE KEY UPDATE `main`=?", 'sss', ['api_default', $key, $key]);
        if($ok === false) {
            echo json_encode(['code'=>1, 'msg'=>'默认接口设置失败，请重试！']);
            exit;
        }
        $conf['api_default'] = $key;
        $api_default = $key;
        echo json_encode(['code'=>0, 'msg'=>'已将「'.$apiInterfaces[$key]['name'].'」设为默认接口']);
        exit;
    }

    // ---- fetch_imgtolink_dir：根据 anonymousId 实时查询 directoryId ----
    if($action === 'fetch_imgtolink_dir') {
        $anonId = isset($_POST['anonymous_id']) ? trim($_POST['anonymous_id']) : '';
        if($anonId === '' || strlen($anonId) < 30) {
            echo json_encode(['code'=>1, 'msg'=>'匿名ID格式不正确']);
            exit;
        }
        // H4 修复：anonymousId 做字符集白名单校验 + URL/Cookie 编码，防止注入 & # 换行符污染
        if(!preg_match('/^[a-zA-Z0-9_\-]{30,128}$/', $anonId)) {
            echo json_encode(['code'=>1, 'msg'=>'匿名ID含非法字符']);
            exit;
        }
        $anonIdEnc = urlencode($anonId);
        // 调用 imgto.link API 查询目录列表（H4 修复：启用 SSL 证书校验，使用项目内置 CA 包）
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://imgto.link/api/v1/directories?anonymousId=' . $anonIdEnc,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'content-type: application/json',
                'Origin: https://imgto.link',
                'Referer: https://imgto.link/zh-CN/free-image-hosting',
            ],
            CURLOPT_COOKIE => 'imgto_link_anonymous_id=' . $anonIdEnc . '; NEXT_LOCALE=zh-CN',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0',
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_setup_ssl($ch);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        unset($ch);

        if($err !== '') {
            echo json_encode(['code'=>1, 'msg'=>'网络错误：' . $err]);
            exit;
        }
        if($http !== 200) {
            echo json_encode(['code'=>1, 'msg'=>'imgto.link 返回 HTTP ' . $http]);
            exit;
        }
        $data = json_decode($resp, true);
        if(!is_array($data) || !isset($data['code']) || $data['code'] !== 200) {
            echo json_encode(['code'=>1, 'msg'=>'imgto.link 返回异常']);
            exit;
        }
        $dirs = isset($data['data']['directories']) ? $data['data']['directories'] : [];
        if(!is_array($dirs) || count($dirs) === 0) {
            echo json_encode(['code'=>1, 'msg'=>'该匿名ID未关联目录，请先在浏览器访问 imgto.link 免费图床页面激活']);
            exit;
        }
        // 优先取 isDefault=true 的目录
        $dirId = null;
        $dirName = '';
        foreach($dirs as $d) {
            if(!empty($d['isDefault'])) {
                $dirId = $d['id'];
                $dirName = $d['name'];
                break;
            }
        }
        // 没有 default 则取第一个
        if($dirId === null) {
            $dirId = $dirs[0]['id'];
            $dirName = $dirs[0]['name'];
        }
        echo json_encode(['code'=>0, 'directory_id'=>$dirId, 'directory_name'=>$dirName]);
        exit;
    }

    echo json_encode(['code'=>1, 'msg'=>'未知操作']);
    exit;
}

// ========== 构建接口当前值（输出到前端，用于回显编辑表单）==========
$apiValues = array();
foreach($apiInterfaces as $key=>$cfg) {
    $vals = array();
    $vals['enable'] = isset($conf['api_'.$key.'_enable']) ? $conf['api_'.$key.'_enable'] : $cfg['default_enable'];
    $vals['alias']  = isset($conf['api_'.$key.'_alias']) ? $conf['api_'.$key.'_alias'] : '';
    $vals['maxsize']= isset($conf['api_'.$key.'_maxsize']) ? $conf['api_'.$key.'_maxsize'] : '';
    foreach($cfg['fields'] as $f) {
        $vals[$f['name']] = isset($conf['api_'.$key.'_'.$f['name']]) ? $conf['api_'.$key.'_'.$f['name']] : (isset($f['default']) ? $f['default'] : '');
    }
    $apiValues[$key] = $vals;
}
?>
<!DOCTYPE html>
<html lang="zh" data-theme="light">
<head>
<meta charset="utf-8">
<script>(function(){try{var t=localStorage.getItem('admin_theme')||'light';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>API接口设置 - <?php echo $lang->admin->title;?></title>
<link rel="stylesheet" href="style/css/admin.css?v=20260810e">
<style>
html,body{height:100%;margin:0}body{overflow:auto}.admin-content{padding:16px 28px!important}

/* 顶部工具条 */
.api-toolbar{display:flex;justify-content:flex-end;align-items:center;gap:12px;margin-bottom:0;flex-wrap:wrap}
.api-toolbar .search-input-wrap{position:relative;flex:0 1 auto;min-width:200px;max-width:280px}
.api-toolbar .search-input-wrap .icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--color-text-secondary);font-size:20px;pointer-events:none}
.api-toolbar .search-input-wrap input{padding-left:40px}
#apiParamFilter, #apiStatusFilter{flex:0 0 auto;min-width:150px;width:auto;margin-left:2px;margin-right:2px}
.api-toolbar .batch-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-left:4px}
.api-toolbar .api-stat{font-size:13px;color:var(--color-text-muted);white-space:nowrap}
.api-toolbar .api-stat strong{color:var(--color-primary);font-size:15px}

/* 表格 */
.table td{vertical-align:middle}
.api-name-cell{display:flex;align-items:center;gap:10px}
.api-name-cell .api-icon{width:34px;height:34px;border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;background:rgba(99,102,241,0.08);color:var(--color-info);flex-shrink:0}
.api-name-cell .api-icon svg{font-size:20px}
.api-name-cell .api-name-text strong{font-size:14px;color:var(--color-text-primary);display:block;line-height:1.3}
.api-name-cell .api-name-text small{font-size:12px;color:var(--color-text-secondary)}
.api-key-code{font-family:monospace;font-size:11px;background:var(--color-surface);padding:1px 5px;border-radius:3px;color:var(--color-text-muted)}

/* 默认接口选择器 */
.default-bar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:rgba(99,102,241,0.06);border:1px solid var(--color-border);border-radius:var(--radius-xl);padding:14px 18px;margin-bottom:16px}
.default-bar .default-label{font-weight:600;color:var(--color-primary);display:flex;align-items:center;gap:6px}
.default-bar select{max-width:280px}

/* 编辑弹窗内表单 */
.edit-form .form-label{font-size:13px;margin-bottom:8px;display:block}
.edit-form .alert{font-size:13px;margin-bottom:14px}
.api-required{color:var(--color-danger)}

/* 编辑弹窗表单间距修复（UI2 标准间距）*/
#editModal .modal-body .row{margin-bottom:16px}
#editModal .modal-body .row:last-child{margin-bottom:0}
#editModal .modal-body .row > *{padding-left:8px;padding-right:8px}
#editModal .modal-body .row > *:first-child{padding-left:0}
#editModal .modal-body .row > *:last-child{padding-right:0}
#editModal .modal-body .form-label{margin-bottom:8px}
#editModal .modal-body .form-control,
#editModal .modal-body .form-select{margin-bottom:0}
</style>
<link rel="stylesheet" href="style/css/sweetalert2.min.css">
<link rel="stylesheet" href="style/css/ui3-dialog.css">
</head>
<body>
<?php echo icon_sprite(); ?>
<div class="admin-content">

  <div class="page-title">
    <?php echo icon('power-plug-outline'); ?> API接口设置
  </div>

  <!-- 默认接口选择器 -->
  <div class="default-bar">
    <span class="default-label"><?php echo icon('check-circle'); ?> 默认图床接口</span>
    <select id="defaultApiSelect" class="form-select">
      <?php foreach($apiInterfaces as $key=>$cfg): ?>
        <option value="<?php echo htmlspecialchars((string)$key, ENT_QUOTES); ?>" <?php echo ($api_default==$key)?'selected':''; ?>><?php echo htmlspecialchars((string)$cfg['name'], ENT_QUOTES); ?></option>
      <?php endforeach; ?>
    </select>
    <button type="button" class="btn btn-primary btn-sm" onclick="saveDefault()"><?php echo icon('content-save'); ?> 设为默认</button>
    <span style="font-size:12px;color:#64748b;">前台图床上传默认使用的 API 接口</span>
  </div>

  <!-- 接口列表 -->
  <div class="card">
    <div class="card-header">
      <div class="card-title" style="display:flex;justify-content:space-between;align-items:center;width:100%;gap:10px;flex-wrap:wrap;">
        <span><?php echo icon('format-list-bulleted'); ?> 接口列表</span>
        <div class="api-toolbar" style="margin-bottom:0;flex:1;justify-content:flex-end;min-width:0;">
          <div class="search-input-wrap">
            <?php echo icon('magnify'); ?>
            <input type="text" class="form-control" id="apiSearch" placeholder="搜索接口名称或标识...">
          </div>
          <select id="apiParamFilter" class="form-select">
            <option value="">全部接口</option>
            <option value="param">需要设置参数</option>
            <option value="noparam">无需设置参数</option>
          </select>
          <select id="apiStatusFilter" class="form-select">
            <option value="">全部状态</option>
            <option value="1">已启用</option>
            <option value="0">已禁用</option>
          </select>
          <div class="batch-actions">
            <span class="api-stat">已选 <strong id="selCount">0</strong> / <span id="totalCount">0</span></span>
            <button type="button" class="btn btn-outline-success btn-sm" onclick="batchSet('1')"><?php echo icon('check-circle-outline'); ?> 批量启用</button>
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="batchSet('0')"><?php echo icon('close-circle-outline'); ?> 批量禁用</button>
          </div>
        </div>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th style="width:42px;"><input type="checkbox" id="checkAll" class="form-check-input"></th>
              <th style="width:42px;">ID</th>
              <th>接口名称</th>
              <th style="width:110px;">状态</th>
              <th style="width:100px;">是否默认</th>
              <th style="width:230px;">操作</th>
            </tr>
          </thead>
          <tbody id="apiTableBody">
            <tr><td colspan="6" class="text-center text-muted py-5"><?php echo icon('loading', 'icon-spin'); ?> 加载中...</td></tr>
          </tbody>
        </table>
      </div>
      <div id="apiPagination" class="d-flex justify-content-center py-3"></div>
    </div>
  </div>

</div>

<!-- 编辑接口 Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editModalTitle"><?php echo icon('pencil'); ?> 编辑接口</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
      </div>
      <div class="modal-body">
        <form id="editForm" class="edit-form">
          <input type="hidden" id="editKey">
          <div id="editFormBody"></div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary" onclick="saveEdit()"><?php echo icon('content-save'); ?> 保存设置</button>
      </div>
    </div>
  </div>
</div>

<script src="style/js/jquery.min.js"></script>
<script src="style/js/sweetalert2.min.js"></script>
<script src="style/js/ui3-dialog.js"></script>
<script src="style/js/cmodal.js"></script>
<script>
// CSRF 令牌
var CSRF_TOKEN = '<?php echo csrf_token();?>';
// M5 修复：CSRF Token 统一通过 Header 传递，不再放 POST Body
$.ajaxSetup({ beforeSend: function(xhr){ xhr.setRequestHeader('X-CSRF-Token', CSRF_TOKEN); } });
// 接口配置（静态结构）
var API_CONFIG = <?php echo json_encode($apiInterfaces, JSON_UNESCAPED_UNICODE);?>;
// 接口当前值（动态）
var API_VALUES = <?php echo json_encode($apiValues, JSON_UNESCAPED_UNICODE);?>;
// 默认接口
var API_DEFAULT = <?php echo json_encode($api_default);?>;
// 各图床默认单文件大小限制（MB）
var API_MAX_SIZES = <?php echo json_encode($apiMaxSizes, JSON_UNESCAPED_UNICODE);?>;

var editModal;
var _filteredKeys = []; // 当前筛选（关键字+条件）后的 key 列表
var _page = 1;          // 当前页
var _perPage = 10;      // 每页条数
var _paramFilter = '';  // 参数条件：'' 全部 / 'param' 需要参数 / 'noparam' 无需参数
var _statusFilter = ''; // 启用状态：'' 全部 / '1' 已启用 / '0' 已禁用

window.addEventListener('DOMContentLoaded', function(){
    editModal = new bootstrap.Modal(document.getElementById('editModal'));
    renderTable();
    bindEvents();
});

// HTML 转义
function escHtml(s){
    if(s === null || s === undefined) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// 轻提示
function toast(type, msg, timer){
    if(type === 'success'){
        Swal.fire({title:msg, icon:'success', timer:1200, showConfirmButton:false});
    } else if(type === 'error'){
        Swal.fire('错误', msg, 'error');
    } else if(type === 'warning'){
        Swal.fire('提示', msg, 'warning');
    } else {
        Swal.fire('提示', msg, 'info');
    }
}

// 接口是否需要独立设置参数：fields 中含 Cookie/Token/Key/额外参数等凭据类字段
// （排除 note 简介说明、alias 简称这类展示性字段）
function apiNeedsParams(key){
    var fields = (API_CONFIG[key] && API_CONFIG[key].fields) || [];
    return fields.some(function(f){
        return f && f.name !== 'note' && f.name !== 'alias';
    });
}

// 应用筛选（关键字 + 参数条件）→ 更新 _filteredKeys，并修正页码超界
function applyFilter(){
    var q = $('#apiSearch').val().toLowerCase().trim();
    var all = Object.keys(API_CONFIG);
    _filteredKeys = all.filter(function(key){
        var cfg = API_CONFIG[key];
        // 参数条件筛选：需要独立设置参数（凭据类）或无需设置参数
        if(_paramFilter === 'param' && !apiNeedsParams(key)) return false;
        if(_paramFilter === 'noparam' && apiNeedsParams(key)) return false;
        // 启用状态筛选：已启用 / 已禁用
        if(_statusFilter !== ''){
            var isEnabled = (API_VALUES[key] && API_VALUES[key].enable === '1');
            if((_statusFilter === '1' && !isEnabled) || (_statusFilter === '0' && isEnabled)) return false;
        }
        // 关键字筛选
        if(q !== ''){
            var hay = (cfg.name + ' ' + key).toLowerCase();
            if(hay.indexOf(q) === -1) return false;
        }
        return true;
    });
    // 页码超界自动修正
    var totalPages = Math.max(1, Math.ceil(_filteredKeys.length / _perPage));
    if(_page > totalPages) _page = totalPages;
    if(_page < 1) _page = 1;
    return _filteredKeys;
}

// 渲染表格（仅当前页，每页 _perPage 条）
function renderTable(){
    var tbody = $('#apiTableBody');
    tbody.empty();
    applyFilter();
    $('#totalCount').text(_filteredKeys.length);
    if(!_filteredKeys.length){
        tbody.append('<tr><td colspan="6" class="text-center text-muted py-5"><span style="font-size:48px;display:block;margin-bottom:8px;">'+eeIcon('power-plug-off-outline')+'</span>暂无接口数据</td></tr>');
        renderPagination();
        updateCheckAll();
        return;
    }
    var start = (_page - 1) * _perPage;
    var pageKeys = _filteredKeys.slice(start, start + _perPage);
    pageKeys.forEach(function(key, i){
        var cfg = API_CONFIG[key];
        var v = API_VALUES[key];
        var enabled = v.enable === '1';
        var isDefault = API_DEFAULT === key;
        var idx = start + i + 1;

        // 状态徽章
        var statusBadge = enabled
            ? '<span class="badge-status badge-status-on">'+eeIcon('check-circle')+' 启用</span>'
            : '<span class="badge-status badge-status-off">'+eeIcon('close-circle')+' 禁用</span>';
        // 默认徽章
        var defaultBadge = isDefault
            ? '<span class="badge-status" style="background:#fef3c7;color:#92400e;">'+eeIcon('star')+' 默认</span>'
            : '<span class="text-muted">—</span>';

        // 操作按钮（全部放进 .btn-group 确保并排对齐）
        var actions = '<div class="btn-group btn-group-sm">';
        actions += '<button type="button" class="btn btn-outline-primary" onclick="openEdit(\''+key+'\')" title="编辑">'+eeIcon('pencil')+' 编辑</button>';
        actions += '<button type="button" class="btn '+(enabled?'btn-outline-warning':'btn-outline-success')+'" onclick="toggleEnable(\''+key+'\')" title="'+(enabled?'禁用':'启用')+'">'+eeIcon(enabled?'close-circle':'check-circle')+' '+(enabled?'禁用':'启用')+'</button>';
        actions += '<button type="button" class="btn btn-outline-info" onclick="setDefault(\''+key+'\')" title="设为默认"'+(isDefault?' disabled':'')+'>'+eeIcon('star-outline')+'</button>';
        actions += '</div>';

        var row = '<tr data-key="'+escHtml(key)+'">' +
            '<td><input type="checkbox" class="form-check-input row-check" value="'+escHtml(key)+'"></td>' +
            '<td>'+idx+'</td>' +
            '<td><div class="api-name-cell">'+
                '<div class="api-icon">'+eeIcon('cloud-upload-outline')+'</div>'+
                '<div class="api-name-text"><strong>'+escHtml(cfg.name)+'</strong><small><span class="api-key-code">'+escHtml(key)+'</span> · 限制 '+(API_MAX_SIZES[key]||10)+'MB</small></div>'+
            '</div></td>' +
            '<td>'+statusBadge+'</td>' +
            '<td>'+defaultBadge+'</td>' +
            '<td>'+actions+'</td>' +
            '</tr>';
        tbody.append(row);
    });
    renderPagination();
    updateSelCount();
    updateCheckAll();
}

// 渲染数字分页器
function renderPagination(){
    var box = $('#apiPagination');
    box.empty();
    var totalPages = Math.max(1, Math.ceil(_filteredKeys.length / _perPage));
    if(totalPages <= 1) return;
    var nav = $('<nav><ul class="pagination mb-0"></ul></nav>');
    var ul = nav.find('ul');
    // 上一页
    ul.append('<li class="page-item '+(_page<=1?'disabled':'')+'"><a class="page-link" href="javascript:void(0)" data-page="'+(_page-1)+'">上一页</a></li>');
    // 数字页码
    var start = Math.max(1, _page - 2);
    var end = Math.min(totalPages, _page + 2);
    if(start > 1){
        ul.append('<li class="page-item"><a class="page-link" href="javascript:void(0)" data-page="1">1</a></li>');
        if(start > 2) ul.append('<li class="page-item disabled"><span class="page-link">…</span></li>');
    }
    for(var p = start; p <= end; p++){
        ul.append('<li class="page-item '+(p===_page?'active':'')+'"><a class="page-link" href="javascript:void(0)" data-page="'+p+'">'+p+'</a></li>');
    }
    if(end < totalPages){
        if(end < totalPages - 1) ul.append('<li class="page-item disabled"><span class="page-link">…</span></li>');
        ul.append('<li class="page-item"><a class="page-link" href="javascript:void(0)" data-page="'+totalPages+'">'+totalPages+'</a></li>');
    }
    // 下一页
    ul.append('<li class="page-item '+(_page>=totalPages?'disabled':'')+'"><a class="page-link" href="javascript:void(0)" data-page="'+(_page+1)+'">下一页</a></li>');
    box.append(nav);
}

// 翻页
function goPage(p){
    var totalPages = Math.max(1, Math.ceil(_filteredKeys.length / _perPage));
    if(p < 1 || p > totalPages || p === _page) return;
    _page = p;
    renderTable();
}

// 更新已选计数
function updateSelCount(){
    var n = $('.row-check:checked').length;
    $('#selCount').text(n);
}

// 同步全选框状态（仅当前页可见行）
function updateCheckAll(){
    var visibleChecks = $('#apiTableBody tr[data-key]:visible .row-check');
    var checkedCount = visibleChecks.filter(':checked').length;
    var allChecked = visibleChecks.length > 0 && checkedCount === visibleChecks.length;
    $('#checkAll').prop('checked', allChecked);
    $('#checkAll').prop('indeterminate', checkedCount > 0 && checkedCount < visibleChecks.length);
}

// 事件绑定（在 DOMContentLoaded 内执行，确保 jQuery 已就绪）
function bindEvents(){
    // 全选/取消全选（仅对当前可见行）
    $('#checkAll').on('change', function(){
        var checked = this.checked;
        $('tr[data-key]:visible .row-check').prop('checked', checked);
        updateSelCount();
        updateCheckAll();
    });
    // 单行勾选时更新计数 & 联动全选状态
    $(document).on('change', '.row-check', function(){
        updateSelCount();
        updateCheckAll();
    });
    // 关键字搜索（输入即搜，重置到第 1 页）
    $('#apiSearch').on('input', function(){
        _page = 1;
        renderTable();
    });
    // 参数条件筛选（切换后重置到第 1 页）
    $('#apiParamFilter').on('change', function(){
        _paramFilter = $(this).val();
        _page = 1;
        renderTable();
    });
    // 启用状态筛选（切换后重置到第 1 页）
    $('#apiStatusFilter').on('change', function(){
        _statusFilter = $(this).val();
        _page = 1;
        renderTable();
    });
    // 分页点击（事件委托）
    $('#apiPagination').on('click', '.page-link', function(e){
        e.preventDefault();
        var li = $(this).closest('.page-item');
        if(li.hasClass('disabled')) return;
        var p = parseInt($(this).data('page'), 10);
        if(!isNaN(p) && p > 0) goPage(p);
    });
}

// ========== 编辑接口 ==========
function openEdit(key){
    var cfg = API_CONFIG[key];
    if(!cfg){ toast('error', '接口不存在'); return; }
    var v = API_VALUES[key] || {};

    $('#editKey').val(key);
    $('#editModalTitle').html(eeIcon('cloud-upload-outline')+' 编辑「'+escHtml(cfg.name)+'」接口');

    var html = '';
    // 提示信息
    if(cfg.info){
        html += '<div class="alert alert-info">'+cfg.info+'</div>';
    }
    // 启用状态（公共）
    html += '<div class="row"><div class="col-md-6 mb-3">'+
        '<label class="form-label">启用状态</label>'+
        '<select class="form-select" name="api_'+key+'_enable">'+
            '<option value="1"'+(v.enable==='1'?' selected':'')+'>启用</option>'+
            '<option value="0"'+(v.enable!=='1'?' selected':'')+'>禁用</option>'+
        '</select></div></div>';

    // 特有字段
    if(cfg.fields.length){
        html += '<div class="row">';
        cfg.fields.forEach(function(f){
            var col = f.col || 12;
            var fname = 'api_'+key+'_'+f.name;
            var val = v[f.name] !== undefined ? v[f.name] : '';
            var required = f.required ? '<span class="api-required">*</span> ' : '';
            html += '<div class="col-md-'+col+' mb-3">';
            html += '<label class="form-label">'+required+escHtml(f.label)+'</label>';
            if(f.type === 'textarea'){
                html += '<textarea class="form-control" name="'+fname+'" placeholder="'+escHtml(f.placeholder||'')+'" style="height:'+(f.height||100)+'px;">'+escHtml(val)+'</textarea>';
            } else if(f.type === 'select'){
                html += '<select class="form-select" name="'+fname+'">';
                var opts = f.options || {};
                Object.keys(opts).forEach(function(ok){
                    html += '<option value="'+escHtml(ok)+'"'+(String(val)===String(ok)?' selected':'')+'>'+escHtml(opts[ok])+'</option>';
                });
                html += '</select>';
            } else if(f.type === 'number'){
                html += '<input type="number" class="form-control" name="'+fname+'" value="'+escHtml(val)+'" placeholder="'+escHtml(f.placeholder||'')+'">';
            } else if(f.disabled){
                html += '<input type="text" class="form-control" value="'+escHtml(f.disabled)+'" disabled>';
            } else {
                var inputType = (f.type === 'password') ? 'password' : 'text';
                html += '<input type="'+inputType+'" class="form-control" name="'+fname+'" value="'+escHtml(val)+'" placeholder="'+escHtml(f.placeholder||'')+'">';
            }
            html += '</div>';
        });
        html += '</div>';
    }

    // 简称 + 上传大小限制（公共）
    var defMax = API_MAX_SIZES[key] || 10;
    html += '<div class="row">'+
        '<div class="col-md-6 mb-3"><label class="form-label">简称</label>'+
        '<input type="text" class="form-control" name="api_'+key+'_alias" value="'+escHtml(v.alias||'')+'" placeholder="简称"></div>'+
        '<div class="col-md-6 mb-3"><label class="form-label">上传大小限制 (MB)</label>'+
        '<input type="number" step="0.1" min="0" class="form-control" name="api_'+key+'_maxsize" value="'+escHtml(v.maxsize||'')+'" placeholder="留空默认 '+defMax+'"></div>'+
        '</div>';

    $('#editFormBody').html(html);

    // LINK图床：anonymousId 输入框旁加「自动获取」按钮
    if(key === 'imgtolink'){
        var anonInput = document.querySelector('input[name="api_imgtolink_anonymous_id"]');
        var dirInput = document.querySelector('input[name="api_imgtolink_directory_id"]');
        if(anonInput && dirInput){
            // 把 anonymousId 输入框包装成 input-group，追加按钮
            var anonWrap = anonInput.parentElement;
            anonInput.outerHTML = '<div class="input-group">'+
                '<input type="text" class="form-control" name="api_imgtolink_anonymous_id" value="'+escHtml(anonInput.value)+'" placeholder="'+escHtml(anonInput.placeholder)+'">'+
                '<button type="button" class="btn btn-outline-primary" id="imgtolinkFetchBtn">'+eeIcon('download-outline')+' 自动获取</button>'+
                '</div>';

            // 在 directory_id 输入框下方加提示容器
            var hint = document.createElement('div');
            hint.style.cssText = 'font-size:12px;margin-top:4px;min-height:16px;';
            dirInput.parentElement.appendChild(hint);

            // 按钮点击触发获取
            $('#imgtolinkFetchBtn').on('click', function(){
                var val = document.querySelector('input[name="api_imgtolink_anonymous_id"]').value.trim();
                if(val.length < 50){
                    hint.textContent = '✗ 请先填写 anonymousId';
                    hint.style.color = '#dc2626';
                    return;
                }
                var $btn = $(this).prop('disabled', true);
                var origHtml = $btn.html();
                $btn.html('<span class="spinner-border spinner-border-sm"></span> 获取中...');
                hint.textContent = '获取中...';
                hint.style.color = '#94a3b8';
                $.ajax({
                    url: '',
                    type: 'POST',
                    dataType: 'json',
                    data: {action:'fetch_imgtolink_dir', anonymous_id:val},
                    success: function(res){
                        if(res.code === 0){
                            dirInput.value = res.directory_id;
                            hint.textContent = '✓ 已自动获取（目录：' + escHtml(res.directory_name) + '）';
                            hint.style.color = '#16a34a';
                        } else {
                            hint.textContent = '✗ ' + (res.msg || '获取失败，请手动填写');
                            hint.style.color = '#dc2626';
                        }
                    },
                    error: function(xhr, textStatus, errorThrown){
                        var errMsg = '网络错误：' + textStatus;
                        if(xhr.status) errMsg += ' (HTTP ' + xhr.status + ')';
                        if(errorThrown) errMsg += ' ' + errorThrown;
                        if(xhr.responseText){
                            var t = xhr.responseText.replace(/<[^>]+>/g, '').substring(0, 150);
                            if(t) errMsg += ' | ' + t;
                        }
                        hint.textContent = '✗ ' + errMsg;
                        hint.style.color = '#dc2626';
                    },
                    complete: function(){
                        $btn.prop('disabled', false).html(origHtml);
                    }
                });
            });
        }
    }

    editModal.show();
}

// 保存编辑
function saveEdit(){
    var key = $('#editKey').val();
    if(!key){ toast('error', '参数错误'); return; }
    var formData = $('#editForm').serializeArray();
    var data = {action:'save'};
    formData.forEach(function(item){ data[item.name] = item.value; });

    Swal.fire({
        title:'确认操作', text:'确定要保存「'+escHtml(API_CONFIG[key].name)+'」的设置吗？',
        icon:'question', showCancelButton:true,
        confirmButtonText:'确认保存', cancelButtonText:'取消',
        confirmButtonColor:'#6366f1', cancelButtonColor:'#94a3b8', reverseButtons:true
    }).then(function(result){
        if(!result.isConfirmed) return;
        $.ajax({
            url:'', type:'POST', data:data, dataType:'json',
            success:function(resp){
                if(resp.code === 0){
                    // 同步内存值
                    var v = API_VALUES[key] || {};
                    formData.forEach(function(item){
                        var m = item.name.match(/^api_[a-zA-Z0-9]+_(.*)$/);
                        if(m){
                            if(m[1]==='enable') v.enable = item.value;
                            else if(m[1]==='alias') v.alias = item.value;
                            else if(m[1]==='maxsize') v.maxsize = item.value;
                            else v[m[1]] = item.value;
                        }
                    });
                    API_VALUES[key] = v;
                    editModal.hide();
                    toast('success', resp.msg);
                    renderTable();
                } else {
                    toast('error', resp.msg);
                }
            },
            error:function(){ toast('error', '保存失败，请重试'); }
        });
    });
}

// ========== 切换启用 ==========
function toggleEnable(key){
    var cfg = API_CONFIG[key];
    if(!cfg) return;
    var cur = API_VALUES[key].enable === '1';
    var verb = cur ? '禁用' : '启用';
    Swal.fire({
        title:'确认操作', text:'确定要'+verb+'「'+escHtml(cfg.name)+'」接口吗？',
        icon:'question', showCancelButton:true,
        confirmButtonText:'确认'+verb, cancelButtonText:'取消',
        confirmButtonColor: cur ? '#ef4444' : '#10b981', cancelButtonColor:'#94a3b8', reverseButtons:true
    }).then(function(result){
        if(!result.isConfirmed) return;
        $.ajax({
            url:'', type:'POST',
            data:{action:'toggle_enable', key:key},
            dataType:'json',
            success:function(resp){
                if(resp.code === 0){
                    API_VALUES[key].enable = resp.enable;
                    toast('success', resp.msg);
                    renderTable();
                } else {
                    toast('error', resp.msg);
                }
            },
            error:function(){ toast('error', '操作失败，请重试'); }
        });
    });
}

// ========== 批量启用/禁用 ==========
function batchSet(value){
    var keys = [];
    $('.row-check:checked').each(function(){ keys.push($(this).val()); });
    if(!keys.length){ toast('warning', '请先勾选要操作的接口'); return; }
    var verb = value === '1' ? '启用' : '禁用';
    Swal.fire({
        title:'确认批量操作',
        text:'确定要批量'+verb+'选中的 '+keys.length+' 个接口吗？',
        icon:'warning', showCancelButton:true,
        confirmButtonText:'确认'+verb, cancelButtonText:'取消',
        confirmButtonColor: value==='1' ? '#10b981' : '#ef4444', cancelButtonColor:'#94a3b8', reverseButtons:true
    }).then(function(result){
        if(!result.isConfirmed) return;
        $.ajax({
            url:'', type:'POST',
            data:{action:'batch_set_enable', keys:keys, value:value},
            dataType:'json',
            success:function(resp){
                if(resp.code === 0){
                    keys.forEach(function(k){ API_VALUES[k].enable = value; });
                    $('#checkAll').prop('checked', false);
                    toast('success', resp.msg);
                    renderTable();
                } else {
                    toast('error', resp.msg);
                }
            },
            error:function(){ toast('error', '操作失败，请重试'); }
        });
    });
}

// ========== 设为默认 ==========
function saveDefault(){
    var key = $('#defaultApiSelect').val();
    setDefault(key);
}
function setDefault(key){
    var cfg = API_CONFIG[key];
    if(!cfg) return;
    if(API_DEFAULT === key){ toast('info', '该接口已是默认接口'); return; }
    Swal.fire({
        title:'确认操作', text:'确定要将「'+escHtml(cfg.name)+'」设为默认图床接口吗？',
        icon:'question', showCancelButton:true,
        confirmButtonText:'确认', cancelButtonText:'取消',
        confirmButtonColor:'#6366f1', cancelButtonColor:'#94a3b8', reverseButtons:true
    }).then(function(result){
        if(!result.isConfirmed) return;
        $.ajax({
            url:'', type:'POST',
            data:{action:'set_default', key:key},
            dataType:'json',
            success:function(resp){
                if(resp.code === 0){
                    API_DEFAULT = key;
                    $('#defaultApiSelect').val(key);
                    toast('success', resp.msg);
                    renderTable();
                } else {
                    toast('error', resp.msg);
                }
            },
            error:function(){ toast('error', '操作失败，请重试'); }
        });
    });
}
</script>
</body>
</html>
