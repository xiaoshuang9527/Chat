<?php
/**
 * 数据层：会话（SITE_CHAT_PT_SESSION）与每轮消息（SITE_CHAT_PT_MESSAGE，用 post_parent 关联）。
 *
 * 为什么不把整段对话塞进一个 meta 字段：meta 值会随对话变长而膨胀，
 * 后台详情页会越来越慢；一条消息一条记录，列表好分页、导出也好做。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function site_chat_register_post_types() {
	register_post_type(
		SITE_CHAT_PT_SESSION,
		array(
			'labels'              => array(
				'name'          => '客服会话',
				'singular_name' => '客服会话',
			),
			'public'              => false,
			'show_ui'             => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'supports'            => array( 'title' ),
		)
	);

	register_post_type(
		SITE_CHAT_PT_MESSAGE,
		array(
			'labels'              => array(
				'name'          => '客服消息',
				'singular_name' => '客服消息',
			),
			'public'              => false,
			'show_ui'             => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'supports'            => array( 'title', 'editor' ),
		)
	);
}
add_action( 'init', 'site_chat_register_post_types', 5 );

/**
 * 访客 IP（无头站经 Next API 路由转发时会带 X-Forwarded-For）。
 */
function site_chat_ip() {
	$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
	foreach ( $candidates as $key ) {
		if ( empty( $_SERVER[ $key ] ) ) {
			continue;
		}
		$value = (string) $_SERVER[ $key ];
		if ( false !== strpos( $value, ',' ) ) {
			$parts = explode( ',', $value );
			$value = trim( $parts[0] );
		}
		$value = filter_var( $value, FILTER_VALIDATE_IP );
		if ( $value ) {
			return $value;
		}
	}
	return '0.0.0.0';
}

/**
 * 访客指纹（IP + UA 的哈希前缀）。只用于限流与后台区分，不存明文 IP 之外的隐私。
 */
function site_chat_visitor_key() {
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
	return substr( wp_hash( site_chat_ip() . '|' . $ua ), 0, 16 );
}

/**
 * 新建会话，返回会话 ID（失败返回 0）。
 */
function site_chat_session_start( $locale = 'zh', $page = '', $entry = 'manual' ) {
	$id = wp_insert_post(
		array(
			'post_type'   => SITE_CHAT_PT_SESSION,
			'post_status' => 'publish',
			'post_title'  => sprintf( '访客 %s · %s', substr( site_chat_visitor_key(), 0, 6 ), current_time( 'Y-m-d H:i' ) ),
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		site_chat_log( 'session_start failed: ' . $id->get_error_message() );
		return 0;
	}

	update_post_meta( $id, '_sc_locale', $locale );
	update_post_meta( $id, '_sc_page', $page );
	update_post_meta( $id, '_sc_status', 'open' );
	update_post_meta( $id, '_sc_visitor', site_chat_visitor_key() );
	update_post_meta( $id, '_sc_ip', site_chat_ip() );
	update_post_meta( $id, '_sc_count', 0 );
	update_post_meta( $id, '_sc_email', '' );
	update_post_meta( $id, '_sc_refs', array() );
	update_post_meta( $id, '_sc_entry', 'auto' === $entry ? 'auto' : 'manual' );

	return (int) $id;
}

/**
 * 校验会话 ID 是否有效（防止拿别人的 ID 续接）。
 */
function site_chat_session_valid( $id ) {
	$id = (int) $id;
	if ( ! $id ) {
		return false;
	}
	$post = get_post( $id );
	if ( ! $post || SITE_CHAT_PT_SESSION !== $post->post_type || 'trash' === $post->post_status ) {
		return false;
	}
	// 同一访客指纹才允许续接；不同访客给新会话。
	return site_chat_visitor_key() === (string) get_post_meta( $id, '_sc_visitor', true );
}

/**
 * 追加一轮消息。
 */
function site_chat_session_add_message( $session_id, $role, $content, $extra = array() ) {
	$session_id = (int) $session_id;
	if ( ! $session_id ) {
		return 0;
	}

	$role = in_array( $role, array( 'user', 'assistant', 'system' ), true ) ? $role : 'assistant';

	$message_id = wp_insert_post(
		array(
			'post_type'    => SITE_CHAT_PT_MESSAGE,
			'post_status'  => 'publish',
			'post_parent'  => $session_id,
			'post_title'   => $role,
			'post_content' => $content,
		),
		true
	);

	if ( is_wp_error( $message_id ) ) {
		site_chat_log( 'message_add failed: ' . $message_id->get_error_message() );
		return 0;
	}

	if ( ! empty( $extra ) ) {
		update_post_meta( $message_id, '_sc_extra', $extra );
	}

	$count = (int) get_post_meta( $session_id, '_sc_count', true );
	update_post_meta( $session_id, '_sc_count', $count + 1 );
	update_post_meta( $session_id, '_sc_last', current_time( 'mysql' ) );

	return (int) $message_id;
}

/**
 * 取会话的全部消息（按时间正序）。
 */
function site_chat_session_messages( $session_id ) {
	$query = new WP_Query(
		array(
			'post_type'      => SITE_CHAT_PT_MESSAGE,
			'post_status'    => 'publish',
			'post_parent'    => (int) $session_id,
			'posts_per_page' => 500,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		)
	);

	$messages = array();
	foreach ( $query->posts as $post ) {
		$messages[] = array(
			'id'      => (int) $post->ID,
			'role'    => 'user' === $post->post_title ? 'user' : 'assistant',
			'content' => (string) $post->post_content,
			'time'    => $post->post_date,
			'extra'   => (array) get_post_meta( $post->ID, '_sc_extra', true ),
		);
	}
	return $messages;
}

/**
 * 取最近 N 轮对话，用于拼进提示词。
 */
function site_chat_session_history( $session_id, $turns = 6 ) {
	$turns = max( 0, (int) $turns );
	if ( ! $turns ) {
		return array();
	}

	$messages = site_chat_session_messages( $session_id );
	$messages = array_slice( $messages, -1 * ( $turns * 2 ) );

	$history = array();
	foreach ( $messages as $message ) {
		$history[] = array(
			'role'    => $message['role'],
			'content' => $message['content'],
		);
	}
	return $history;
}

function site_chat_session_set_status( $session_id, $status ) {
	$allowed = array( 'open', 'escalated', 'closed' );
	if ( ! in_array( $status, $allowed, true ) ) {
		return;
	}
	update_post_meta( (int) $session_id, '_sc_status', $status );
}

function site_chat_session_status( $session_id ) {
	$status = (string) get_post_meta( (int) $session_id, '_sc_status', true );
	return $status ? $status : 'open';
}

function site_chat_session_set_email( $session_id, $email ) {
	update_post_meta( (int) $session_id, '_sc_email', sanitize_email( $email ) );
}

/**
 * 状态标签（后台用）。
 */
function site_chat_status_labels() {
	return array(
		'open'      => '进行中',
		'escalated' => '待跟进',
		'closed'    => '已处理',
	);
}
