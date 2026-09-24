import { siteConfig } from "@/site.config";

import { ChatWidgetLoader } from "./chat-widget-loader";

/**
 * AI 客服接入（服务端组件）。
 *
 * 配置来自 WordPress 插件的 /site-chat/v1/config，文案与快捷问题只在后台维护一份；
 * 这里只把两个接口地址改写成同源代理，浏览器永远不直接连 WordPress。
 */

export type ChatWidgetConfig = {
  endpoint: string;
  escalateEndpoint: string;
  locale: string;
  title: string;
  greeting: string;
  position: string;
  theme: string;
  quick: string[];
  showRefs: boolean;
  email: string;
  strings: Record<string, string>;
};

const baseUrl = process.env.WORDPRESS_URL;

async function loadConfig(locale: "zh" | "en"): Promise<ChatWidgetConfig | null> {
  if (!baseUrl) return null;

  try {
    const response = await fetch(
      `${baseUrl}/wp-json/site-chat/v1/config?locale=${locale}`,
      { next: { revalidate: 300 } }
    );

    if (!response.ok) return null;

    const data = (await response.json()) as Partial<ChatWidgetConfig>;
    if (!data || !data.greeting) return null;

    return {
      ...(data as ChatWidgetConfig),
      endpoint: "/api/chat",
      escalateEndpoint: "/api/chat/escalate",
    };
  } catch {
    // WordPress 不可用时静默跳过，不阻塞页面渲染
    return null;
  }
}

/**
 * 中英两套配置一起给客户端，由客户端按 /en 前缀挑一套。
 * 这样根布局只需要挂一次，中文页与英文页各自拿到对应语言的文案。
 */
export async function SiteChat() {
  const [zh, en] = await Promise.all([loadConfig("zh"), loadConfig("en")]);

  const fallbackTitle = siteConfig.site_name;
  if (zh && !zh.title) zh.title = fallbackTitle;
  if (en && !en.title) en.title = fallbackTitle;

  if (!zh && !en) return null;

  return (
    <ChatWidgetLoader
      configs={{
        zh: zh as unknown as Record<string, unknown>,
        en: en as unknown as Record<string, unknown>,
      }}
    />
  );
}
