<?php
/**
 * REST 接口：
 *   POST /wp-json/site-chat/v1/chat       一轮问答
 *   POST /wp-json/site-chat/v1/escalate   访客留下邮箱，转人工
 *   GET  /wp-json/site-chat/v1/widget.js  独立挂件脚本（无头站用）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function site_chat_register_rest_routes() {
	register_rest_route(
		SITE_CHAT_REST_NS,
		'/chat',
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => 'site_chat_rest_chat',
		)
	);

	register_rest_route(
		SITE_CHAT_REST_NS,
		'/escalate',
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => 'site_chat_rest_escalate',
		)
	);

	register_rest_route(
		SITE_CHAT_REST_NS,
		'/widget.js',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => 'site_chat_rest_widget_js',
		)
	);

	register_rest_route(
		SITE_CHAT_REST_NS,
		'/config',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => 'site_chat_rest_config',
		)
	);
}
add_action( 'rest_api_init', 'site_chat_register_rest_routes' );

/**
 * 挂件配置（纯 JSON）。
 *
 * 无头站在服务端读这份配置再注入页面，这样文案与快捷问题只在插件设置里维护一份，
 * 前端不需要重复抄一遍。
 */
function site_chat_rest_config( WP_REST_Request $request ) {
	$locale = (string) $request->get_param( 'locale' );
	$locale = in_array( $locale, array( 'zh', 'en' ), true ) ? $locale : site_chat_default_locale();

	$response = new WP_REST_Response( site_chat_widget_config( $locale ), 200 );
	$response->header( 'Cache-Control', 'public, max-age=300' );
	return $response;
}

/**
 * 按提问语言猜 zh / en。
 */
function site_chat_locale_from_text( $text ) {
	return preg_match( '/\x{4e00}-\x{9fff}/u', (string) $text ) ? 'zh' : 'en';
}

/**
 * 限流：单 IP 每分钟 / 每天。
 */
function site_chat_rate_limit_ok( $opts ) {
	$ip       = site_chat_ip();
	$min_key  = 'sc_rl_m_' . md5( $ip );
	$day_key  = 'sc_rl_d_' . md5( $ip );
	$per_min  = (int) get_transient( $min_key );
	$per_day  = (int) get_transient( $day_key );

	if ( $per_min >= (int) $opts['rate_per_min'] ) {
		return false;
	}
	if ( $per_day >= (int) $opts['rate_per_day'] ) {
		return false;
	}

	set_transient( $min_key, $per_min + 1, MINUTE_IN_SECONDS );
	set_transient( $day_key, $per_day + 1, DAY_IN_SECONDS );
	return true;
}

/**
 * 统一响应格式。
 */
function site_chat_rest_reply( $session_id, $reply, $escalate = false, $extra = array() ) {
	$opts = site_chat_get_options();

	$payload = array(
		'ok'         => true,
		'session_id' => (int) $session_id,
		'reply'      => (string) $reply,
		'escalate'   => (bool) $escalate,
		'email'      => $escalate ? (string) $opts['fallback_email'] : '',
		'refs'       => array(),
	);

	return new WP_REST_Response( array_merge( $payload, $extra ), 200 );
}

/**
 * 一轮问答。
 */
function site_chat_rest_chat( WP_REST_Request $request ) {
	$opts = site_chat_get_options();

	$message = (string) $request->get_param( 'message' );
	$message = trim( wp_strip_all_tags( $message ) );
	if ( '' === $message ) {
		return new WP_REST_Response( array( 'ok' => false, 'error' => '消息为空' ), 400 );
	}
	if ( mb_strlen( $message ) > 500 ) {
		$message = mb_substr( $message, 0, 500 );
	}

	$locale_param = (string) $request->get_param( 'locale' );
	$locale       = in_array( $locale_param, array( 'zh', 'en' ), true ) ? $locale_param : site_chat_locale_from_text( $message );

	$page = esc_url_raw( (string) $request->get_param( 'page' ) );
	if ( mb_strlen( $page ) > 200 ) {
		$page = mb_substr( $page, 0, 200 );
	}

	$incoming = (int) $request->get_param( 'session_id' );

	// 限流：超了不消耗模型，直接给兜底话术
	if ( ! site_chat_rate_limit_ok( $opts ) ) {
		return site_chat_rest_reply( $incoming, site_chat_fill( site_chat_opt( $opts, 'busy_reply', $locale ) ), true, array( 'reason' => 'rate_limit' ) );
	}

	$session_id = ( $incoming && site_chat_session_valid( $incoming ) ) ? $incoming : site_chat_session_start( $locale, $page );
	if ( ! $session_id ) {
		return new WP_REST_Response( array( 'ok' => false, 'error' => '会话创建失败，请稍后再试' ), 500 );
	}

	// 历史要在写入本轮消息之前取，避免把当前这句重复带进提示词
	$history = site_chat_session_history( $session_id, $opts['history_turns'] );
	site_chat_session_add_message( $session_id, 'user', $message );

	// 寒暄：不消耗模型
	if ( site_chat_is_smalltalk( $message ) ) {
		$reply = 'zh' === $locale
			? '您好，我在的。可以问我产品花色、规格、耐磨层、适用空间，或者发货、样品、售后等问题。'
			: "Hi! I'm here. Ask me about colours, sizes, wear layer, suitable spaces, shipping, samples or after-sales.";
		site_chat_session_add_message( $session_id, 'assistant', $reply, array( 'intent' => 'smalltalk' ) );
		return site_chat_rest_reply( $session_id, $reply );
	}

	// 本月用量上限
	if ( site_chat_usage_exceeded( $opts ) ) {
		$reply = site_chat_fill( site_chat_opt( $opts, 'busy_reply', $locale ) );
		site_chat_session_add_message( $session_id, 'assistant', $reply, array( 'reason' => 'quota' ) );
		site_chat_session_set_status( $session_id, 'escalated' );
		return site_chat_rest_reply( $session_id, $reply, true, array( 'reason' => 'quota' ) );
	}

	// L1 + L2 检索
	$docs = site_chat_kb_search( $message, 6 );

	// 知识库没有 → 不调模型，直接走邮件兜底（不编造）
	if ( ! $docs ) {
		$reply = site_chat_fill( site_chat_opt( $opts, 'escalate_reply', $locale ) );
		site_chat_session_add_message( $session_id, 'assistant', $reply, array( 'reason' => 'no_kb' ) );
		site_chat_session_set_status( $session_id, 'escalated' );
		return site_chat_rest_reply( $session_id, $reply, true, array( 'reason' => 'no_kb' ) );
	}

	$system = site_chat_fill( site_chat_opt( $opts, 'system_prompt', $locale ) )
		. "\n\n【知识库内容】\n"
		. site_chat_kb_format( $docs )
		. "\n\n注意：只能依据上面【知识库内容】作答；内容里没有的，一律回答需要同事确认，不要编造。";

	$messages = array(
		array(
			'role'    => 'system',
			'content' => $system,
		),
	);
	foreach ( $history as $item ) {
		$messages[] = $item;
	}
	$messages[] = array(
		'role'    => 'user',
		'content' => $message,
	);

	$result = site_chat_llm_complete( $messages, $opts );

	if ( is_wp_error( $result ) ) {
		$reply = site_chat_fill( site_chat_opt( $opts, 'error_reply', $locale ) );
		site_chat_session_add_message( $session_id, 'assistant', $reply, array( 'reason' => 'llm_error', 'detail' => $result->get_error_message() ) );
		site_chat_session_set_status( $session_id, 'escalated' );
		return site_chat_rest_reply( $session_id, $reply, true, array( 'reason' => 'llm_error' ) );
	}

	site_chat_usage_bump();

	$reply    = trim( (string) $result['text'] );
	$escalate = false;
	if ( false !== mb_strpos( $reply, '[[转人工]]' ) ) {
		$reply    = trim( str_replace( '[[转人工]]', '', $reply ) );
		$escalate = true;
	}

	// 兜底：模型忘了打标记、但回答本身已经在"要同事确认/转人工"时，也要弹出留邮箱表单。
	// 只认"需要人工介入"这一族措辞——普通的"如需报价请联系销售"不该弹表单。
	if ( ! $escalate ) {
		$needles = array( '需要同事', '同事确认', '同事帮您确认', '同事核实', '需要人工', '转人工', 'need a colleague', 'need a human', 'colleague to confirm', 'pass this to a colleague' );
		foreach ( $needles as $needle ) {
			if ( false !== mb_strpos( $reply, $needle ) ) {
				$escalate = true;
				break;
			}
		}
	}

	$refs = $opts['show_refs'] ? site_chat_kb_refs( $docs ) : array();

	site_chat_session_add_message(
		$session_id,
		'assistant',
		$reply,
		array(
			'escalate' => $escalate,
			'refs'     => $refs,
			'usage'    => isset( $result['usage']['total_tokens'] ) ? (int) $result['usage']['total_tokens'] : 0,
		)
	);

	if ( $escalate ) {
		site_chat_session_set_status( $session_id, 'escalated' );
	}

	return site_chat_rest_reply(
		$session_id,
		$reply,
		$escalate,
		array(
			'refs'   => $refs,
			'reason' => '',
		)
	);
}

/**
 * 访客留下邮箱 → 标记待跟进 + 通知。
 */
function site_chat_rest_escalate( WP_REST_Request $request ) {
	$opts       = site_chat_get_options();
	$session_id = (int) $request->get_param( 'session_id' );
	$email      = sanitize_email( (string) $request->get_param( 'email' ) );

	if ( ! site_chat_session_valid( $session_id ) ) {
		return new WP_REST_Response( array( 'ok' => false, 'error' => '会话不存在' ), 404 );
	}
	if ( ! is_email( $email ) ) {
		return new WP_REST_Response( array( 'ok' => false, 'error' => '邮箱格式不正确' ), 400 );
	}

	site_chat_session_set_email( $session_id, $email );
	site_chat_session_set_status( $session_id, 'escalated' );

	$note = (string) $request->get_param( 'note' );
	$note = trim( wp_strip_all_tags( $note ) );
	if ( '' !== $note ) {
		$note = mb_substr( $note, 0, 300 );
	}
	site_chat_session_add_message( $session_id, 'user', '[留下邮箱] ' . $email . ( $note ? '｜补充：' . $note : '' ), array( 'intent' => 'escalate' ) );

	if ( ! empty( $opts['notify_escalate'] ) ) {
		$transcript = '';
		foreach ( site_chat_session_messages( $session_id ) as $message ) {
			$transcript .= ( 'user' === $message['role'] ? '访客：' : 'AI：' ) . $message['content'] . "\n";
		}
		$page = (string) get_post_meta( $session_id, '_sc_page', true );

		wp_mail(
			$opts['fallback_email'],
			'[AI 客服] 有访客需要人工跟进',
			"访客邮箱：{$email}\n来源页面：{$page}\n后台查看：" . admin_url( 'admin.php?page=site-chat&session=' . $session_id ) . "\n\n---- 对话记录 ----\n" . $transcript
		);
	}

	$reply = 'zh' === (string) get_post_meta( $session_id, '_sc_locale', true )
		? '已收到，我们会尽快通过邮件回复您。'
		: 'Got it — we will get back to you by email shortly.';

	site_chat_session_add_message( $session_id, 'assistant', $reply, array( 'intent' => 'escalate_done' ) );

	return new WP_REST_Response( array( 'ok' => true, 'reply' => $reply ), 200 );
}

/**
 * 独立挂件脚本（供无头站或其他前端引入）。
 */
function site_chat_rest_widget_js() {
	$file = SITE_CHAT_DIR . 'assets/chat-widget.js';
	if ( ! file_exists( $file ) ) {
		status_header( 404 );
		exit;
	}

	nocache_headers();
	header( 'Content-Type: application/javascript; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );

	// 先注入默认配置，再交给挂件脚本读取（页面里可自行覆盖 window.SiteChatConfig）
	echo 'window.SiteChatConfig=Object.assign({},' . wp_json_encode( site_chat_widget_config() ) . ',window.SiteChatConfig||{});' . "\n";
	readfile( $file );
	exit;
}
