<?php
/**
 * 挂件投放：
 *   模式 1（普通 WordPress 主题站）：wp_footer 自动注入
 *   模式 2（无头站）：GET /wp-json/site-chat/v1/widget.js
 *   模式 3：短代码 [site_chat] 输出一个"打开客服"按钮
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 站点默认语言（按 WordPress 语言判断）。
 */
function site_chat_default_locale() {
	return 0 === strpos( (string) get_locale(), 'zh' ) ? 'zh' : 'en';
}

/**
 * 挂件文案。
 */
function site_chat_widget_strings( $locale ) {
	if ( 'en' === $locale ) {
		return array(
			'placeholder'   => 'Type your question…',
			'send'          => 'Send',
			'open'          => 'Chat with us',
			'close'         => 'Close',
			'thinking'      => 'Thinking…',
			'escalateTitle' => 'Leave your email',
			'escalateHint'  => 'We will get back to you by email.',
			'escalateEmail' => 'your@email.com',
			'escalateSubmit'=> 'Send',
			'escalateOk'    => 'Thanks! We will contact you soon.',
			'escalateBad'   => 'Please enter a valid email.',
			'netError'      => 'Network error, please try again.',
			'refsLabel'     => 'Related products',
		);
	}

	return array(
		'placeholder'   => '请输入您的问题…',
		'send'          => '发送',
		'open'          => '在线咨询',
		'close'         => '收起',
		'thinking'      => '正在输入…',
		'escalateTitle' => '留个邮箱，我们回复您',
		'escalateHint'  => '也可以直接把问题发到邮箱。',
		'escalateEmail' => '您的邮箱',
		'escalateSubmit'=> '提交',
		'escalateOk'    => '已收到，我们会尽快回复您。',
		'escalateBad'   => '请填写正确的邮箱地址。',
		'netError'      => '网络异常，请稍后再试。',
		'refsLabel'     => '相关产品',
	);
}

/**
 * 挂件配置（模式 1 内联注入，模式 2 由 widget.js 接口输出）。
 */
function site_chat_widget_config( $locale = null ) {
	$opts = site_chat_get_options();
	if ( ! $locale ) {
		$locale = site_chat_default_locale();
	}

	$quick = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) site_chat_opt( $opts, 'quick_questions', $locale ) ) as $line ) {
		$line = trim( $line );
		if ( '' !== $line ) {
			$quick[] = $line;
		}
	}

	return array(
		'endpoint'         => rest_url( SITE_CHAT_REST_NS . '/chat' ),
		'escalateEndpoint' => rest_url( SITE_CHAT_REST_NS . '/escalate' ),
		'locale'           => $locale,
		'title'            => (string) site_chat_opt( $opts, 'widget_title', $locale ),
		'greeting'         => site_chat_fill( site_chat_opt( $opts, 'widget_greeting', $locale ) ),
		'position'         => 'left' === $opts['widget_position'] ? 'left' : 'right',
		'theme'            => (string) $opts['widget_theme'],
		'quick'            => $quick,
		'showRefs'         => ! empty( $opts['show_refs'] ),
		'email'            => (string) $opts['fallback_email'],
		// 自动展开：进站后主动露出来，避免访客不知道有客服
		'autoOpen'          => ! empty( $opts['auto_open'] ),
		'autoOpenDelay'     => (int) $opts['auto_open_delay'],
		'autoOpenFrequency' => (string) $opts['auto_open_frequency'],
		'autoOpenMobile'    => (string) $opts['auto_open_mobile'],
		'autoOpenExclude'   => (string) $opts['auto_open_exclude'],
		'strings'          => site_chat_widget_strings( $locale ),
	);
}

/**
 * 模式 1：主题站自动注入。
 */
function site_chat_enqueue_widget() {
	$opts = site_chat_get_options();
	if ( empty( $opts['widget_enabled'] ) || is_admin() ) {
		return;
	}

	wp_enqueue_script( 'site-chat-widget', SITE_CHAT_URL . 'assets/chat-widget.js', array(), SITE_CHAT_VERSION, true );
	wp_add_inline_script( 'site-chat-widget', 'window.SiteChatConfig=' . wp_json_encode( site_chat_widget_config() ) . ';', 'before' );
}
add_action( 'wp_enqueue_scripts', 'site_chat_enqueue_widget' );

/**
 * 模式 3：短代码按钮。
 */
function site_chat_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'locale' => '',
			'title'  => '',
		),
		$atts,
		'site_chat'
	);

	$locale = in_array( $atts['locale'], array( 'zh', 'en' ), true ) ? $atts['locale'] : site_chat_default_locale();
	$config = site_chat_widget_config( $locale );
	if ( '' !== $atts['title'] ) {
		$config['title'] = $atts['title'];
	}

	wp_enqueue_script( 'site-chat-widget', SITE_CHAT_URL . 'assets/chat-widget.js', array(), SITE_CHAT_VERSION, true );
	wp_add_inline_script( 'site-chat-widget', 'window.SiteChatConfig=' . wp_json_encode( $config ) . ';', 'before' );

	return '<button type="button" class="site-chat-open" style="cursor:pointer;padding:12px 22px;border:0;border-radius:999px;background:'
		. esc_attr( $config['theme'] )
		. ';color:#fff;font-size:15px;">'
		. esc_html( '' !== $atts['title'] ? $atts['title'] : $config['strings']['open'] )
		. '</button>';
}
add_shortcode( 'site_chat', 'site_chat_shortcode' );
