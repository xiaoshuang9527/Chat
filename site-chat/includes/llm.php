<?php
/**
 * 模型接入层：统一按 OpenAI 兼容协议调用。
 *
 * 换供应商 = 改 base_url + model，业务代码不动。
 * API Key 只在这里（服务端）使用，永远不下发到浏览器。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 本月用量计数键（按月重置）。
 */
function site_chat_usage_key() {
	return 'site_chat_usage_' . gmdate( 'Ym' );
}

function site_chat_usage_get() {
	return (int) get_option( site_chat_usage_key(), 0 );
}

function site_chat_usage_bump() {
	update_option( site_chat_usage_key(), site_chat_usage_get() + 1, false );
}

function site_chat_usage_exceeded( $opts ) {
	$cap = (int) $opts['monthly_cap'];
	return $cap > 0 && site_chat_usage_get() >= $cap;
}

/**
 * 调一次 chat/completions。
 *
 * @return array{text:string,usage:array,model:string}|WP_Error
 */
function site_chat_llm_complete( $messages, $opts = null, $max_tokens = null ) {
	if ( null === $opts ) {
		$opts = site_chat_get_options();
	}

	$key = trim( (string) $opts['api_key'] );
	if ( '' === $key ) {
		return new WP_Error( 'site_chat_no_key', '还没有填写 API Key' );
	}

	$base = trim( (string) $opts['base_url'] );
	if ( '' === $base ) {
		return new WP_Error( 'site_chat_no_base', '还没有填写接口地址' );
	}

	$model = trim( (string) $opts['model'] );
	if ( '' === $model ) {
		return new WP_Error( 'site_chat_no_model', '还没有填写模型名' );
	}

	$body = array(
		'model'       => $model,
		'messages'    => $messages,
		'temperature' => (float) $opts['temperature'],
		'max_tokens'  => $max_tokens ? (int) $max_tokens : (int) $opts['max_tokens'],
		'stream'      => false,
	);

	$response = wp_remote_post(
		rtrim( $base, '/' ) . '/chat/completions',
		array(
			'timeout' => 45,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $key,
			),
			'body'    => wp_json_encode( $body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		site_chat_log( 'llm transport error: ' . $response->get_error_message() );
		return new WP_Error( 'site_chat_http', '连接模型服务失败：' . $response->get_error_message() );
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$raw    = (string) wp_remote_retrieve_body( $response );
	$data   = json_decode( $raw, true );

	if ( 200 !== $status ) {
		$detail = '';
		if ( is_array( $data ) ) {
			if ( isset( $data['error']['message'] ) ) {
				$detail = (string) $data['error']['message'];
			} elseif ( isset( $data['message'] ) ) {
				$detail = (string) $data['message'];
			}
		}
		if ( '' === $detail ) {
			$detail = mb_substr( wp_strip_all_tags( $raw ), 0, 200 );
		}
		site_chat_log( 'llm http ' . $status . ': ' . $detail );
		return new WP_Error( 'site_chat_http_' . $status, '模型返回 HTTP ' . $status . '：' . $detail );
	}

	if ( ! is_array( $data ) || ! isset( $data['choices'][0]['message']['content'] ) ) {
		site_chat_log( 'llm unexpected payload: ' . mb_substr( $raw, 0, 300 ) );
		return new WP_Error( 'site_chat_bad_payload', '模型返回内容无法解析' );
	}

	return array(
		'text'  => (string) $data['choices'][0]['message']['content'],
		'usage' => isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array(),
		'model' => isset( $data['model'] ) ? (string) $data['model'] : $model,
	);
}

/**
 * 设置页的「测试模型连接」。
 */
function site_chat_llm_ping() {
	$opts = site_chat_get_options();

	$result = site_chat_llm_complete(
		array(
			array(
				'role'    => 'user',
				'content' => '回复两个字：正常',
			),
		),
		$opts,
		32
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return sprintf(
		'%s / %s 通了，回复：%s',
		$opts['base_url'],
		$result['model'],
		trim( mb_substr( $result['text'], 0, 40 ) )
	);
}
