/**
 * Open the AI chat widget, ask one question, and report the real page state.
 * Use with: capture.ps1 -Url http://192.168.1.68:3001/ -EvalFile chat-open-and-ask.js -Suffix "-chat"
 *
 * NOTE: keep this file pure ASCII. PowerShell 5.1 reads a .js file without a BOM as ANSI,
 * which would mangle Chinese text before it reaches the page, so the Chinese question
 * below is written with \u escapes.
 */
new Promise((resolve) => {
  const startedAt = Date.now();
  const question = "M0B68 \u662f\u4ec0\u4e48\u82b1\u8272\uff1f"; // M0B68 是什么花色？
  const timer = setInterval(() => {
    const bubble = document.querySelector(".sc-bubble");

    if (!bubble) {
      if (Date.now() - startedAt > 15000) {
        clearInterval(timer);
        resolve({
          widgetLoaded: false,
          scriptTagPresent: Boolean(document.querySelector("script[data-site-chat-widget]")),
          configPresent: Boolean(window.SiteChatConfig),
          note: "挂件未出现",
        });
      }
      return;
    }

    clearInterval(timer);
    bubble.click();

    setTimeout(() => {
      const input = document.querySelector(".sc-input");
      const send = document.querySelector(".sc-send");
      if (input && send) {
        input.value = question;
        input.dispatchEvent(new Event("input", { bubbles: true }));
        send.click();
      }

      setTimeout(() => {
        const body = document.querySelector(".sc-body");
        resolve({
          widgetLoaded: true,
          configPresent: Boolean(window.SiteChatConfig),
          locale: window.SiteChatConfig ? window.SiteChatConfig.locale : "",
          endpoint: window.SiteChatConfig ? window.SiteChatConfig.endpoint : "",
          panelOpen: Boolean(document.querySelector(".sc-panel.sc-open")),
          messageCount: document.querySelectorAll(".sc-msg").length,
          quickCount: document.querySelectorAll(".sc-quick button").length,
          escalateFormVisible: Boolean(document.querySelector(".sc-esc")),
          lastText: body ? body.innerText.slice(-320) : "",
        });
      }, 6000);
    }, 700);
  }, 300);
});
