import { NextResponse } from "next/server";

/**
 * AI 客服代理：浏览器只跟本站同源接口说话，WordPress 地址与模型 Key 都留在服务端。
 * 与 app/api/inquiry 同一套写法。
 */
const baseUrl = process.env.WORDPRESS_URL;

export async function POST(request: Request) {
  if (!baseUrl) {
    return NextResponse.json(
      { ok: false, error: "客服服务未配置。" },
      { status: 500 }
    );
  }

  try {
    const payload = await request.json();
    const forwardedFor =
      request.headers.get("x-forwarded-for") || request.headers.get("x-real-ip") || "";

    const response = await fetch(`${baseUrl}/wp-json/site-chat/v1/chat`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        ...(forwardedFor ? { "X-Forwarded-For": forwardedFor } : {}),
      },
      body: JSON.stringify(payload),
      cache: "no-store",
    });

    const data = await response.json();
    return NextResponse.json(data, { status: response.status });
  } catch {
    return NextResponse.json(
      { ok: false, error: "客服暂时无法应答，请稍后再试。" },
      { status: 500 }
    );
  }
}
