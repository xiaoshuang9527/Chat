import { NextResponse } from "next/server";

/** 访客留邮箱转人工：同样由服务端转发到 WordPress。 */
const baseUrl = process.env.WORDPRESS_URL;

export async function POST(request: Request) {
  if (!baseUrl) {
    return NextResponse.json({ ok: false, error: "客服服务未配置。" }, { status: 500 });
  }

  try {
    const payload = await request.json();
    const forwardedFor =
      request.headers.get("x-forwarded-for") || request.headers.get("x-real-ip") || "";

    const response = await fetch(`${baseUrl}/wp-json/site-chat/v1/escalate`, {
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
      { ok: false, error: "提交失败，请稍后再试。" },
      { status: 500 }
    );
  }
}
