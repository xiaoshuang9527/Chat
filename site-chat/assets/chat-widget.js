/**
 * Site Chat 挂件（原生 JS，零依赖）。
 *
 * 投放模式：
 *   模式 1：WordPress 主题站由插件 wp_footer 自动注入；
 *   模式 2：无头站自己引入本文件，并预先定义 window.SiteChatConfig。
 *
 * 对外 API（供无头站在客户端路由切换时调用）：
 *   window.SiteChat.reinit(config)  销毁旧挂件并用新配置重建（中/英切换用）
 *   window.SiteChat.open() / .close()
 *
 * 配置项见 includes/widget.php 的 site_chat_widget_config()。
 */
(function () {
  "use strict";

  if (window.__siteChatLoaded) {
    return;
  }
  window.__siteChatLoaded = true;

  var STORE_SESSION = "site_chat_session";
  var STORE_ESCALATED = "site_chat_escalated";
  var STORE_OPEN = "site_chat_open";
  var STORE_AUTOSHOWN = "site_chat_autoshown";
  var STORE_DISMISSED = "site_chat_dismissed";
  var instance = null;

  /**
   * 建一个挂件实例。destroy() 后 DOM、样式、事件监听全部移除，可换配置重建。
   */
  function createWidget(cfg) {
    if (!cfg || !cfg.endpoint) {
      return null;
    }

    var S = cfg.strings || {};
    var THEME = cfg.theme || "#111827";
    var LOCALE = cfg.locale === "en" ? "en" : "zh";
    var POSITION = cfg.position === "left" ? "left" : "right";
    var sending = false;
    var sessionId = 0;
    var autoOpened = false;      // 这次对话是不是"自动展开"带起来的（记进会话，便于评估效果）
    var autoDone = false;        // 本页是否已经自动展开过
    var destroyed = false;
    var root, panel, body, input, sendBtn, bubble, styleEl;
    var docClickHandler = null;

    try {
      sessionId = parseInt(localStorage.getItem(STORE_SESSION) || "0", 10) || 0;
    } catch (e) {
      sessionId = 0;
    }

    function t(key, fallback) {
      return typeof S[key] === "string" && S[key] ? S[key] : fallback;
    }

    function store(store_type, key, value) {
      try {
        if (value === undefined) {
          return store_type.getItem(key);
        }
        store_type.setItem(key, value);
      } catch (e) {
        /* 隐私模式忽略 */
      }
      return null;
    }

    /* ---------- 样式 ---------- */
    var css = [
      ".sc-root{position:fixed;z-index:2147483000;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Hiragino Sans GB','Microsoft YaHei',sans-serif}",
      ".sc-bubble{position:fixed;bottom:24px;width:58px;height:58px;border-radius:50%;background:" + THEME + ";color:#fff;border:0;cursor:pointer;box-shadow:0 6px 22px rgba(0,0,0,.24);display:flex;align-items:center;justify-content:center;transition:transform .15s ease}",
      ".sc-bubble:hover{transform:scale(1.06)}",
      ".sc-bubble svg{width:26px;height:26px;fill:#fff}",
      ".sc-panel{position:fixed;bottom:94px;width:360px;max-width:calc(100vw - 32px);height:min(560px,calc(100vh - 130px));background:#fff;border-radius:16px;box-shadow:0 18px 50px rgba(0,0,0,.26);display:none;flex-direction:column;overflow:hidden}",
      ".sc-panel.sc-open{display:flex}",
      ".sc-head{background:" + THEME + ";color:#fff;padding:13px 16px;font-size:15px;font-weight:600;display:flex;justify-content:space-between;align-items:center;gap:10px}",
      ".sc-head button{background:transparent;border:0;color:#fff;font-size:20px;line-height:1;cursor:pointer;padding:0 2px}",
      ".sc-body{flex:1;overflow-y:auto;padding:14px;background:#f8f9fa}",
      ".sc-msg{margin-bottom:10px;display:flex}",
      ".sc-msg.sc-me{justify-content:flex-end}",
      ".sc-b{max-width:82%;padding:9px 12px;border-radius:12px;font-size:14px;line-height:1.55;white-space:pre-wrap;word-break:break-word}",
      ".sc-them .sc-b{background:#fff;border:1px solid #e5e7eb;color:#111827;border-bottom-left-radius:4px}",
      ".sc-me .sc-b{background:" + THEME + ";color:#fff;border-bottom-right-radius:4px}",
      ".sc-them.sc-typing .sc-b{color:#6b7280;font-style:italic}",
      ".sc-quick{display:flex;flex-wrap:wrap;gap:8px;margin:2px 0 12px}",
      ".sc-quick button{background:#fff;border:1px solid #d1d5db;border-radius:999px;padding:6px 12px;font-size:12.5px;color:#374151;cursor:pointer}",
      ".sc-quick button:hover{border-color:" + THEME + ";color:" + THEME + "}",
      ".sc-refs{font-size:12px;color:#6b7280;margin:-4px 0 10px}",
      ".sc-foot{border-top:1px solid #e5e7eb;padding:10px;background:#fff;display:flex;gap:8px;align-items:flex-end}",
      ".sc-input{flex:1;resize:none;border:1px solid #d1d5db;border-radius:10px;padding:9px 11px;font-size:14px;max-height:96px;font-family:inherit;line-height:1.4}",
      ".sc-input:focus{outline:none;border-color:" + THEME + "}",
      ".sc-send{background:" + THEME + ";color:#fff;border:0;border-radius:10px;padding:9px 14px;font-size:14px;cursor:pointer}",
      ".sc-send:disabled{opacity:.5;cursor:default}",
      ".sc-esc{background:#fffbeb;border:1px solid #fcd34d;border-radius:12px;padding:12px;margin-bottom:10px}",
      ".sc-esc h4{margin:0 0 6px;font-size:13.5px;color:#78350f}",
      ".sc-esc p{margin:0 0 8px;font-size:12.5px;color:#92400e}",
      ".sc-esc input{width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;margin-bottom:8px;box-sizing:border-box}",
      ".sc-esc button{background:" + THEME + ";color:#fff;border:0;border-radius:8px;padding:8px 14px;font-size:13px;cursor:pointer;width:100%}",
      ".sc-err{font-size:12px;color:#b91c1c;margin-bottom:8px}",
      ".sc-pos-left .sc-bubble,.sc-pos-left .sc-panel{left:24px}",
      ".sc-pos-right .sc-bubble,.sc-pos-right .sc-panel{right:24px}",
      "@media (max-width:520px){.sc-panel{left:12px!important;right:12px!important;width:auto;bottom:86px;height:min(72vh,540px)}.sc-bubble{width:52px;height:52px}}"
    ].join("");

    function injectStyles() {
      styleEl = document.createElement("style");
      styleEl.setAttribute("data-site-chat", "1");
      styleEl.appendChild(document.createTextNode(css));
      document.head.appendChild(styleEl);
    }

    /* ---------- 结构 ---------- */
    function build() {
      root = document.createElement("div");
      root.className = "sc-root sc-pos-" + POSITION;
      // 自动展开的诊断标记（也方便以后 QA 直接从 DOM 看状态）
      root.setAttribute("data-sc-auto", "pending");

      bubble = document.createElement("button");
      bubble.type = "button";
      bubble.className = "sc-bubble";
      bubble.setAttribute("aria-label", t("open", "在线咨询"));
      bubble.innerHTML =
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3C6.98 3 3 6.58 3 11c0 2.28 1.06 4.33 2.76 5.78-.1 1.02-.5 2.2-1.35 3.12-.2.22-.05.58.25.57 1.9-.09 3.28-.63 4.2-1.14A10.9 10.9 0 0 0 12 19c5.02 0 9-3.58 9-8s-3.98-8-9-8Z"/></svg>';

      panel = document.createElement("div");
      panel.className = "sc-panel";

      var head = document.createElement("div");
      head.className = "sc-head";
      var headTitle = document.createElement("span");
      headTitle.textContent = cfg.title || t("open", "在线咨询");
      var closeBtn = document.createElement("button");
      closeBtn.type = "button";
      closeBtn.setAttribute("aria-label", t("close", "收起"));
      closeBtn.innerHTML = "&times;";
      head.appendChild(headTitle);
      head.appendChild(closeBtn);

      body = document.createElement("div");
      body.className = "sc-body";

      var foot = document.createElement("div");
      foot.className = "sc-foot";
      input = document.createElement("textarea");
      input.className = "sc-input";
      input.rows = 1;
      input.placeholder = t("placeholder", "请输入您的问题…");
      sendBtn = document.createElement("button");
      sendBtn.type = "button";
      sendBtn.className = "sc-send";
      sendBtn.textContent = t("send", "发送");
      foot.appendChild(input);
      foot.appendChild(sendBtn);

      panel.appendChild(head);
      panel.appendChild(body);
      panel.appendChild(foot);
      root.appendChild(bubble);
      root.appendChild(panel);
      document.body.appendChild(root);

      bubble.addEventListener("click", function () {
        toggle();
      });
      closeBtn.addEventListener("click", close);
      sendBtn.addEventListener("click", submit);
      input.addEventListener("keydown", function (event) {
        if (event.key === "Enter" && !event.shiftKey) {
          event.preventDefault();
          submit();
        }
      });

      // 短代码 [site_chat] 输出的按钮
      docClickHandler = function (event) {
        var target = event.target;
        while (target && target !== document.body) {
          if (target.classList && target.classList.contains("site-chat-open")) {
            event.preventDefault();
            open();
            return;
          }
          target = target.parentNode;
        }
      };
      document.addEventListener("click", docClickHandler);

      // 切换语言会重建挂件，原本展开的面板保持展开（静默，不抢焦点）
      if (store(sessionStorage, STORE_OPEN) === "1") {
        open(true);
      }

      scheduleAutoOpen();
    }

    function open(silent) {
      panel.classList.add("sc-open");
      store(sessionStorage, STORE_OPEN, "1");
      autoDone = true;
      markAutoShown();
      if (!body.getAttribute("data-ready")) {
        body.setAttribute("data-ready", "1");
        greeting();
      }
      // 自动展开时绝不抢焦点：否则手机端会直接弹键盘、桌面端页面会跳到输入框
      if (!silent) {
        setTimeout(function () {
          input.focus();
        }, 80);
      }
    }

    function close() {
      panel.classList.remove("sc-open");
      store(sessionStorage, STORE_OPEN, "0");
      // 访客主动收起过 → 本次会话内不再自动展开（给了明确拒绝就别再弹）
      store(sessionStorage, STORE_DISMISSED, "1");
    }

    function toggle() {
      if (panel.classList.contains("sc-open")) {
        close();
      } else {
        open();
      }
    }

    /* ---------- 自动展开 ---------- */

    function isSmallScreen() {
      return !!(window.matchMedia && window.matchMedia("(max-width: 520px)").matches);
    }

    function autoFrequency() {
      var freq = cfg.autoOpenFrequency || "daily";
      return freq === "session" || freq === "always" ? freq : "daily";
    }

    function autoStoreType() {
      return autoFrequency() === "session" ? sessionStorage : localStorage;
    }

    function markAutoShown() {
      store(autoStoreType(), STORE_AUTOSHOWN, String(Date.now()));
    }

    /**
     * 是否允许在本页自动展开。任何一条不满足就不弹。
     */
    function autoSkipReason() {
      if (!cfg.autoOpen) return "config-off";
      if (autoDone) return "already-open";
      if (destroyed) return "destroyed";

      // 本次会话里收起过
      if (store(sessionStorage, STORE_DISMISSED) === "1") return "dismissed";

      // 已经聊过天的访客不再打扰
      if (store(localStorage, STORE_SESSION)) return "has-session";

      // 频率控制
      var freq = autoFrequency();
      if (freq !== "always") {
        var last = parseInt(store(autoStoreType(), STORE_AUTOSHOWN) || "0", 10) || 0;
        if (last) {
          if (freq === "session") return "shown-session";
          if (freq === "daily" && Date.now() - last < 24 * 60 * 60 * 1000) return "shown-today";
        }
      }

      // 页面排除：法务页这种"来读条文"的地方不弹
      var exclude = (cfg.autoOpenExclude || "").split(",");
      var path = window.location.pathname;
      for (var i = 0; i < exclude.length; i++) {
        var rule = (exclude[i] || "").trim();
        if (rule && path.indexOf(rule) !== -1) return "excluded:" + rule;
      }

      return "";
    }

    function autoOpenAllowed() {
      return autoSkipReason() === "";
    }

    function markAuto(value) {
      if (root) {
        root.setAttribute("data-sc-auto", String(value));
      }
    }

    function scheduleAutoOpen() {
      var reason = autoSkipReason();
      if (reason) {
        markAuto("skip:" + reason);
        return;
      }

      var delayMs = Math.max(0, parseInt(cfg.autoOpenDelay, 10) || 0) * 1000;
      var mobileMode = cfg.autoOpenMobile || "scroll";

      function fire() {
        var why = autoSkipReason();
        if (why) {
          markAuto("skip:" + why);
          return;
        }
        autoOpened = true;
        markAuto("fired");
        open(true);
      }

      if (isSmallScreen()) {
        if (mobileMode === "off") return;

        // 移动端默认等访客往下滚一段再展开，避免一进站就盖住首屏
        if (mobileMode === "scroll") {
          var scrollable = document.documentElement.scrollHeight - window.innerHeight;
          if (scrollable <= 0) {
            markAuto("armed:delay-shortpage");
            setTimeout(fire, delayMs);   // 页面太短没得滚，退回延时
            return;
          }
          markAuto("armed:scroll");
          var onScroll = function () {
            var total = document.documentElement.scrollHeight - window.innerHeight;
            if (total > 0 && window.scrollY / total >= 0.3) {
              window.removeEventListener("scroll", onScroll);
              fire();
            }
          };
          window.addEventListener("scroll", onScroll, { passive: true });
          return;
        }
      }

      markAuto("armed:delay");
      setTimeout(fire, delayMs);
    }

    /* ---------- 消息渲染 ---------- */
    function addMessage(text, mine, options) {
      options = options || {};
      var row = document.createElement("div");
      row.className = "sc-msg " + (mine ? "sc-me" : "sc-them") + (options.typing ? " sc-typing" : "");
      var bubbleEl = document.createElement("div");
      bubbleEl.className = "sc-b";
      bubbleEl.textContent = text;
      row.appendChild(bubbleEl);
      body.appendChild(row);
      body.scrollTop = body.scrollHeight;
      return row;
    }

    function addQuickQuestions() {
      var list = cfg.quick || [];
      if (!list.length) {
        return;
      }
      var wrap = document.createElement("div");
      wrap.className = "sc-quick";
      list.forEach(function (question) {
        var button = document.createElement("button");
        button.type = "button";
        button.textContent = question;
        button.addEventListener("click", function () {
          wrap.remove();
          send(question);
        });
        wrap.appendChild(button);
      });
      body.appendChild(wrap);
      body.scrollTop = body.scrollHeight;
    }

    function greeting() {
      if (cfg.greeting) {
        addMessage(cfg.greeting, false);
      }
      addQuickQuestions();
      if (store(localStorage, STORE_ESCALATED) === "1" && cfg.email) {
        addEscalateForm(true);
      }
    }

    /* ---------- 兜底表单 ---------- */
    function addEscalateForm(silent) {
      if (body.querySelector(".sc-esc")) {
        return;
      }
      var box = document.createElement("div");
      box.className = "sc-esc";

      var title = document.createElement("h4");
      title.textContent = t("escalateTitle", "留个邮箱，我们回复您");
      var hint = document.createElement("p");
      hint.textContent = (cfg.email ? cfg.email + " · " : "") + t("escalateHint", "也可以直接把问题发到邮箱。");
      var field = document.createElement("input");
      field.type = "email";
      field.placeholder = t("escalateEmail", "您的邮箱");
      var error = document.createElement("div");
      error.className = "sc-err";
      var submitBtn = document.createElement("button");
      submitBtn.type = "button";
      submitBtn.textContent = t("escalateSubmit", "提交");

      submitBtn.addEventListener("click", function () {
        var email = (field.value || "").trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
          error.textContent = t("escalateBad", "请填写正确的邮箱地址。");
          return;
        }
        submitBtn.disabled = true;
        error.textContent = "";

        fetch(cfg.escalateEndpoint, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ session_id: sessionId, email: email, locale: LOCALE })
        })
          .then(function (response) {
            return response.json();
          })
          .then(function (data) {
            if (destroyed) {
              return;
            }
            if (data && data.ok) {
              store(localStorage, STORE_ESCALATED, "1");
              box.remove();
              addMessage(data.reply || t("escalateOk", "已收到，我们会尽快回复您。"), false);
            } else {
              submitBtn.disabled = false;
              error.textContent = (data && data.error) || t("netError", "网络异常，请稍后再试。");
            }
          })
          .catch(function () {
            if (destroyed) {
              return;
            }
            submitBtn.disabled = false;
            error.textContent = t("netError", "网络异常，请稍后再试。");
          });
      });

      box.appendChild(title);
      box.appendChild(hint);
      box.appendChild(field);
      box.appendChild(error);
      box.appendChild(submitBtn);
      body.appendChild(box);
      body.scrollTop = body.scrollHeight;

      if (!silent) {
        setTimeout(function () {
          field.focus();
        }, 60);
      }
    }

    function addRefs(refs) {
      if (!cfg.showRefs || !refs || !refs.length) {
        return;
      }
      var codes = refs
        .map(function (item) {
          return item.code;
        })
        .filter(Boolean);
      if (!codes.length) {
        return;
      }
      var line = document.createElement("div");
      line.className = "sc-refs";
      line.textContent = t("refsLabel", "相关产品") + "：" + codes.join("、");
      body.appendChild(line);
      body.scrollTop = body.scrollHeight;
    }

    /* ---------- 发送 ---------- */
    function submit() {
      var text = (input.value || "").trim();
      if (!text) {
        return;
      }
      input.value = "";
      send(text);
    }

    function send(text) {
      if (sending) {
        return;
      }
      sending = true;
      sendBtn.disabled = true;
      addMessage(text, true);

      var typing = addMessage(t("thinking", "正在输入…"), false, { typing: true });

      fetch(cfg.endpoint, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          session_id: sessionId,
          message: text,
          locale: LOCALE,
          page: window.location.href,
          entry: autoOpened ? "auto" : "manual"
        })
      })
        .then(function (response) {
          return response.json();
        })
        .then(function (data) {
          if (destroyed) {
            return;
          }
          typing.remove();
          if (!data || !data.ok) {
            addMessage((data && data.error) || t("netError", "网络异常，请稍后再试。"), false);
            return;
          }
          if (data.session_id) {
            sessionId = data.session_id;
            store(localStorage, STORE_SESSION, String(sessionId));
          }
          addMessage(data.reply || "", false);
          addRefs(data.refs);
          if (data.escalate) {
            addEscalateForm();
          }
        })
        .catch(function () {
          if (destroyed) {
            return;
          }
          typing.remove();
          addMessage(t("netError", "网络异常，请稍后再试。"), false);
        })
        .finally(function () {
          sending = false;
          if (!destroyed) {
            sendBtn.disabled = false;
          }
        });
    }

    function destroy() {
      destroyed = true;
      if (docClickHandler) {
        document.removeEventListener("click", docClickHandler);
        docClickHandler = null;
      }
      if (root && root.parentNode) {
        root.parentNode.removeChild(root);
      }
      if (styleEl && styleEl.parentNode) {
        styleEl.parentNode.removeChild(styleEl);
      }
      root = panel = body = input = sendBtn = bubble = styleEl = null;
    }

    injectStyles();
    build();

    return {
      destroy: destroy,
      open: open,
      close: close,
      locale: LOCALE,
    };
  }

  function start(cfg) {
    if (instance) {
      instance.destroy();
      instance = null;
    }
    instance = createWidget(cfg || window.SiteChatConfig || {});
    return Boolean(instance);
  }

  // 供无头站在客户端路由切换（中/英）时重建挂件
  window.SiteChat = {
    reinit: function (config) {
      if (config) {
        window.SiteChatConfig = Object.assign({}, window.SiteChatConfig || {}, config);
      }
      return start(window.SiteChatConfig);
    },
    open: function () {
      if (instance) {
        instance.open();
      }
    },
    close: function () {
      if (instance) {
        instance.close();
      }
    },
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () {
      start(window.SiteChatConfig);
    });
  } else {
    start(window.SiteChatConfig);
  }
})();
