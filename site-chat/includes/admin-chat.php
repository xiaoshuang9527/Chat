<?php
/**
 * 后台：会话记录列表 + 对话详情 + 状态标记 + CSV 导出。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function site_chat_admin_menu() {
	add_menu_page( 'AI 客服', 'AI 客服', 'manage_options', 'site-chat', 'site_chat_render_page', 'dashicons-format-chat', 26 );
	add_submenu_page( 'site-chat', '会话记录', '会话记录', 'manage_options', 'site-chat', 'site_chat_render_page' );
	add_submenu_page( 'site-chat', '客服设置', '客服设置', 'manage_options', 'site-chat-settings', 'site_chat_render_settings' );
}
add_action( 'admin_menu', 'site_chat_admin_menu' );

/**
 * 查询会话。
 */
function site_chat_query_sessions( $args = array() ) {
	$defaults = array(
		'post_type'      => SITE_CHAT_PT_SESSION,
		'post_status'    => 'publish',
		'posts_per_page' => 20,
		'paged'          => 1,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	$query_args = array_merge( $defaults, $args );

	if ( ! empty( $args['status'] ) ) {
		$query_args['meta_query'] = array(
			array(
				'key'   => '_sc_status',
				'value' => $args['status'],
			),
		);
	}

	return new WP_Query( $query_args );
}

/**
 * 页面入口：有 session 参数看详情，否则看列表。
 */
function site_chat_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$session = isset( $_GET['session'] ) ? absint( $_GET['session'] ) : 0;
	if ( $session ) {
		site_chat_render_session( $session );
		return;
	}

	site_chat_render_list();
}

/**
 * 会话列表。
 */
function site_chat_render_list() {
	$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
	$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$labels = site_chat_status_labels();

	$query = site_chat_query_sessions(
		array(
			'status' => $status,
			'paged'  => $paged,
		)
	);
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline">AI 客服 · 会话记录</h1>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=site-chat-settings' ) ); ?>" class="page-title-action">客服设置</a>
		<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=site_chat_export' . ( $status ? '&status=' . $status : '' ) ), 'site_chat_export' ) ); ?>" class="page-title-action">导出 CSV</a>
		<hr class="wp-header-end" />

		<ul class="subsubsub">
			<?php
			$counts = array(
				''          => site_chat_query_sessions( array( 'posts_per_page' => 1, 'fields' => 'ids' ) )->found_posts,
				'open'      => site_chat_query_sessions( array( 'status' => 'open', 'posts_per_page' => 1, 'fields' => 'ids' ) )->found_posts,
				'escalated' => site_chat_query_sessions( array( 'status' => 'escalated', 'posts_per_page' => 1, 'fields' => 'ids' ) )->found_posts,
				'closed'    => site_chat_query_sessions( array( 'status' => 'closed', 'posts_per_page' => 1, 'fields' => 'ids' ) )->found_posts,
			);
			$items  = array();
			foreach ( array_merge( array( '' => '全部' ), $labels ) as $key => $label ) {
				$url     = admin_url( 'admin.php?page=site-chat' . ( $key ? '&status=' . $key : '' ) );
				$items[] = sprintf(
					'<li><a href="%s" class="%s">%s <span class="count">(%d)</span></a></li>',
					esc_url( $url ),
					$status === $key ? 'current' : '',
					esc_html( $label ),
					(int) $counts[ $key ]
				);
			}
			echo implode( ' | ', $items ); // phpcs:ignore WordPress.Security.EscapeOutput
			?>
		</ul>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:150px;">开始时间</th>
					<th style="width:110px;">访客</th>
					<th style="width:70px;">语言</th>
					<th style="width:70px;">展开</th>
					<th>来源页</th>
					<th style="width:70px;">消息</th>
					<th style="width:90px;">状态</th>
					<th style="width:180px;">访客邮箱</th>
					<th style="width:90px;">操作</th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $query->posts ) : ?>
				<tr><td colspan="9">还没有会话记录。挂件上线后，访客的每次咨询都会出现在这里。</td></tr>
			<?php else : ?>
				<?php foreach ( $query->posts as $post ) : ?>
					<?php
					$sid    = (int) $post->ID;
					$st     = site_chat_session_status( $sid );
					$count  = (int) get_post_meta( $sid, '_sc_count', true );
					$locale = (string) get_post_meta( $sid, '_sc_locale', true );
					$entry  = 'auto' === get_post_meta( $sid, '_sc_entry', true ) ? '自动' : '手动';
					$page   = (string) get_post_meta( $sid, '_sc_page', true );
					$email  = (string) get_post_meta( $sid, '_sc_email', true );
					$detail = admin_url( 'admin.php?page=site-chat&session=' . $sid );
					?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $post->post_date_gmt, 'Y-m-d H:i' ) ); ?></td>
						<td><?php echo esc_html( substr( (string) get_post_meta( $sid, '_sc_visitor', true ), 0, 6 ) ); ?></td>
						<td><?php echo 'en' === $locale ? 'EN' : '中文'; ?></td>
						<td><?php echo esc_html( $entry ); ?></td>
						<td>
							<?php if ( $page ) : ?>
								<a href="<?php echo esc_url( $page ); ?>" target="_blank" rel="noopener"><?php echo esc_html( mb_substr( $page, 0, 60 ) ); ?></a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><?php echo (int) $count; ?></td>
						<td>
							<?php if ( 'escalated' === $st ) : ?>
								<strong style="color:#b32d2e;"><?php echo esc_html( $labels[ $st ] ); ?></strong>
							<?php else : ?>
								<?php echo esc_html( isset( $labels[ $st ] ) ? $labels[ $st ] : $st ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo $email ? esc_html( $email ) : '—'; ?></td>
						<td><a href="<?php echo esc_url( $detail ); ?>" class="button button-small">查看对话</a></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<?php
		$total = (int) $query->max_num_pages;
		if ( $total > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $total,
						'prev_text' => '‹',
						'next_text' => '›',
					)
				)
			);
			echo '</div></div>';
		}
		?>
	</div>
	<?php
}

/**
 * 会话详情。
 */
function site_chat_render_session( $session_id ) {
	$post = get_post( $session_id );
	if ( ! $post || SITE_CHAT_PT_SESSION !== $post->post_type ) {
		echo '<div class="wrap"><h1>会话不存在</h1></div>';
		return;
	}

	$labels   = site_chat_status_labels();
	$status   = site_chat_session_status( $session_id );
	$messages = site_chat_session_messages( $session_id );
	$notice   = isset( $_GET['sc_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['sc_notice'] ) ) : '';
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline">会话详情 #<?php echo (int) $session_id; ?></h1>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=site-chat' ) ); ?>" class="page-title-action">返回列表</a>
		<hr class="wp-header-end" />

		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">基本信息</th>
				<td>
					开始时间：<?php echo esc_html( get_date_from_gmt( $post->post_date_gmt, 'Y-m-d H:i' ) ); ?>　｜
					语言：<?php echo 'en' === get_post_meta( $session_id, '_sc_locale', true ) ? 'EN' : '中文'; ?>　｜
					展开方式：<?php echo 'auto' === get_post_meta( $session_id, '_sc_entry', true ) ? '自动展开' : '访客自己点开'; ?>　｜
					访客指纹：<?php echo esc_html( substr( (string) get_post_meta( $session_id, '_sc_visitor', true ), 0, 6 ) ); ?>　｜
					来源页：
					<?php $page = (string) get_post_meta( $session_id, '_sc_page', true ); ?>
					<?php if ( $page ) : ?>
						<a href="<?php echo esc_url( $page ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $page ); ?></a>
					<?php else : ?>—<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">访客邮箱</th>
				<td>
					<?php $email = (string) get_post_meta( $session_id, '_sc_email', true ); ?>
					<?php echo $email ? '<strong>' . esc_html( $email ) . '</strong>' : '（访客还没有留下邮箱）'; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">处理状态</th>
				<td>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
						<input type="hidden" name="action" value="site_chat_set_status" />
						<input type="hidden" name="session" value="<?php echo (int) $session_id; ?>" />
						<?php wp_nonce_field( 'site_chat_set_status_' . $session_id ); ?>
						<select name="status">
							<?php foreach ( $labels as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button">更新状态</button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:12px;" onsubmit="return confirm('删除这条会话及其全部消息？');">
						<input type="hidden" name="action" value="site_chat_delete" />
						<input type="hidden" name="session" value="<?php echo (int) $session_id; ?>" />
						<?php wp_nonce_field( 'site_chat_delete_' . $session_id ); ?>
						<button type="submit" class="button button-link-delete">删除会话</button>
					</form>
				</td>
			</tr>
		</table>

		<h2>对话记录</h2>
		<div style="max-width:760px;">
			<?php if ( ! $messages ) : ?>
				<p>这条会话还没有消息。</p>
			<?php endif; ?>
			<?php foreach ( $messages as $message ) : ?>
				<?php
				$is_user = 'user' === $message['role'];
				$extra   = $message['extra'];
				?>
				<div style="margin:10px 0;padding:10px 14px;border-radius:10px;<?php echo $is_user ? 'background:#f0f6fc;border:1px solid #c5d9ed;' : 'background:#f6f7f7;border:1px solid #dcdcde;'; ?>">
					<div style="font-size:12px;color:#666;margin-bottom:4px;">
						<?php echo $is_user ? '访客' : 'AI 客服'; ?> ·
						<?php echo esc_html( get_date_from_gmt( $message['time'], 'H:i:s' ) ); ?>
						<?php if ( ! empty( $extra['reason'] ) ) : ?>
							· <span style="color:#b32d2e;"><?php echo esc_html( $extra['reason'] ); ?></span>
						<?php endif; ?>
					</div>
					<div style="white-space:pre-wrap;"><?php echo esc_html( $message['content'] ); ?></div>
					<?php if ( ! empty( $extra['refs'] ) ) : ?>
						<div style="font-size:12px;color:#666;margin-top:6px;">
							命中来源：<?php echo esc_html( implode( '、', wp_list_pluck( $extra['refs'], 'code' ) ) ); ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}

/**
 * 更新状态。
 */
function site_chat_handle_set_status() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '权限不足' );
	}
	$session = isset( $_POST['session'] ) ? absint( $_POST['session'] ) : 0;
	check_admin_referer( 'site_chat_set_status_' . $session );

	$status = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'open';
	site_chat_session_set_status( $session, $status );

	wp_safe_redirect( admin_url( 'admin.php?page=site-chat&session=' . $session . '&sc_notice=' . rawurlencode( '状态已更新' ) ) );
	exit;
}
add_action( 'admin_post_site_chat_set_status', 'site_chat_handle_set_status' );

/**
 * 删除会话（连同消息）。
 */
function site_chat_handle_delete() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '权限不足' );
	}
	$session = isset( $_POST['session'] ) ? absint( $_POST['session'] ) : 0;
	check_admin_referer( 'site_chat_delete_' . $session );

	foreach ( site_chat_session_messages( $session ) as $message ) {
		wp_delete_post( $message['id'], true );
	}
	wp_delete_post( $session, true );

	wp_safe_redirect( admin_url( 'admin.php?page=site-chat' ) );
	exit;
}
add_action( 'admin_post_site_chat_delete', 'site_chat_handle_delete' );

/**
 * 导出 CSV（含完整对话）。
 */
function site_chat_handle_export() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '权限不足' );
	}
	check_admin_referer( 'site_chat_export' );

	$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
	$query  = site_chat_query_sessions(
		array(
			'status'         => $status,
			'posts_per_page' => 2000,
		)
	);

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=site-chat-' . gmdate( 'Ymd-His' ) . '.csv' );

	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // Excel 打开中文不乱码
	fputcsv( $out, array( '会话ID', '开始时间', '状态', '语言', '来源页', '访客邮箱', '消息数', '对话记录' ) );

	$labels = site_chat_status_labels();
	foreach ( $query->posts as $post ) {
		$sid   = (int) $post->ID;
		$lines = array();
		foreach ( site_chat_session_messages( $sid ) as $message ) {
			$lines[] = ( 'user' === $message['role'] ? '访客' : 'AI' ) . '：' . $message['content'];
		}
		$st = site_chat_session_status( $sid );

		fputcsv(
			$out,
			array(
				$sid,
				get_date_from_gmt( $post->post_date_gmt, 'Y-m-d H:i:s' ),
				isset( $labels[ $st ] ) ? $labels[ $st ] : $st,
				'en' === get_post_meta( $sid, '_sc_locale', true ) ? 'EN' : '中文',
				(string) get_post_meta( $sid, '_sc_page', true ),
				(string) get_post_meta( $sid, '_sc_email', true ),
				count( $lines ),
				implode( "\n", $lines ),
			)
		);
	}

	fclose( $out );
	exit;
}
add_action( 'admin_post_site_chat_export', 'site_chat_handle_export' );
