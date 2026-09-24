<?php
/**
 * 生成 AI 客服知识库的产品部分：把本站产品导成 site-chat 能读的 products.json。
 *
 * 这是 rococo-core 侧唯一与 AI 客服相关的文件——它只负责"本站数据 → 通用 JSON"，
 * 客服插件本身与本站业务零耦合（见 方案H 第九、十节）。
 *
 * 用法（沙箱外执行）：
 *   $env:ROCOCO_KB_OUT = "C:\...\rococo-site\data\knowledge\products.json"
 *   php wp-cli.phar --path=D:\WP\rococoeco eval-file includes/kb-export.php
 *
 * 不设 ROCOCO_KB_OUT 时写到插件目录下的 data/knowledge/products.json。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function rococo_kb_log( $message ) {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::log( $message );
	} else {
		echo $message . PHP_EOL;
	}
}

$out = getenv( 'ROCOCO_KB_OUT' );
if ( ! $out ) {
	$out = dirname( __DIR__ ) . '/data/knowledge/products.json';
}

$dir = dirname( $out );
if ( ! is_dir( $dir ) ) {
	wp_mkdir_p( $dir );
}

/**
 * 数组值转成顿号分隔的短句，空值返回空串（后面会被整体过滤掉）。
 */
function rococo_kb_join( $value ) {
	if ( is_array( $value ) ) {
		$value = array_filter( array_map( 'trim', array_map( 'strval', $value ) ), 'strlen' );
		return implode( '、', array_unique( $value ) );
	}
	return trim( (string) $value );
}

$query = new WP_Query(
	array(
		'post_type'      => ROCOCO_PRODUCT_PT,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	)
);

$items = array();

foreach ( $query->posts as $post ) {
	$data = rococo_get_product_data( (int) $post->ID );

	$full         = isset( $data['full'] ) && is_array( $data['full'] ) ? $data['full'] : array();
	$install_text = isset( $full['installText'] ) ? rococo_kb_join( $full['installText'] ) : '';

	$fields = array(
		'产品编号'   => rococo_kb_join( $data['code'] ),
		'产品线'     => rococo_kb_join( $data['line'] ),
		'系列'       => rococo_kb_join( $data['series'] ),
		'花色/纹理'  => rococo_kb_join( $data['texture'] ),
		'色系'       => rococo_kb_join( $data['tone'] ),
		'规格'       => rococo_kb_join( $data['sizes'] ),
		'耐磨层'     => rococo_kb_join( $data['wearLayer'] ),
		'适用空间'   => rococo_kb_join( $data['spaces'] ),
		'适用基层'   => rococo_kb_join( $data['substrates'] ),
		'产品特点'   => rococo_kb_join( $data['features'] ),
		'性能'       => rococo_kb_join( $data['performance'] ),
		'收边与配件' => rococo_kb_join( $data['accessories'] ),
		'安装与保养' => $install_text,
		'简介'       => rococo_kb_join( $data['summary'] ),
	);
	$fields = array_filter( $fields, 'strlen' );

	$keywords = array_merge(
		array( $data['code'], $data['line'] ),
		(array) $data['series'],
		(array) $data['texture'],
		(array) $data['tone'],
		(array) $data['spaces']
	);
	$keywords = array_values( array_filter( array_unique( array_map( 'strval', $keywords ) ), 'strlen' ) );

	$items[] = array(
		'id'       => 'product-' . (int) $post->ID,
		'type'     => 'product',
		'code'     => (string) $data['code'],
		'title'    => (string) $post->post_title,
		'url'      => (string) get_permalink( $post ),
		'fields'   => $fields,
		'keywords' => $keywords,
	);
}

$payload = array(
	'generated_at' => current_time( 'mysql' ),
	'source'       => 'rococo-core',
	'count'        => count( $items ),
	'items'        => $items,
);

$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
$ok   = file_put_contents( $out, $json );

if ( false === $ok ) {
	rococo_kb_log( '写入失败：' . $out );
	return;
}

rococo_kb_log( sprintf( '已写出 %d 款产品 → %s（%.1f KB）', count( $items ), $out, strlen( $json ) / 1024 ) );

$missing = array();
foreach ( $items as $item ) {
	if ( '' === $item['code'] ) {
		$missing[] = $item['id'];
	}
}
if ( $missing ) {
	rococo_kb_log( '注意：以下产品没有编号：' . implode( '、', $missing ) );
}
