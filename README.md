# MTXTCUI3 — 图床系统

一款基于 **PHP + MySQL** 开发的多图床 / 图片托管系统。前台采用 **Vue 2 + Element UI** 渲染上传界面，后台采用自研 **UI3 布局**（侧边栏 + 顶栏 + iframe 视图），内置 50+ 第三方图床接口、S3 兼容存储、用户中心、套餐与兑换码、接口分组、API 密钥鉴权等完整运营能力，开箱即用。

---

## ✨ 功能特性

### 多图床接入
- 集成 50+ 图床接口（`api/*.php`）：本地上传、360 图床、CFBed 自建、Chevereto、ImgBB、Imgur.LA、Hello 图床、星跃、奶昔、蜜蜂、晒图、LINK 图床等
- 每个接口支持独立开关、别名、大小限制，后台可视化配置
- **S3 兼容存储**：支持多套 S3 配置，Access Key / Secret Key 加密入库（AES）

### 用户中心与商业化
- 注册 / 登录，可选邮箱验证码（SMTP）、上传需登录开关
- **套餐系统**：多等级套餐（存储上限 / 有效天数 / 绑定接口分组 / 默认套餐），到期自动回退
- **兑换码**：后台批量生成、批次管理、自定义有效期与天数，前台一键兑换
- **接口分组**：把图床接口分组后绑定套餐，实现「不同套餐 → 不同可用接口」

### 对外 API
- `api/api_upload.php`：**Bearer Token（`sk-xxx`）鉴权** 上传接口，GET 返回可用接口列表，POST 上传图片；密钥仅展示一次，数据库只存 SHA-256 哈希
- `api_doc.php`：面向第三方用户的在线对接文档（动态展示当前账号可用接口）
- `api/v1/`：类 S3 风格的图片库 REST API（profile / strategies / upload / images / albums），令牌与图片元数据存储于 `api/v1/data/store.json`

### 后台管理（`admin/`）
仪表盘统计（上传趋势图）、图床接口管理、S3 存储、接口分组、套餐管理、兑换码、用户管理、图片管理、API 密钥、访问控制（访客分组 / 强制隐藏本地上传）、注册配置、系统设置、管理员操作日志，支持明暗主题切换。

### 安全设计
- 全部 SQL 使用预处理语句（防注入），输出经 `htmlspecialchars`（防 XSS）
- 安装向导、后台表单均带 CSRF 防护；Cookie 设置 `HttpOnly + SameSite=Lax`，HTTPS 下强制 `Secure`
- 安全密钥随机生成并持久化到数据库，S3 / 图床凭据使用 `ct_encrypt` 加密存储
- `.htaccess` 拦截敏感文件与目录（`config.php`、`inc/`、`docs/`、`logs/`、`backup/`）；上传目录禁用脚本执行（纵深防御防 RCE）
- 防重复加载守卫、生产环境隐藏错误输出

---

## 📦 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | **≥ 8.5** |
| 必须扩展 | `mysqli`、`curl`、`json`、`mbstring` |
| 建议扩展 | `openssl`（凭据加密）、`fileinfo`（文件类型检测）、`gd`（图片处理） |
| php.ini | `upload_max_filesize ≥ 2M`、`post_max_size ≥ 8M`、`max_execution_time ≥ 30s`、`memory_limit ≥ 64M` |
| 数据库 | MySQL 5.7+ / MariaDB（InnoDB + utf8mb4） |
| Web 服务器 | Apache（内置 `.htaccess`）或 Nginx（伪静态规则见 `install/readme.txt`） |

> 环境是否达标以安装向导「环境检测」步骤为准。

---

## 🚀 安装部署

1. 将代码上传至 Web 根目录（如 `/www/wwwroot/你的域名/`）
2. 浏览器访问 `http://你的域名/install/`，按 6 步向导操作：
   **协议说明 → 环境检测 → 数据库配置 → 创建数据表 → 管理员设置 → 安装完成**
3. 安装完成后程序自动生成 `install.lock`；如需重装请删除该文件（生产环境建议直接删除 `install/` 目录）
4. 数据库连接参数写入根目录 `config.php`（安装向导自动完成）：

   ```php
   $dbconfig = array(
       'host'   => '127.0.0.1',
       'port'   => 3306,
       'user'   => '数据库用户名',
       'pwd'    => '数据库密码',
       'dbname' => '数据库名'
   );
   ```

5. 后台入口：`/admin/`，使用安装时设置的管理员账号登录

### Nginx 伪静态
```nginx
rewrite ^/index.html$ /index.php;
rewrite ^/sort/([1-9]+[0-9]*).html$ /sort.php?id=$1;
rewrite ^/sort/([a-zA-Z]+).html$ /sort.php?alias=$1;
rewrite ^/site-([1-9]+[0-9]*).html$ /site.php?id=$1;
rewrite ^/([a-zA-Z]+).html$ /site.php?alias=$1;
```

### 本地开发（无需 Apache/Nginx）
```bash
php -S 0.0.0.0:8080 -t . router.php
```

---

## 📁 目录结构

```
├── index.php          # 前台主入口（Vue2 + Element UI 上传界面）
├── config.php         # 数据库配置（安装时自动写入）
├── header.php         # 公共引导（加载核心类库）
├── footer.php         # 公共页脚
├── module.php         # 导航/站点展示模块（遗留，仅兼容）
├── router.php         # 本地开发路由（php -S 专用，模拟 .htaccess）
├── api_doc.php        # 对外 API 在线对接文档
├── .htaccess          # Apache 安全规则与伪静态
├── install/           # 安装向导（6 步）
├── inc/               # 核心类库：db/function/security/member/user_auth/api_keys/package/smtp_mailer 等
├── api/               # 50+ 图床接口适配器 + api_upload.php + v1 图片库 API
├── admin/             # 后台管理（UI3 布局，含 style/ 与功能页）
├── bd/                # 前台静态资源（Vue/Element UI 本地化）
├── user/              # 用户中心（注册/登录/套餐购买）
└── backup/            # 备份目录（勿暴露公网）
```

### 数据库主要表（`install/install.sql`）
`eecms_config`（系统配置与图床凭据）、`eecms_users` / `eecms_images` / `eecms_email_codes`、`eecms_packages` / `eecms_user_subs` / `eecms_redeem_codes`、`eecms_api_groups` / `eecms_api_group_items`、`api_keys`（对外 API 密钥）、`eecms_admin_logs` 等。

---

## 🔌 对外 API 使用示例

```bash
# 1. 在后台「API 密钥」创建密钥，得到 sk-xxxx 前缀密钥（仅显示一次）

# 2. 查询当前账号可用图床接口
curl -H "Authorization: Bearer sk-xxxxxxxxxxxxxxxx" \
     https://你的域名/api/api_upload.php

# 3. 上传图片
curl -X POST \
     -H "Authorization: Bearer sk-xxxxxxxxxxxxxxxx" \
     -F "api=local" \
     -F "file=@./demo.png" \
     https://你的域名/api/api_upload.php
```

完整请求 / 响应格式与错误码见站内 `api_doc.php`。

---

## 🔒 安全注意事项

- **严禁**将 `backup/`、`docs/`、`logs/`、`api/v1/data/store.json` 暴露公网（`.gitignore` 已排除，服务器侧由 `.htaccess` 拦截）
- 生产环境请删除 `install/` 目录，关闭 PHP 错误显示（系统已默认关闭）
- 定期修改管理员密码与各图床接口的 Cookie / Token / Key（数据库中加密存储）
- 部署 HTTPS 后，会话 Cookie 将自动标记 `Secure`

---

## 📄 授权说明

本项目由 **Eecms 内容管理系统**（软著登记号：2022SR0050653）演进而来，免费可商用。作者不对使用本系统构建网站所产生的任何内容、版权纠纷及法律争议承担责任。使用即视为理解并接受上述条款。
