<?php
/**
 * Plugin Name:       Site Chat
 * Description:       网站 AI 智能售前客服：知识库问答、答不上来引导邮件、后台查看完整对话。可装到任意 WordPress 站（普通主题站或无头站）。
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Site Chat
 * License:           GPL-2.0-or-later
 * Text Domain:       site-chat
 *
 * 设计要点（详见 rococo-build/plans/方案H-AI售前客服.md 第十节）：
 * 1. 与站点业务零耦合：知识库来自 data/knowledge/*.json，换站只换数据。
 * 2. 模型走 OpenAI 兼容协议，换供应商只改 base_url + model。
 * 3. Key 只存服务端，前端永远拿不到。
 * 4. 挂件投放两种模式：普通主题站 wp_footer 自动注入；无头站用独立 JS + REST。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SITE_CHAT_VERSION', '1.0.0' );
define( 'SITE_CHAT_FILE', __FILE__ );
define( 'SITE_CHAT_DIR', plugin_dir_path( __FILE__ ) );
define( 'SITE_CHAT_URL', plugin_dir_url( __FILE__ ) );
define( 'SITE_CHAT_PT_SESSION', 'sc_chat_session' );
define( 'SITE_CHAT_PT_MESSAGE', 'sc_chat_message' );
define( 'SITE_CHAT_OPTION', 'site_chat_options' );
define( 'SITE_CHAT_REST_NS', 'site-chat/v1' );

require_once SITE_CHAT_DIR . 'includes/settings.php';
require_once SITE_CHAT_DIR . 'includes/store.php';
require_once SITE_CHAT_DIR . 'includes/kb.php';
require_once SITE_CHAT_DIR . 'includes/llm.php';
require_once SITE_CHAT_DIR . 'includes/rest.php';
require_once SITE_CHAT_DIR . 'includes/widget.php';
require_once SITE_CHAT_DIR . 'includes/admin-chat.php';

/**
 * 调试日志（仅在 WP_DEBUG 打开时写）。
 */
function site_chat_log( $message ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[site-chat] ' . $message );
	}
}

/**
 * 启用：写入默认设置 + 刷新固定链接。对话数据不会被创建或删除。
 */
function site_chat_activate() {
	if ( false === get_option( SITE_CHAT_OPTION ) ) {
		add_option( SITE_CHAT_OPTION, site_chat_defaults() );
	}
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'site_chat_activate' );

/**
 * 停用：只刷新固定链接，保留所有对话记录。
 */
function site_chat_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'site_chat_deactivate' );
