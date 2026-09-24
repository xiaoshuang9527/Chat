<?php
/**
 * 设置页：模型接入、知识库、挂件外观、限流与兜底。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 默认设置。
 *
 * 提示词里的 {site_name} / {email} 会在调用时替换，不在这里替换。
 */
function site_chat_defaults() {
	return array(
		// 模型接入
		'provider'         => 'deepseek',
		'base_url'         => 'https://api.deepseek.com/v1',
		'api_key'          => '',
		'model'            => 'deepseek-chat',
		'temperature'      => '0.2',
		'max_tokens'       => 600,
		// 对话
		'history_turns'    => 6,
		'monthly_cap'      => 3000,
		'rate_per_min'     => 10,
		'rate_per_day'     => 200,
		// 兜底
		// 通用占位符：这是给"换站复用"用的默认值，本站已在数据库里保存了真实邮箱（保存值优先于默认值）
		'fallback_email'   => 'your@email.com',
		'notify_escalate'  => 1,
		'include_draft'    => 0,
		// 提示词
		'system_prompt'    => '你是 {site_name} 的售前客服助手。
只能依据下面提供的【知识库内容】回答，绝对不要编造产品参数、花色、规格、价格或交期。
如果知识库内容里没有相关信息，不要猜测，直接回答"这个问题我需要同事帮您确认"，并提示访客留下邮箱。
涉及价格、折扣、批量优惠、具体交期、合同与付款条款时，一律请访客联系销售确认。
当你的回答需要同事进一步跟进时，请在回答的最后单独加上一行：[[转人工]]（这一行不会展示给访客）。
回答简洁友好，控制在 150 字以内，使用与访客提问相同的语言。',
		'escalate_reply'   => '这个问题我需要同事帮您确认，避免给您错误信息。方便留个邮箱吗？也可以直接发邮件到 {email}，我们会尽快回复您。',
		'error_reply'      => '抱歉，智能客服暂时连接不上。您可以稍后再试，或直接发邮件到 {email} 咨询。',
		'busy_reply'       => '当前咨询量较大，智能客服暂时无法应答。请留下邮箱或发邮件到 {email}，我们会尽快回复。',
		// 挂件
		'widget_enabled'   => 1,
		'widget_title'     => '在线客服',
		'widget_greeting'  => '您好，我是 {site_name} 的智能助手，可以帮您查询产品花色、规格、发货等信息。',
		'widget_position'  => 'right',
		'widget_theme'     => '#111827',
		'quick_questions'  => "有哪些浅色的木纹地板？\n地板防水吗，能用在哪里？\n怎么索取样品？",
		'show_refs'        => 1,
		// 自动展开（让访客进站就知道有客服）
		'auto_open'           => 1,
		'auto_open_delay'     => 6,
		'auto_open_frequency' => 'daily',
		'auto_open_mobile'    => 'scroll',
		'auto_open_exclude'   => '/privacy,/terms,/cookies',
		// 英文站文案（留空则回退到上面的中文版）
		'widget_title_en'    => 'Live Chat',
		'widget_greeting_en' => 'Hi, I am the ROCOCO assistant. Ask me about colours, sizes, wear layer, suitable spaces, samples or shipping.',
		'quick_questions_en' => "Which light wood-look floors do you have?\nIs the flooring waterproof?\nHow can I get a sample?",
		'escalate_reply_en'  => 'I need a colleague to confirm this, so I do not give you wrong information. Could you leave your email? Or write to {email} and we will reply shortly.',
		'error_reply_en'     => 'Sorry, the assistant is unavailable right now. Please try again later, or email {email}.',
		'busy_reply_en'      => 'We are receiving a lot of enquiries right now. Please leave your email or write to {email} and we will get back to you.',
		'system_prompt_en'   => 'You are the pre-sales assistant for ROCOCO flooring.
Answer ONLY from the [Knowledge Base] provided below. Never invent product specs, colours, sizes, prices or lead times.
If the answer is not in the knowledge base, do not guess: say you need a colleague to confirm, and ask the visitor to leave an email.
For price, discount, bulk pricing, delivery time, contract and payment terms, always ask the visitor to confirm with sales.
When your answer needs a colleague to follow up, add a separate final line: [[转人工]] (this line is never shown to the visitor).
Keep the reply under 120 words, and answer in the same language the visitor used.',
	);
}

/**
 * 按语言取设置项：英文站优先用 `xxx_en`，没配就回退中文版。
 */
function site_chat_opt( $opts, $key, $locale ) {
	if ( 'en' === $locale ) {
		$en_key = $key . '_en';
		if ( isset( $opts[ $en_key ] ) && '' !== trim( (string) $opts[ $en_key ] ) ) {
			return $opts[ $en_key ];
		}
	}
	return isset( $opts[ $key ] ) ? $opts[ $key ] : '';
}

/**
 * 取设置（已与默认值合并）。
 */
function site_chat_get_options() {
	$saved = get_option( SITE_CHAT_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( site_chat_defaults(), $saved );
}

/**
 * 把提示词里的占位符换成真实值。
 */
function site_chat_fill( $text ) {
	$opts = site_chat_get_options();
	return str_replace(
		array( '{site_name}', '{email}' ),
		array( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $opts['fallback_email'] ),
		(string) $text
	);
}

/**
 * 常见模型供应商预设（都是 OpenAI 兼容协议）。
 */
function site_chat_providers() {
	return array(
		'deepseek' => array(
			'label' => 'DeepSeek（推荐，中文好、价格低）',
			'base'  => 'https://api.deepseek.com/v1',
			'model' => 'deepseek-chat',
		),
		'qwen'     => array(
			'label' => '通义千问（百炼兼容模式）',
			'base'  => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
			'model' => 'qwen-plus',
		),
		'zhipu'    => array(
			'label' => '智谱 GLM',
			'base'  => 'https://open.bigmodel.cn/api/paas/v4',
			'model' => 'glm-4-flash',
		),
		'moonshot' => array(
			'label' => '月之暗面 Kimi',
			'base'  => 'https://api.moonshot.cn/v1',
			'model' => 'moonshot-v1-8k',
		),
		'openai'   => array(
			'label' => 'OpenAI',
			'base'  => 'https://api.openai.com/v1',
			'model' => 'gpt-4o-mini',
		),
		'custom'   => array(
			'label' => '自定义（任意 OpenAI 兼容接口）',
			'base'  => '',
			'model' => '',
		),
	);
}

/**
 * 保存时清洗。
 */
function site_chat_sanitize_options( $input ) {
	$defaults = site_chat_defaults();
	$out      = array();
	if ( ! is_array( $input ) ) {
		return $defaults;
	}

	$texts = array( 'provider', 'base_url', 'api_key', 'model', 'fallback_email', 'widget_title', 'widget_title_en', 'widget_theme', 'widget_position', 'temperature', 'auto_open_exclude' );
	foreach ( $texts as $key ) {
		if ( isset( $input[ $key ] ) ) {
			$out[ $key ] = trim( sanitize_text_field( wp_unslash( $input[ $key ] ) ) );
		}
	}

	$areas = array(
		'system_prompt', 'escalate_reply', 'error_reply', 'busy_reply', 'widget_greeting', 'quick_questions',
		'system_prompt_en', 'escalate_reply_en', 'error_reply_en', 'busy_reply_en', 'widget_greeting_en', 'quick_questions_en',
	);
	foreach ( $areas as $key ) {
		if ( isset( $input[ $key ] ) ) {
			$out[ $key ] = trim( wp_kses_post( wp_unslash( $input[ $key ] ) ) );
		}
	}

	$ints = array( 'max_tokens', 'history_turns', 'monthly_cap', 'rate_per_min', 'rate_per_day', 'auto_open_delay' );
	foreach ( $ints as $key ) {
		if ( isset( $input[ $key ] ) ) {
			$out[ $key ] = max( 0, (int) $input[ $key ] );
		}
	}

	$bools = array( 'widget_enabled', 'notify_escalate', 'include_draft', 'show_refs', 'auto_open' );
	foreach ( $bools as $key ) {
		$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
	}

	if ( ! in_array( $out['widget_position'], array( 'right', 'left' ), true ) ) {
		$out['widget_position'] = 'right';
	}
	if ( ! in_array( $out['auto_open_frequency'], array( 'session', 'daily', 'always' ), true ) ) {
		$out['auto_open_frequency'] = 'daily';
	}
	if ( ! in_array( $out['auto_open_mobile'], array( 'scroll', 'delay', 'off' ), true ) ) {
		$out['auto_open_mobile'] = 'scroll';
	}

	return array_merge( $defaults, $out );
}

function site_chat_register_settings() {
	register_setting(
		'site_chat',
		SITE_CHAT_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'site_chat_sanitize_options',
			'default'           => site_chat_defaults(),
		)
	);
}
add_action( 'admin_init', 'site_chat_register_settings' );

/**
 * 「测试连接」按钮的处理（admin-post）。
 */
function site_chat_handle_test() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '权限不足' );
	}
	check_admin_referer( 'site_chat_test' );

	$result = site_chat_llm_ping();
	if ( is_wp_error( $result ) ) {
		set_transient( 'site_chat_test_result', array( 'ok' => false, 'message' => $result->get_error_message() ), 120 );
	} else {
		set_transient( 'site_chat_test_result', array( 'ok' => true, 'message' => $result ), 120 );
	}

	wp_safe_redirect( admin_url( 'admin.php?page=site-chat-settings' ) );
	exit;
}
add_action( 'admin_post_site_chat_test', 'site_chat_handle_test' );

/**
 * 设置页渲染。
 */
function site_chat_render_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$opts      = site_chat_get_options();
	$providers = site_chat_providers();
	$stats     = site_chat_kb_stats();
	$test      = get_transient( 'site_chat_test_result' );
	if ( $test ) {
		delete_transient( 'site_chat_test_result' );
	}
	$usage = (int) get_option( site_chat_usage_key(), 0 );
	$name  = SITE_CHAT_OPTION;
	?>
	<div class="wrap">
		<h1>AI 智能客服 · 设置</h1>

		<?php if ( $test ) : ?>
			<div class="notice <?php echo $test['ok'] ? 'notice-success' : 'notice-error'; ?> is-dismissible">
				<p><strong><?php echo $test['ok'] ? '模型连接正常' : '模型连接失败'; ?></strong>：<?php echo esc_html( $test['message'] ); ?></p>
			</div>
		<?php endif; ?>

		<div class="notice notice-info">
			<p>
				<strong>知识库状态</strong>：
				已加载 <strong><?php echo (int) $stats['plain']; ?></strong> 条（产品 <?php echo (int) $stats['product']; ?> · FAQ <?php echo (int) $stats['faq']; ?>），
				跳过草稿 <strong><?php echo (int) $stats['draft']; ?></strong> 条；
				本月模型调用 <?php echo $usage; ?> 次<?php echo $opts['monthly_cap'] ? '（上限 ' . (int) $opts['monthly_cap'] . '）' : '（未设上限）'; ?>。
			</p>
			<p>知识库文件目录：<code><?php echo esc_html( site_chat_kb_dir() ); ?></code></p>
			<?php if ( ! empty( $stats['files'] ) ) : ?>
				<p>文件：<?php echo esc_html( implode( '、', $stats['files'] ) ); ?></p>
			<?php else : ?>
				<p style="color:#b32d2e;">目录里还没有 JSON 文件，客服将无法回答任何问题。</p>
			<?php endif; ?>
		</div>

		<form method="post" action="options.php">
			<?php settings_fields( 'site_chat' ); ?>

			<h2 class="title">一、模型接入</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sc-provider">供应商预设</label></th>
					<td>
						<select id="sc-provider" name="<?php echo esc_attr( $name ); ?>[provider]" onchange="
							var p = this.value, map = <?php echo wp_json_encode( wp_list_pluck( $providers, 'base' ) ); ?>,
							    m = <?php echo wp_json_encode( wp_list_pluck( $providers, 'model' ) ); ?>;
							if ( p !== 'custom' ) { document.getElementById('sc-base').value = map[p]; document.getElementById('sc-model').value = m[p]; }
						">
							<?php foreach ( $providers as $key => $provider ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $opts['provider'], $key ); ?>><?php echo esc_html( $provider['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">换供应商只改下面两项即可，业务代码不动。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sc-base">接口地址（base_url）</label></th>
					<td><input id="sc-base" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[base_url]" value="<?php echo esc_attr( $opts['base_url'] ); ?>" placeholder="https://api.deepseek.com/v1" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="sc-key">API Key</label></th>
					<td>
						<input id="sc-key" type="password" class="regular-text" name="<?php echo esc_attr( $name ); ?>[api_key]" value="<?php echo esc_attr( $opts['api_key'] ); ?>" autocomplete="off" />
						<p class="description">只保存在本站数据库，永远不会下发到浏览器。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sc-model">模型名</label></th>
					<td><input id="sc-model" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[model]" value="<?php echo esc_attr( $opts['model'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row">生成参数</th>
					<td>
						温度 <input type="number" step="0.1" min="0" max="1" class="small-text" name="<?php echo esc_attr( $name ); ?>[temperature]" value="<?php echo esc_attr( $opts['temperature'] ); ?>" />
						最长输出 <input type="number" min="100" step="50" class="small-text" name="<?php echo esc_attr( $name ); ?>[max_tokens]" value="<?php echo (int) $opts['max_tokens']; ?>" /> tokens
						<p class="description">温度越低回答越稳，客服场景建议 0~0.3。</p>
					</td>
				</tr>
			</table>

			<h2 class="title">二、提示词与兜底</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sc-prompt">系统提示词</label></th>
					<td>
						<textarea id="sc-prompt" name="<?php echo esc_attr( $name ); ?>[system_prompt]" rows="9" class="large-text code"><?php echo esc_textarea( $opts['system_prompt'] ); ?></textarea>
						<p class="description">可用占位符：<code>{site_name}</code>、<code>{email}</code>。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sc-escalate">答不上来的回复</label></th>
					<td><textarea id="sc-escalate" name="<?php echo esc_attr( $name ); ?>[escalate_reply]" rows="3" class="large-text code"><?php echo esc_textarea( $opts['escalate_reply'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="sc-prompt-en">英文系统提示词</label></th>
					<td>
						<textarea id="sc-prompt-en" name="<?php echo esc_attr( $name ); ?>[system_prompt_en]" rows="7" class="large-text code"><?php echo esc_textarea( $opts['system_prompt_en'] ); ?></textarea>
						<p class="description">英文站（/en）用这份；留空则回退中文版。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sc-escalate-en">英文：答不上来的回复</label></th>
					<td><textarea id="sc-escalate-en" name="<?php echo esc_attr( $name ); ?>[escalate_reply_en]" rows="3" class="large-text code"><?php echo esc_textarea( $opts['escalate_reply_en'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="sc-error-en">英文：模型不可用时的回复</label></th>
					<td><textarea id="sc-error-en" name="<?php echo esc_attr( $name ); ?>[error_reply_en]" rows="2" class="large-text code"><?php echo esc_textarea( $opts['error_reply_en'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="sc-error">模型不可用时的回复</label></th>
					<td><textarea id="sc-error" name="<?php echo esc_attr( $name ); ?>[error_reply]" rows="2" class="large-text code"><?php echo esc_textarea( $opts['error_reply'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="sc-busy">超出用量上限时的回复</label></th>
					<td><textarea id="sc-busy" name="<?php echo esc_attr( $name ); ?>[busy_reply]" rows="2" class="large-text code"><?php echo esc_textarea( $opts['busy_reply'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="sc-email">兜底邮箱</label></th>
					<td>
						<input id="sc-email" type="email" class="regular-text" name="<?php echo esc_attr( $name ); ?>[fallback_email]" value="<?php echo esc_attr( $opts['fallback_email'] ); ?>" />
						<p class="description">访客答不上来时引导到这个邮箱。</p>
					</td>
				</tr>
				<tr>
					<th scope="row">转人工时通知我</th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[notify_escalate]" value="1" <?php checked( $opts['notify_escalate'] ); ?> /> 有访客留下邮箱时给兜底邮箱发一封通知邮件</label></td>
				</tr>
			</table>

			<h2 class="title">三、知识库</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">知识库目录</th>
					<td>
						<code><?php echo esc_html( site_chat_kb_dir() ); ?></code>
						<p class="description">放 <code>*.json</code> 即可，结构见 <code>data/knowledge/README.md</code>。条目里 <code>"status": "draft"</code> 的默认不参与回答。</p>
					</td>
				</tr>
				<tr>
					<th scope="row">包含草稿条目</th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[include_draft]" value="1" <?php checked( $opts['include_draft'] ); ?> /> 打开后，<code>draft</code> 条目也会喂给模型（内容没确认前不建议打开）</label></td>
				</tr>
				<tr>
					<th scope="row">回答附带来源产品</th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_refs]" value="1" <?php checked( $opts['show_refs'] ); ?> /> 在回答下方显示命中的产品编号</label></td>
				</tr>
			</table>

			<h2 class="title">四、挂件与限流</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">自动注入挂件</th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[widget_enabled]" value="1" <?php checked( $opts['widget_enabled'] ); ?> /> 在 WordPress 主题站的每个页面右下角自动显示客服挂件</label>
						<p class="description">无头站（如 Next.js 前台）请关闭这项，改用独立 JS 挂件。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sc-wtitle">挂件标题</label></th>
					<td><input id="sc-wtitle" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[widget_title]" value="<?php echo esc_attr( $opts['widget_title'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="sc-wgreet">问候语</label></th>
					<td><textarea id="sc-wgreet" name="<?php echo esc_attr( $name ); ?>[widget_greeting]" rows="2" class="large-text"><?php echo esc_textarea( $opts['widget_greeting'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="sc-wquick">快捷问题</label></th>
					<td>
						<textarea id="sc-wquick" name="<?php echo esc_attr( $name ); ?>[quick_questions]" rows="3" class="large-text"><?php echo esc_textarea( $opts['quick_questions'] ); ?></textarea>
						<p class="description">一行一个问题，显示在对话开头。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sc-wtitle-en">英文：挂件标题</label></th>
					<td><input id="sc-wtitle-en" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[widget_title_en]" value="<?php echo esc_attr( $opts['widget_title_en'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="sc-wgreet-en">英文：问候语</label></th>
					<td><textarea id="sc-wgreet-en" name="<?php echo esc_attr( $name ); ?>[widget_greeting_en]" rows="2" class="large-text"><?php echo esc_textarea( $opts['widget_greeting_en'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="sc-wquick-en">英文：快捷问题</label></th>
					<td><textarea id="sc-wquick-en" name="<?php echo esc_attr( $name ); ?>[quick_questions_en]" rows="3" class="large-text"><?php echo esc_textarea( $opts['quick_questions_en'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row">外观</th>
					<td>
						位置
						<select name="<?php echo esc_attr( $name ); ?>[widget_position]">
							<option value="right" <?php selected( $opts['widget_position'], 'right' ); ?>>右下角</option>
							<option value="left" <?php selected( $opts['widget_position'], 'left' ); ?>>左下角</option>
						</select>
						主色 <input type="text" class="small-text" name="<?php echo esc_attr( $name ); ?>[widget_theme]" value="<?php echo esc_attr( $opts['widget_theme'] ); ?>" placeholder="#111827" />
					</td>
				</tr>
				<tr>
					<th scope="row">自动展开</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[auto_open]" value="1" <?php checked( $opts['auto_open'] ); ?> />
							访客进站后自动展开客服面板（避免访客不知道有客服）
						</label>
						<p class="description">
							延迟 <input type="number" min="0" max="60" class="small-text" name="<?php echo esc_attr( $name ); ?>[auto_open_delay]" value="<?php echo (int) $opts['auto_open_delay']; ?>" /> 秒后展开　｜　频率
							<select name="<?php echo esc_attr( $name ); ?>[auto_open_frequency]">
								<option value="session" <?php selected( $opts['auto_open_frequency'], 'session' ); ?>>每次会话一次</option>
								<option value="daily" <?php selected( $opts['auto_open_frequency'], 'daily' ); ?>>每人每天一次</option>
								<option value="always" <?php selected( $opts['auto_open_frequency'], 'always' ); ?>>每次访问都展开</option>
							</select>
						</p>
						<p class="description">
							手机端：
							<select name="<?php echo esc_attr( $name ); ?>[auto_open_mobile]">
								<option value="scroll" <?php selected( $opts['auto_open_mobile'], 'scroll' ); ?>>等访客滚动到 30% 再展开（推荐）</option>
								<option value="delay" <?php selected( $opts['auto_open_mobile'], 'delay' ); ?>>和桌面一样按延迟展开</option>
								<option value="off" <?php selected( $opts['auto_open_mobile'], 'off' ); ?>>手机端不自动展开，只留气泡</option>
							</select>
						</p>
						<p class="description">
							不自动展开的页面（路径片段，逗号分隔）：<br />
							<input type="text" class="large-text" name="<?php echo esc_attr( $name ); ?>[auto_open_exclude]" value="<?php echo esc_attr( $opts['auto_open_exclude'] ); ?>" placeholder="/privacy,/terms,/cookies" />
						</p>
						<p class="description">
							另外两条自动生效的规则：访客<strong>手动收起过</strong>就不再弹（本次会话内）；<strong>已经聊过天</strong>的访客不再自动展开。
							自动展开时不抢焦点（不弹手机键盘、不跳页面），也不遮罩页面。
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">限流</th>
					<td>
						单 IP 每分钟 <input type="number" min="1" class="small-text" name="<?php echo esc_attr( $name ); ?>[rate_per_min]" value="<?php echo (int) $opts['rate_per_min']; ?>" /> 次 ／
						每天 <input type="number" min="1" class="small-text" name="<?php echo esc_attr( $name ); ?>[rate_per_day]" value="<?php echo (int) $opts['rate_per_day']; ?>" /> 次
					</td>
				</tr>
				<tr>
					<th scope="row">上下文与用量</th>
					<td>
						携带最近 <input type="number" min="0" max="20" class="small-text" name="<?php echo esc_attr( $name ); ?>[history_turns]" value="<?php echo (int) $opts['history_turns']; ?>" /> 轮对话 ／
						每月最多调用 <input type="number" min="0" class="small-text" name="<?php echo esc_attr( $name ); ?>[monthly_cap]" value="<?php echo (int) $opts['monthly_cap']; ?>" /> 次（0 = 不限）
					</td>
				</tr>
			</table>

			<?php submit_button( '保存设置' ); ?>
		</form>

		<hr />
		<h2 class="title">五、模型连通性自检</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="site_chat_test" />
			<?php wp_nonce_field( 'site_chat_test' ); ?>
			<p>先保存设置，再点下面按钮发一条测试消息给模型。</p>
			<p><button type="submit" class="button">测试模型连接</button></p>
		</form>

		<hr />
		<h2 class="title">六、界面接入方式</h2>
		<p><strong>普通 WordPress 主题站</strong>：打开上面的「自动注入挂件」即可，无需改主题。</p>
		<p><strong>无头站 / 其他前端</strong>：在页面里引入挂件脚本，并先设置配置对象：</p>
		<pre class="code" style="padding:12px;background:#f6f7f7;overflow:auto;">&lt;script&gt;
window.SiteChatConfig = {
  endpoint: "&lt;?php echo esc_html( rest_url( SITE_CHAT_REST_NS . '/chat' ) ); ?&gt;",
  locale: "zh"
};
&lt;/script&gt;
&lt;script src="&lt;?php echo esc_html( rest_url( SITE_CHAT_REST_NS . '/widget.js' ) ); ?&gt;" defer&gt;&lt;/script&gt;</pre>
		<p>只想放在某个页面时，用短代码 <code>[site_chat]</code>。</p>
	</div>
	<?php
}
