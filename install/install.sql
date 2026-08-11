-- ========== 引擎升级：InnoDB + utf8mb4 ==========
-- InnoDB：支持事务、行锁、崩溃恢复
-- utf8mb4：支持 emoji 和 4 字节字符（utf8 只支持 3 字节）

DROP TABLE IF EXISTS `eecms_config`;
CREATE TABLE `eecms_config` (
  `name` varchar(255) NOT NULL,
  `main` text,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 核心设置
INSERT INTO `eecms_config` VALUES ('admin_user', 'admin');
-- H1 修复：废除明文弱密码 123456，初始留空，由安装向导 Step 5「管理员账户设置」用 password_hash() 写入（规范 § 8.2.3）
INSERT INTO `eecms_config` VALUES ('admin_pwd', '');
INSERT INTO `eecms_config` VALUES ('title', '网站标题');
INSERT INTO `eecms_config` VALUES ('keywords', '网站关键词');
INSERT INTO `eecms_config` VALUES ('description', '网站描述');
INSERT INTO `eecms_config` VALUES ('name', '图床系统');
INSERT INTO `eecms_config` VALUES ('icp', '');
INSERT INTO `eecms_config` VALUES ('time', '');
INSERT INTO `eecms_config` VALUES ('Copyright', '');
INSERT INTO `eecms_config` VALUES ('jieshao', '网站简介');
INSERT INTO `eecms_config` VALUES ('info', '网站信息');
INSERT INTO `eecms_config` VALUES ('email', '');

-- 默认 API（本地上传，无需第三方依赖）
INSERT INTO `eecms_config` VALUES ('api_default', 'local');

-- 邮件
INSERT INTO `eecms_config` VALUES ('mail_name', '');
INSERT INTO `eecms_config` VALUES ('mail_stmp', '');
INSERT INTO `eecms_config` VALUES ('mail_port', '465');
INSERT INTO `eecms_config` VALUES ('mail_pwd', '');

-- ========== 图床配置 ==========
INSERT INTO `eecms_config` VALUES ('api_360_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_360_cookie', '');
INSERT INTO `eecms_config` VALUES ('api_360_alias', '');

INSERT INTO `eecms_config` VALUES ('api_local_enable', '1');
INSERT INTO `eecms_config` VALUES ('api_local_alias', '');

INSERT INTO `eecms_config` VALUES ('api_cfbed_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_cfbed_url', '');
INSERT INTO `eecms_config` VALUES ('api_cfbed_token', '');
INSERT INTO `eecms_config` VALUES ('api_cfbed_alias', '');

INSERT INTO `eecms_config` VALUES ('api_chevereto_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_chevereto_key', '');
INSERT INTO `eecms_config` VALUES ('api_chevereto_alias', '');

INSERT INTO `eecms_config` VALUES ('api_zhongzhuan_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_zhongzhuan_alias', '');

INSERT INTO `eecms_config` VALUES ('api_phototourl_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_phototourl_alias', '');

INSERT INTO `eecms_config` VALUES ('api_imgloc_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_imgloc_alias', '');

INSERT INTO `eecms_config` VALUES ('api_locimg_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_locimg_alias', '');

INSERT INTO `eecms_config` VALUES ('api_jisu_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_jisu_alias', '');

INSERT INTO `eecms_config` VALUES ('api_yopngs_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_yopngs_alias', '');

INSERT INTO `eecms_config` VALUES ('api_feria_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_feria_alias', '');

INSERT INTO `eecms_config` VALUES ('api_gurl_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_gurl_alias', '');

INSERT INTO `eecms_config` VALUES ('api_ljpic_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_ljpic_alias', '');

INSERT INTO `eecms_config` VALUES ('api_nickyam_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_nickyam_alias', '');

INSERT INTO `eecms_config` VALUES ('api_dogimg_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_dogimg_alias', '');

INSERT INTO `eecms_config` VALUES ('api_matu_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_matu_alias', '');

INSERT INTO `eecms_config` VALUES ('api_pnglog_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_pnglog_alias', '');

INSERT INTO `eecms_config` VALUES ('api_lvse_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_lvse_alias', '');

INSERT INTO `eecms_config` VALUES ('api_fatcat_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_fatcat_alias', '');

INSERT INTO `eecms_config` VALUES ('api_131img_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_131img_alias', '');

INSERT INTO `eecms_config` VALUES ('api_feimg_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_feimg_alias', '');

INSERT INTO `eecms_config` VALUES ('api_yootn_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_yootn_alias', '');

INSERT INTO `eecms_config` VALUES ('api_urusai_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_urusai_token', '');
INSERT INTO `eecms_config` VALUES ('api_urusai_alias', '');

INSERT INTO `eecms_config` VALUES ('api_czl_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_czl_alias', '');

INSERT INTO `eecms_config` VALUES ('api_tutu_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_tutu_alias', '');

INSERT INTO `eecms_config` VALUES ('api_uuimg_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_uuimg_alias', '');

INSERT INTO `eecms_config` VALUES ('api_tuwu_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_tuwu_alias', '');

INSERT INTO `eecms_config` VALUES ('api_imgcc_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_imgcc_key', '');
INSERT INTO `eecms_config` VALUES ('api_imgcc_alias', '');

INSERT INTO `eecms_config` VALUES ('api_imgdata_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_imgdata_cookie', '');
INSERT INTO `eecms_config` VALUES ('api_imgdata_alias', '');

INSERT INTO `eecms_config` VALUES ('api_pngcdn_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_pngcdn_token', '');
INSERT INTO `eecms_config` VALUES ('api_pngcdn_alias', '');

INSERT INTO `eecms_config` VALUES ('api_naixiai_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_naixiai_key', '');
INSERT INTO `eecms_config` VALUES ('api_naixiai_alias', '');

INSERT INTO `eecms_config` VALUES ('api_yiyunt_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_yiyunt_token', '');
INSERT INTO `eecms_config` VALUES ('api_yiyunt_alias', '');

INSERT INTO `eecms_config` VALUES ('api_scdn_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_scdn_cdn', '');
INSERT INTO `eecms_config` VALUES ('api_scdn_format', '');
INSERT INTO `eecms_config` VALUES ('api_scdn_storage', '');
INSERT INTO `eecms_config` VALUES ('api_scdn_alias', '');

INSERT INTO `eecms_config` VALUES ('api_imgbb_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_imgbb_key', '');
INSERT INTO `eecms_config` VALUES ('api_imgbb_expiration', '');
INSERT INTO `eecms_config` VALUES ('api_imgbb_alias', '');

INSERT INTO `eecms_config` VALUES ('api_imgurla_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_imgurla_key', '');
INSERT INTO `eecms_config` VALUES ('api_imgurla_alias', '');

INSERT INTO `eecms_config` VALUES ('api_helloimg_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_helloimg_token', '');
INSERT INTO `eecms_config` VALUES ('api_helloimg_alias', '');

INSERT INTO `eecms_config` VALUES ('api_stardots_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_stardots_key', '');
INSERT INTO `eecms_config` VALUES ('api_stardots_secret', '');
INSERT INTO `eecms_config` VALUES ('api_stardots_space', '');
INSERT INTO `eecms_config` VALUES ('api_stardots_alias', '');

INSERT INTO `eecms_config` VALUES ('api_remit_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_remit_alias', '');

INSERT INTO `eecms_config` VALUES ('api_alibaba_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_alibaba_alias', '');

INSERT INTO `eecms_config` VALUES ('api_beeimg_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_beeimg_key', '');
INSERT INTO `eecms_config` VALUES ('api_beeimg_version', 'v2');
INSERT INTO `eecms_config` VALUES ('api_beeimg_storage_id', '1');
INSERT INTO `eecms_config` VALUES ('api_beeimg_public', '0');
INSERT INTO `eecms_config` VALUES ('api_beeimg_remove_exif', '1');
INSERT INTO `eecms_config` VALUES ('api_beeimg_alias', '');

INSERT INTO `eecms_config` VALUES ('api_meituan_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_meituan_token', '');
INSERT INTO `eecms_config` VALUES ('api_meituan_alias', '');

INSERT INTO `eecms_config` VALUES ('api_suning_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_suning_authid', '');
INSERT INTO `eecms_config` VALUES ('api_suning_alias', '');

INSERT INTO `eecms_config` VALUES ('api_meipai_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_meipai_token', '');
INSERT INTO `eecms_config` VALUES ('api_meipai_alias', '');

INSERT INTO `eecms_config` VALUES ('api_alipay_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_alipay_cookie', '');
INSERT INTO `eecms_config` VALUES ('api_alipay_alias', '');

INSERT INTO `eecms_config` VALUES ('api_youzan_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_youzan_sid', '');
INSERT INTO `eecms_config` VALUES ('api_youzan_category', '');
INSERT INTO `eecms_config` VALUES ('api_youzan_alias', '');

-- M7 修复：补齐 wentian 启用开关配置项（原缺失导致该接口既无法启用也无法通过 is_api_enabled 校验）
INSERT INTO `eecms_config` VALUES ('api_wentian_enable', '0');

INSERT INTO `eecms_config` VALUES ('api_imgw_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_imgw_token', '');
INSERT INTO `eecms_config` VALUES ('api_imgw_alias', '');

INSERT INTO `eecms_config` VALUES ('api_xwyue_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_xwyue_alias', '');

INSERT INTO `eecms_config` VALUES ('api_keye_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_keye_alias', '');

INSERT INTO `eecms_config` VALUES ('api_shaitu_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_shaitu_alias', '');

INSERT INTO `eecms_config` VALUES ('api_guaigua_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_guaigua_alias', '');

INSERT INTO `eecms_config` VALUES ('api_imgtolink_enable', '0');
INSERT INTO `eecms_config` VALUES ('api_imgtolink_alias', '');
INSERT INTO `eecms_config` VALUES ('api_imgtolink_anonymous_id', '');
INSERT INTO `eecms_config` VALUES ('api_imgtolink_directory_id', '');

-- S3 存储配置（JSON 数组，空字符串表示无配置）
INSERT INTO `eecms_config` VALUES ('s3_storage_configs', '');

-- ========== 用户中心配置 ==========
INSERT INTO `eecms_config` VALUES ('reg_enable', '1');
INSERT INTO `eecms_config` VALUES ('reg_email_verify', '0');
INSERT INTO `eecms_config` VALUES ('upload_require_login', '0');

-- SMTP 邮件配置
INSERT INTO `eecms_config` VALUES ('smtp_host', '');
INSERT INTO `eecms_config` VALUES ('smtp_port', '465');
INSERT INTO `eecms_config` VALUES ('smtp_user', '');
INSERT INTO `eecms_config` VALUES ('smtp_pass', '');
INSERT INTO `eecms_config` VALUES ('smtp_secure', 'ssl');
INSERT INTO `eecms_config` VALUES ('smtp_from_email', '');
INSERT INTO `eecms_config` VALUES ('smtp_from_name', '');

-- ========== 访问控制配置 ==========
INSERT INTO `eecms_config` VALUES ('guest_group_id', '0');
INSERT INTO `eecms_config` VALUES ('guest_hide_local', '1');

-- ========== 业务表（导航/分类/列表/申请/公告/点赞）==========
CREATE TABLE IF NOT EXISTS `eecms_nav` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nid` int(11) NOT NULL DEFAULT 0,
  `icon` text NOT NULL,
  `name` text NOT NULL,
  `url` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_nid` (`nid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eecms_sort` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sid` int(11) NOT NULL DEFAULT 0,
  `icon` text NOT NULL,
  `sortname` text NOT NULL,
  `alias` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sid` (`sid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eecms_list` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lid` int(11) NOT NULL DEFAULT 0,
  `name` text NOT NULL,
  `img` text NOT NULL,
  `sortname` text NOT NULL,
  `zsurl` text NOT NULL,
  `url` text NOT NULL,
  `alias` text NOT NULL,
  `introduce` text NOT NULL,
  `time` text NOT NULL,
  `view` text NOT NULL,
  `love` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lid` (`lid`),
  KEY `idx_sortname` (`sortname`(50))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eecms_apply` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` text NOT NULL,
  `img` text NOT NULL,
  `sortname` text NOT NULL,
  `url` text NOT NULL,
  `introduce` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eecms_notice` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `content` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eecms_love` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `love_id` int(11) NOT NULL DEFAULT 0,
  `love_ip` varchar(45) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_love_id` (`love_id`),
  KEY `idx_love_ip` (`love_ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========== 用户中心表 ==========
CREATE TABLE IF NOT EXISTS `eecms_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(128) NOT NULL DEFAULT '',
  `role` enum('super_admin','user') NOT NULL DEFAULT 'user',
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `avatar` varchar(255) DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime DEFAULT NULL,
  `upload_count` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eecms_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL DEFAULT 0,
  `username` varchar(64) NOT NULL DEFAULT '',
  `filename` varchar(255) NOT NULL,
  `url` text NOT NULL,
  `thumb_url` text,
  `size` bigint(20) NOT NULL DEFAULT 0,
  `api_type` varchar(32) NOT NULL DEFAULT '',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eecms_email_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(128) NOT NULL,
  `code` varchar(10) NOT NULL,
  `purpose` varchar(32) NOT NULL DEFAULT 'register',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `ip` varchar(45) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========== 套餐系统表 ==========
CREATE TABLE IF NOT EXISTS `eecms_packages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL COMMENT '套餐名称',
  `level` int(11) NOT NULL DEFAULT 0 COMMENT '等级权重，越大越高',
  `storage_limit` bigint(20) NOT NULL DEFAULT 0 COMMENT '存储上限（字节）',
  `days` int(11) NOT NULL DEFAULT 0 COMMENT '有效天数',
  `group_id` int(11) NOT NULL DEFAULT 0 COMMENT '绑定接口分组ID',
  `is_default` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否默认套餐',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=启用 0=禁用(软删除)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_level` (`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eecms_user_subs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `package_id` int(11) NOT NULL DEFAULT 0,
  `package_level` int(11) NOT NULL DEFAULT 0 COMMENT '冗余：开通时套餐等级',
  `package_name` varchar(64) NOT NULL DEFAULT '' COMMENT '冗余：开通时套餐名',
  `expire_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '到期时间',
  `custom_storage` bigint(20) DEFAULT NULL COMMENT '自定义存储覆盖（NULL=跟随套餐）',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eecms_redeem_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL COMMENT '兑换码',
  `target_package_id` int(11) NOT NULL COMMENT '目标套餐ID',
  `custom_days` int(11) DEFAULT NULL COMMENT '自定义天数（NULL=取套餐天数）',
  `used_user_id` int(11) NOT NULL DEFAULT 0 COMMENT '使用者ID（0=未使用）',
  `used_at` datetime DEFAULT NULL COMMENT '使用时间',
  `expires_at` datetime DEFAULT NULL COMMENT '兑换码过期时间',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `batch_no` varchar(32) NOT NULL DEFAULT '' COMMENT '批次号',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_batch` (`batch_no`),
  KEY `idx_target` (`target_package_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eecms_api_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL COMMENT '分组名称',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '分组描述',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eecms_api_group_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `api_type` varchar(32) NOT NULL COMMENT '图床标识（如 local, imgbb, s3:0）',
  `is_s3` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否S3存储',
  `s3_id` int(11) NOT NULL DEFAULT 0 COMMENT 'S3配置ID',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_api` (`group_id`, `api_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eecms_admin_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_user` varchar(64) NOT NULL DEFAULT '',
  `action` varchar(64) NOT NULL DEFAULT '' COMMENT '操作类型',
  `target_type` varchar(32) NOT NULL DEFAULT '' COMMENT '目标类型',
  `target_id` int(11) NOT NULL DEFAULT 0 COMMENT '目标ID',
  `detail` text COMMENT '操作详情JSON',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_user`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========== API 密钥表 ==========
-- 用于对外 API 上传接口（api/api_upload.php）的 Bearer Token 鉴权
-- 安全策略：明文密钥仅在创建/重生成时返回一次，数据库只存 SHA-256 哈希
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL COMMENT '归属用户ID（关联 eecms_users.id）',
  `name` VARCHAR(100) NOT NULL COMMENT '密钥名称（用户自定义）',
  `key_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 哈希（不存明文）',
  `key_prefix` VARCHAR(20) NOT NULL COMMENT '展示用前缀 sk-xxxxxxxx',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=启用 0=禁用',
  `last_used_at` DATETIME DEFAULT NULL COMMENT '最后使用时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  UNIQUE KEY `uniq_key_hash` (`key_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========== 默认数据 ==========
-- 默认套餐（免费版：100MB存储，永久有效）
INSERT INTO `eecms_packages` (`name`, `level`, `storage_limit`, `days`, `group_id`, `is_default`, `status`) VALUES ('免费版', 0, 104857600, 0, 0, 1, 1);
