"use client";

import { usePathname } from "next/navigation";
import { useEffect, useRef } from "react";

/**
 * 把配置挂到 window.SiteChatConfig，再引入挂件脚本。
 * 脚本本身是插件里的 assets/chat-widget.js，经 install.ps1 同步到 public/site-chat/。
 *
 * 英文站（/en 前缀）用英文配置，其余用中文配置。中英切换是客户端路由，
 * 挂件脚本只会加载一次，所以语言变化时调用 window.SiteChat.reinit() 重建挂件。
 */
export function ChatWidgetLoader({
  configs,
}: {
  configs: { zh?: Record<string, unknown>; en?: Record<string, unknown> };
}) {
  const pathname = usePathname();
  const isEnglish = pathname === "/en" || (pathname || "").startsWith("/en/");
  const locale = isEnglish ? "en" : "zh";
  const config = (isEnglish ? configs.en : configs.zh) || configs.zh || configs.en;

  // 只按语言触发重建，配置本身放 ref 里读，避免 props 引用变化导致重复初始化
  const configRef = useRef(config);
  configRef.current = config;

  useEffect(() => {
    if (typeof window === "undefined") return;

    const current = configRef.current;
    if (!current) return;

    const globalWindow = window as unknown as {
      SiteChatConfig?: Record<string, unknown>;
      SiteChat?: { reinit: (config?: Record<string, unknown>) => boolean };
    };
    globalWindow.SiteChatConfig = { ...(globalWindow.SiteChatConfig || {}), ...current };

    // 脚本已就绪：直接按当前语言重建（切语言时走这里）
    if (globalWindow.SiteChat) {
      globalWindow.SiteChat.reinit(current);
      return;
    }

    // 脚本还在下载：等它自己用 window.SiteChatConfig 启动即可
    if (document.querySelector("script[data-site-chat-widget]")) return;

    const script = document.createElement("script");
    script.src = "/site-chat/chat-widget.js";
    script.defer = true;
    script.setAttribute("data-site-chat-widget", "1");
    document.body.appendChild(script);
  }, [locale]);

  return null;
}
