# Site Chat · WordPress AI 智能售前客服

一个可移植的 WordPress 插件：给网站配一位「懂产品」的 AI 售前客服。
它能按你自己的知识库回答产品与业务问题；**答不上来时不会编造**，而是引导客户留邮箱，
所有对话记录都存在你自己的 WordPress 后台。

> 设计原则：知识库决定它知道什么，提示词决定它不乱说什么。
> 模型层走 OpenAI 兼容协议，换供应商只改两个字段；API Key 只存服务端。

## 特性

- **知识库问答**：结构化精确匹配（产品编号等）+ 关键词打分，先规则后向量
- **不编造**：知识库里没有的内容一律回答「需要同事确认」，并弹出留邮箱表单
- **对话全量入库**：后台可看会话列表、完整对话、状态标记（进行中 / 待跟进 / 已处理）、CSV 导出
- **通知**：客户留下邮箱时给兜底邮箱发通知邮件（需站点已配 SMTP）
- **挂件三种投放**：普通 WordPress 主题站自动注入、无头站独立 JS、短代码
- **中英双语**：配置接口按语言返回对应文案，无头站切换语言时重建挂件
- **限流与用量控制**：按 IP 每分钟 / 每天限流，可设每月模型调用上限

## 目录结构

```
site-chat/                             WordPress 插件本体（整个目录拷进 wp-content/plugins/）
  site-chat.php                        插件入口、常量、启用/停用钩子
  includes/settings.php                设置页：模型接入、提示词、知识库、挂件、限流
  includes/store.php                   数据层：会话 CPT + 每轮消息 CPT
  includes/kb.php                      知识库加载与检索（L1 + L2）
  includes/llm.php                     OpenAI 兼容调用与用量计数
  includes/rest.php                    REST：chat / escalate / config / widget.js
  includes/widget.php                  挂件投放（自动注入 / 短代码 / 配置）
  includes/admin-chat.php              后台会话列表、详情、状态、CSV 导出
  assets/chat-widget.js                挂件脚本（原生 JS，零依赖，可重建）
  data/knowledge/faq.json              知识库示例（人工问答）
docs/方案H-AI售前客服.md               完整方案与实施记录（含跨站复用、技术选型、实测数据）
docs/知识库格式说明.md                 知识库 JSON 的字段与规则
integrations/nextjs/                   无头站（Next.js）接入
  components/chat/site-chat.tsx        服务端读插件配置（中英两套）
  components/chat/chat-widget-loader.tsx  按语言注入并重建挂件
  app/api/chat/route.ts                同源代理：浏览器不直连 WordPress
  app/api/chat/escalate/route.ts       留邮箱的代理
integrations/wordpress-bridge/kb-export.php  站点数据 → 知识库 JSON 的导出脚本示例
tools/chat-open-and-ask.js             挂件回归验证脚本（配合 Chrome/Edge DevTools 协议）
```

## 快速开始

### 1. 装插件

把 `site-chat/` 整个目录放到 `wp-content/plugins/`，然后在后台「插件」页启用。

### 2. 配模型

后台 **「AI 客服 → 客服设置 → 一、模型接入」**：

| 项 | 说明 |
| --- | --- |
| 供应商预设 | DeepSeek / 通义千问 / 智谱 GLM / Kimi / OpenAI / 自定义（任意 OpenAI 兼容接口） |
| 接口地址 | 选预设会自动填，例如 `https://api.deepseek.com/v1` |
| API Key | 只存站点数据库，永远不会下发到浏览器 |
| 模型名 | 例如 `deepseek-chat` |

保存后点页面最下方的 **「测试模型连接」** 自检，返回「模型连接正常」即可。

### 3. 放知识库

把 JSON 文件放进 `wp-content/plugins/site-chat/data/knowledge/`，插件自动加载（目录可用过滤器 `site_chat_kb_dir` 改）。

- **人工问答**：按 `faq.json` 的格式维护。`status: "draft"` 的条目默认**不参与回答**——业务口径没确认前留 draft，客服遇到相关问题会自动转邮件兜底，而不是编造。
- **产品/业务数据**：写一个导出脚本生成，参考 `integrations/wordpress-bridge/kb-export.php`（把文章/产品导成带 `code` / `fields` / `keywords` 的 JSON）。

格式细节见 `docs/知识库格式说明.md`。

## 挂件三种投放方式

| 模式 | 适用站 | 做法 |
| --- | --- | --- |
| 1 | 普通 WordPress 主题站 | 设置页打开「自动注入挂件」，全站右下角生效，**不需要改主题** |
| 2 | 无头站 / 其他前端 | 引入 `GET /wp-json/site-chat/v1/widget.js`；或把 `assets/chat-widget.js` 放到前端 `public/` 下，先设置 `window.SiteChatConfig` 再加载 |
| 3 | 只想放某个页面 | 用短代码 `[site_chat]` |

模式 2 的配置示例：

```html
<script>
window.SiteChatConfig = {
  endpoint: "/api/chat",                 // 建议走自己的同源代理
  escalateEndpoint: "/api/chat/escalate",
  locale: "zh"
};
</script>
<script src="/site-chat/chat-widget.js" defer></script>
```

其余文案（标题、问候语、快捷问题、界面按钮）由 `GET /site-chat/v1/config` 提供，在后台维护一份即可。
无头站如果是客户端路由（Next.js 等），**切换语言时要调用 `window.SiteChat.reinit(config)` 重建挂件**，否则挂件会一直停留在首次加载时的语言。

## REST 接口

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| POST | `/wp-json/site-chat/v1/chat` | 一轮问答 |
| POST | `/wp-json/site-chat/v1/escalate` | 访客留邮箱，标记待跟进并通知 |
| GET | `/wp-json/site-chat/v1/config` | 挂件配置（`?locale=zh|en`） |
| GET | `/wp-json/site-chat/v1/widget.js` | 独立挂件脚本 |

`chat` 请求与响应：

```json
// 请求
{ "session_id": 0, "message": "M0B68 是什么花色？", "locale": "zh", "page": "https://example.com/products" }

// 响应
{
  "ok": true,
  "session_id": 42,
  "reply": "M0B68 是橡木纹理，属于深色系……",
  "escalate": false,
  "email": "",
  "refs": [{ "code": "M0B68", "title": "M0B68 橡木 深色", "url": "…" }],
  "reason": ""
}
```

`escalate: true` 时前端应弹出留邮箱表单；`reason` 取值：`no_kb`（知识库没有）、`llm_error`（模型不可用）、`rate_limit`（被限流）、`quota`（超出月度上限）。

## 数据模型

| 内容类型 | 存什么 |
| --- | --- |
| `sc_chat_session` | 一次会话：meta 存语言、来源页、状态、访客邮箱、访客指纹、消息数 |
| `sc_chat_message` | 每一轮消息，用 `post_parent` 挂到会话；`post_title` 是角色（user/assistant） |

刻意**不把整段对话塞进一个 meta 字段**：meta 值会随对话变长而膨胀，后台详情页会越来越慢。

## 检索策略

| 层级 | 做法 | 适用 |
| --- | --- | --- |
| L1 | 产品编号等字段精确匹配（权重最高） | 「M0B68 是什么花色？」 |
| L2 | 关键词打分：中文 2/3-gram + 拉丁词，标题 > 关键词 > 正文，带虚词过滤 | 「有没有浅色的木纹地板？」 |
| L3 | 向量检索（**未实现**，预留） | 口语化、绕圈子的问法 |

最低命中分数可用过滤器调整：`site_chat_kb_min_score`（默认 8）。
**先规则后向量**是有意的取舍：按编号/规格查这类问题占大多数，结构化匹配比向量更准、更便宜、也更好排查；模型只负责组织语言，不负责提供数据——这一条同时压住了幻觉和成本。

## 安全

- API Key 只存 `wp_options`，只由服务端发往模型服务；前端调自己的同源接口
- 按 IP 限流（默认 10 次/分钟、200 次/天），按访客指纹校验会话归属
- 可设每月模型调用上限，避免异常流量打爆账单
- 只把最近 N 轮对话带进提示词（默认 6 轮）
- 话题兜底：命中不了知识库就不调模型，直接走邮件兜底

## 换到另一个 WordPress 站

1. 拷 `site-chat/` → 启用插件
2. 填 API Key / 提示词 / 兜底邮箱
3. 换 `data/knowledge/` 里的 JSON

普通主题站到此为止；无头站再多一步：接入 `integrations/nextjs/`。

## 已知限制

- 未接入向量检索，口语化提问靠 2/3-gram 命中，效果取决于知识库措辞
- 英文文案需在设置页单独维护（留空自动回退中文）
- 无头站切换语言会重建挂件，界面上的旧气泡会被清空（后端沿用同一会话，记录不丢）
- 通知邮件依赖站点自身的 SMTP 配置

## 许可

GPL-2.0-or-later（见插件头声明）。
