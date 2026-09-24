<?php
/**
 * 知识库：读取 data/knowledge/*.json，做两级检索。
 *
 * L1 结构化精确匹配：产品编号 / 关键词字段直接命中（最准、零成本）。
 * L2 关键词打分：拉丁词 + 中文 2/3-gram 命中标题、字段、正文分别计分。
 * 设计取舍见 方案H 第十节：先规则后向量，模型只负责组织语言，不负责提供数据。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 知识库目录（可被过滤器覆盖，方便别站换位置）。
 */
function site_chat_kb_dir() {
	$dir = SITE_CHAT_DIR . 'data/knowledge/';
	$dir = apply_filters( 'site_chat_kb_dir', $dir );
	return trailingslashit( $dir );
}

/**
 * 把一条 JSON 条目规范成内部文档结构。
 */
function site_chat_kb_normalize( $raw, $source ) {
	if ( ! is_array( $raw ) ) {
		return null;
	}

	$title    = isset( $raw['title'] ) ? (string) $raw['title'] : '';
	$code     = isset( $raw['code'] ) ? (string) $raw['code'] : '';
	$fields   = isset( $raw['fields'] ) && is_array( $raw['fields'] ) ? $raw['fields'] : array();
	$question = isset( $raw['question'] ) ? (string) $raw['question'] : '';
	$answer   = isset( $raw['answer'] ) ? (string) $raw['answer'] : '';
	$text     = isset( $raw['text'] ) ? (string) $raw['text'] : ( isset( $raw['content'] ) ? (string) $raw['content'] : '' );

	$type = 'generic';
	if ( '' !== $code ) {
		$type = 'product';
	} elseif ( '' !== $answer ) {
		$type = 'faq';
	}

	$body_parts = array( $title, $question, $answer, $text );
	foreach ( $fields as $label => $value ) {
		if ( is_array( $value ) ) {
			$value = implode( '、', $value );
		}
		$body_parts[] = $label . '：' . $value;
	}
	$body = trim( implode( "\n", array_filter( $body_parts, 'strlen' ) ) );

	$keywords = isset( $raw['keywords'] ) && is_array( $raw['keywords'] ) ? $raw['keywords'] : array();
	if ( ! $keywords ) {
		// 从编号、标题、字段值里自动派生关键词
		$auto = array( $code, $title );
		foreach ( $fields as $value ) {
			if ( is_array( $value ) ) {
				$auto = array_merge( $auto, $value );
			} elseif ( is_string( $value ) && strlen( $value ) <= 40 ) {
				$auto[] = $value;
			}
		}
		$keywords = array_values( array_filter( array_unique( $auto ), 'strlen' ) );
	}

	return array(
		'id'       => isset( $raw['id'] ) ? (string) $raw['id'] : ( $code ? 'p-' . $code : 'k-' . md5( $title . $source ) ),
		'type'     => $type,
		'code'     => $code,
		'title'    => '' !== $title ? $title : ( '' !== $code ? $code : $question ),
		'url'      => isset( $raw['url'] ) ? (string) $raw['url'] : '',
		'fields'   => $fields,
		'body'     => $body,
		'keywords' => $keywords,
		'source'   => $source,
	);
}

/**
 * 读取知识库（带进程内缓存）。
 *
 * @return array{ docs: array, draft: int, files: string[], product: int, faq: int }
 */
function site_chat_kb_load() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	$dir  = site_chat_kb_dir();
	$docs = array();
	$draft = 0;
	$files = array();
	$product = 0;
	$faq = 0;

	$paths = glob( $dir . '*.json' );
	if ( ! is_array( $paths ) ) {
		$paths = array();
	}
	sort( $paths );

	foreach ( $paths as $path ) {
		$raw_json = file_get_contents( $path );
		if ( false === $raw_json ) {
			continue;
		}
		$data = json_decode( $raw_json, true );
		if ( ! is_array( $data ) ) {
			site_chat_log( 'kb: JSON 解析失败 ' . basename( $path ) );
			continue;
		}

		// 支持两种顶层结构：直接数组，或 { items: [...] }
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : $data;
		if ( ! $items ) {
			continue;
		}

		$files[] = basename( $path );
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( isset( $item['status'] ) && 'draft' === $item['status'] ) {
				$draft++;
				continue;
			}
			$doc = site_chat_kb_normalize( $item, basename( $path ) );
			if ( ! $doc ) {
				continue;
			}
			if ( 'product' === $doc['type'] ) {
				$product++;
			} elseif ( 'faq' === $doc['type'] ) {
				$faq++;
			}
			$docs[] = $doc;
		}
	}

	$cache = array(
		'docs'    => $docs,
		'draft'   => $draft,
		'files'   => $files,
		'product' => $product,
		'faq'     => $faq,
	);

	return $cache;
}

/**
 * 后台用的知识库统计。
 */
function site_chat_kb_stats() {
	$kb = site_chat_kb_load();
	return array(
		'plain'   => count( $kb['docs'] ),
		'product' => $kb['product'],
		'faq'     => $kb['faq'],
		'draft'   => $kb['draft'],
		'files'   => $kb['files'],
	);
}

function site_chat_kb_documents() {
	$kb = site_chat_kb_load();
	return $kb['docs'];
}

/**
 * 从提问里提出检索词：拉丁词 + 中文 2/3-gram。
 *
 * 不引入分词库：中文用 2-gram / 3-gram 覆盖"耐磨层""防水""木纹"这类常见词，
 * 权重区分长短（3-gram 更具体，权重更高）。
 *
 * 虚词（"你们""怎么""可以"…）不算检索词：它们能跟知识库里任意一句凑上，
 * 会把"上门安装服务怎么收费"这种本不该命中的提问也放进来。
 */
function site_chat_kb_stopwords() {
	return array(
		'你们', '我们', '他们', '她们', '它们', '咱们',
		'这个', '那个', '哪个', '哪些', '什么', '怎么', '怎样', '如何',
		'可以', '能否', '是否', '请问', '一下', '有没', '没有',
		'需要', '提供', '多少', '一般', '大概', '目前', '现在',
		'就是', '然后', '因为', '所以', '但是', '如果', '还有', '以及',
		'帮我', '我想', '我要', '知道', '了解', '方面', '情况',
		'the', 'and', 'for', 'you', 'are', 'can', 'what', 'how', 'does', 'your',
	);
}

function site_chat_kb_tokens( $query ) {
	$q = mb_strtolower( (string) $query );
	$tokens = array();
	$stop   = site_chat_kb_stopwords();

	if ( preg_match_all( '/[a-z0-9][a-z0-9\.\-\/]{1,}/', $q, $matches ) ) {
		foreach ( $matches[0] as $word ) {
			if ( in_array( $word, $stop, true ) ) {
				continue;
			}
			$tokens[ $word ] = max( isset( $tokens[ $word ] ) ? $tokens[ $word ] : 0, 2 );
		}
	}

	$cjk = preg_replace( '/[^\x{4e00}-\x{9fff}]+/u', ' ', $q );
	foreach ( explode( ' ', trim( (string) $cjk ) ) as $segment ) {
		$length = mb_strlen( $segment );
		for ( $n = 2; $n <= 3; $n++ ) {
			for ( $i = 0; $i + $n <= $length; $i++ ) {
				$gram = mb_substr( $segment, $i, $n );
				if ( in_array( $gram, $stop, true ) ) {
					continue;
				}
				$tokens[ $gram ] = max( isset( $tokens[ $gram ] ) ? $tokens[ $gram ] : 0, $n - 1 );
			}
		}
	}

	return $tokens;
}

/**
 * 从提问里抽出"像产品编号"的片段（如 HS1228 / M0B68 / RXD-003）。
 */
function site_chat_kb_code_candidates( $query ) {
	$found = array();
	if ( preg_match_all( '/\b([A-Za-z]{1,4}[-_]?\d{2,5}[A-Za-z0-9\-]*)\b/', (string) $query, $matches ) ) {
		foreach ( $matches[1] as $candidate ) {
			$found[] = mb_strtolower( $candidate );
		}
	}
	return array_values( array_unique( $found ) );
}

/**
 * 检索。返回命中的文档（按分数降序）。
 */
function site_chat_kb_search( $query, $limit = 6 ) {
	$limit = max( 1, (int) $limit );
	$docs  = site_chat_kb_documents();
	if ( ! $docs ) {
		return array();
	}

	$tokens = site_chat_kb_tokens( $query );
	$codes  = site_chat_kb_code_candidates( $query );
	$scored = array();

	foreach ( $docs as $doc ) {
		$code   = mb_strtolower( $doc['code'] );
		$title  = mb_strtolower( $doc['title'] );
		$body   = mb_strtolower( $doc['body'] );
		$keys   = mb_strtolower( implode( ' ', $doc['keywords'] ) );
		$score  = 0;
		$hits   = 0;

		// L1：编号精确命中，直接给压倒性分数
		if ( '' !== $code && in_array( $code, $codes, true ) ) {
			$score += 80;
			$hits++;
		}

		// L2：关键词打分
		foreach ( $tokens as $token => $weight ) {
			if ( '' !== $code && $token === $code ) {
				$score += 80;
				$hits++;
				continue;
			}
			$matched = false;
			if ( '' !== $keys && false !== mb_strpos( $keys, $token ) ) {
				$score += 6 * $weight;
				$matched = true;
			}
			if ( false !== mb_strpos( $title, $token ) ) {
				$score += 4 * $weight;
				$matched = true;
			}
			if ( false !== mb_strpos( $body, $token ) ) {
				$score += 2 * $weight;
				$matched = true;
			}
			if ( $matched ) {
				$hits++;
			}
		}

		if ( $score > 0 ) {
			$scored[] = array(
				'doc'   => $doc,
				'score' => $score,
				'hits'  => $hits,
			);
		}
	}

	$min_score = (int) apply_filters( 'site_chat_kb_min_score', 8 );

	usort(
		$scored,
		function ( $a, $b ) {
			if ( $a['score'] === $b['score'] ) {
				return 0;
			}
			return $a['score'] < $b['score'] ? 1 : -1;
		}
	);

	$hits = array();
	foreach ( $scored as $row ) {
		if ( $row['score'] < $min_score ) {
			continue;
		}
		$hits[] = $row['doc'];
		if ( count( $hits ) >= $limit ) {
			break;
		}
	}

	return $hits;
}

/**
 * 把命中文档拼成提示词里的知识库段落（限制总长度，控住 token 成本）。
 */
function site_chat_kb_format( $docs, $max_chars = 3500 ) {
	if ( ! $docs ) {
		return '';
	}

	$blocks = array();
	$index  = 0;
	foreach ( $docs as $doc ) {
		$index++;
		$lines = array();
		$label = 'faq' === $doc['type'] ? '常见问题' : '产品';
		$lines[] = sprintf( '【%d】%s：%s', $index, $label, $doc['title'] );
		foreach ( $doc['fields'] as $name => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( '、', $value );
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			$lines[] = '- ' . $name . '：' . $value;
		}
		if ( '' !== $doc['body'] && ! $doc['fields'] ) {
			$lines[] = $doc['body'];
		}
		$blocks[] = implode( "\n", $lines );
	}

	$text = implode( "\n\n", $blocks );
	if ( mb_strlen( $text ) > $max_chars ) {
		$text = mb_substr( $text, 0, $max_chars ) . "\n…（内容过长，已截断）";
	}
	return $text;
}

/**
 * 命中的产品编号（前端/后台展示用）。
 */
function site_chat_kb_refs( $docs ) {
	$refs = array();
	foreach ( $docs as $doc ) {
		if ( '' === $doc['code'] ) {
			continue;
		}
		$refs[] = array(
			'code'  => $doc['code'],
			'title' => $doc['title'],
			'url'   => $doc['url'],
		);
		if ( count( $refs ) >= 4 ) {
			break;
		}
	}
	return $refs;
}

/**
 * 寒暄类消息：不走模型也不兜底，直接给固定回复。
 */
function site_chat_is_smalltalk( $message ) {
	$text = trim( mb_strtolower( (string) $message ) );
	if ( '' === $text ) {
		return false;
	}
	if ( mb_strlen( $text ) > 12 ) {
		return false;
	}
	$patterns = array( '你好', '您好', 'hi', 'hello', 'hey', '在吗', '在么', '有人吗', '谢谢', '多谢', '感谢', 'thanks', 'thank you', '再见', '拜拜', 'bye' );
	foreach ( $patterns as $pattern ) {
		if ( false !== mb_strpos( $text, $pattern ) ) {
			return true;
		}
	}
	return false;
}
