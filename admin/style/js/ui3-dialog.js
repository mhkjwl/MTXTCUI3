/* ============================================================
   UI3 原生对话框 v6 —— 照搬 deepseek 新示例
   成功/失败卡片(透明遮罩+SVG描边动画) + 二次确认弹窗(暗色遮罩+emoji)
   拦截 Swal.fire，API 兼容，无需改业务代码
   ============================================================ */
(function () {
  'use strict';

  /* ---------- 状态图标 SVG（描边动画，照搬示例） ---------- */
  function statusSvg(color, glow, pathD, sparkD) {
    return '<svg viewBox="0 0 400 400"><circle class="ui-circle-bg" cx="200" cy="200" r="160"/>' +
      '<circle class="ui-glow-circle" cx="200" cy="200" r="160"/>' +
      '<path class="ui-status-path" d="' + pathD + '"/>' +
      '<circle class="ui-sparkle s1" cx="310" cy="80" r="5"/>' +
      '<circle class="ui-sparkle s2" cx="70" cy="300" r="4"/>' +
      '<circle class="ui-sparkle s3" cx="80" cy="90" r="4"/>' +
      '<style>.ui-circle-bg{stroke:' + color + ';fill:none;stroke-width:10;stroke-linecap:round;transform-origin:center;transform:scale(0);transition:transform .5s cubic-bezier(.34,1.56,.64,1)}' +
      '.ui-active .ui-circle-bg{transform:scale(1);transition-delay:.1s}' +
      '.ui-glow-circle{stroke:' + glow + ';fill:none;stroke-width:5;opacity:0;transform-origin:center;transform:scale(.8);transition:opacity .6s ease,transform .6s ease}' +
      '.ui-active .ui-glow-circle{opacity:.5;transform:scale(1.1);transition-delay:.6s}' +
      '.ui-status-path{stroke:' + color + ';fill:none;stroke-width:14;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:360;stroke-dashoffset:360;transition:stroke-dashoffset .7s cubic-bezier(.34,1.56,.64,1);filter:drop-shadow(0 0 8px rgba(0,0,0,.08))}' +
      '.ui-active .ui-status-path{stroke-dashoffset:0;transition-delay:.35s}' +
      '.ui-sparkle{fill:' + (sparkD || '#94a3b8') + ';opacity:0;transform:scale(0);transition:opacity .4s ease,transform .4s cubic-bezier(.34,1.56,.64,1)}' +
      '.ui-active .s1{opacity:1;transform:scale(1);transition-delay:.9s}' +
      '.ui-active .s2{opacity:1;transform:scale(1);transition-delay:1s}' +
      '.ui-active .s3{opacity:1;transform:scale(1);transition-delay:1.1s}</style></svg>';
  }

  var STATUS_ICONS = {
    success: statusSvg('#10b981', '#34d399', 'M 120 230 L 180 290 L 300 160'),
    error:   statusSvg('#dc2626', '#f87171', 'M 135 135 L 265 265 M 265 135 L 135 265'),
    warning: statusSvg('#f59e0b', '#fbbf24', 'M 200 130 L 200 240 M 200 285 L 200 290'),
    info:    statusSvg('#0ea5e9', '#38bdf8', 'M 200 190 L 200 280 M 200 120 L 200 130')
  };

  /* ---------- 确认弹窗 emoji ---------- */
  var CONFIRM_EMOJI = { question: '❓', warning: '⚠️', info: 'ℹ️', success: '✅', error: '❌' };

  /* ---------- 样式 ---------- */
  var CSS =
    '.ui-overlay{position:fixed;top:0;left:0;width:100%;height:100%;z-index:99999;display:flex;justify-content:center;align-items:center;pointer-events:none}' +
    /* 状态卡片层（透明遮罩） */
    '.ui-status{position:absolute;top:0;left:0;width:100%;height:100%;display:flex;justify-content:center;align-items:center;background:transparent;pointer-events:none;opacity:0;visibility:hidden;transition:opacity .4s ease,visibility .4s ease}' +
    '.ui-status.ui-active{opacity:1;visibility:visible;pointer-events:auto}' +
    '.ui-status-card{background:var(--surface,#fff);border-radius:32px;padding:40px 50px 44px;box-shadow:0 30px 60px rgba(0,0,0,.2),0 10px 30px rgba(0,0,0,.08);transform:scale(.6) translateY(30px);transition:transform .5s cubic-bezier(.34,1.56,.64,1),opacity .4s ease;opacity:0;display:flex;flex-direction:column;align-items:center;max-width:420px;width:90%;pointer-events:auto;border:1px solid var(--border,#e2e8f0)}' +
    '.ui-status.ui-active .ui-status-card{transform:scale(1) translateY(0);opacity:1}' +
    '.ui-status-icon{width:130px;height:130px;margin-bottom:24px}' +
    '.ui-status-icon svg{width:100%;height:100%;display:block;overflow:visible}' +
    '.ui-status-title{color:var(--text,#1e293b);font-size:24px;font-weight:700;letter-spacing:1px;margin-bottom:4px;opacity:0;transform:translateY(12px);transition:opacity .5s ease,transform .5s ease}' +
    '.ui-status.ui-active .ui-status-title{opacity:1;transform:translateY(0);transition-delay:.45s}' +
    '.ui-status-sub{color:var(--text-2,#64748b);font-size:15px;letter-spacing:.5px;opacity:0;transform:translateY(8px);transition:opacity .5s ease,transform .5s ease}' +
    '.ui-status.ui-active .ui-status-sub{opacity:1;transform:translateY(0);transition-delay:.6s}' +
    '.ui-status-actions{margin-top:24px;opacity:0;transform:translateY(10px);transition:opacity .4s ease,transform .4s ease}' +
    '.ui-status.ui-active .ui-status-actions{opacity:1;transform:translateY(0);transition-delay:.75s}' +
    '.ui-close-btn{padding:10px 40px;font-size:15px;font-weight:600;background:var(--surface-2,#f1f5f9);border:none;border-radius:40px;cursor:pointer;letter-spacing:.5px;color:var(--text,#334155);box-shadow:0 4px 12px rgba(0,0,0,.04);transition:all .2s ease}' +
    '.ui-close-btn:hover{background:var(--border,#e2e8f0);box-shadow:0 6px 20px rgba(0,0,0,.08);transform:translateY(-2px) scale(1.02)}' +
    '.ui-close-btn.danger{color:#dc2626;background:#fef2f2}' +
    '.ui-close-btn.danger:hover{background:#fee2e2}' +
    /* 确认弹窗层（暗色模糊遮罩） */
    '.ui-confirm{position:absolute;top:0;left:0;width:100%;height:100%;display:flex;justify-content:center;align-items:center;background:rgba(0,0,0,.45);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);opacity:0;visibility:hidden;transition:opacity .3s ease,visibility .3s ease}' +
    '.ui-confirm.ui-active{opacity:1;visibility:visible;pointer-events:auto}' +
    '.ui-confirm-card{background:var(--surface,#fff);border-radius:28px;padding:40px 44px 36px;max-width:440px;width:90%;box-shadow:0 40px 80px rgba(0,0,0,.25);transform:scale(.8) translateY(30px);transition:transform .4s cubic-bezier(.34,1.56,.64,1),opacity .3s ease;opacity:0;text-align:center;position:relative;border:1px solid var(--border,#e2e8f0)}' +
    '.ui-confirm.ui-active .ui-confirm-card{transform:scale(1) translateY(0);opacity:1}' +
    '.ui-confirm-close{position:absolute;top:18px;right:22px;font-size:24px;color:#94a3b8;cursor:pointer;line-height:1;transition:color .2s;background:none;border:none}' +
    '.ui-confirm-close:hover{color:#475569}' +
    '.ui-confirm-emoji{font-size:52px;margin-bottom:12px;display:block}' +
    '.ui-confirm-title{font-size:22px;font-weight:700;color:var(--text,#1e293b);margin-bottom:8px;margin-top:0}' +
    '.ui-confirm-text{font-size:16px;color:var(--text-2,#475569);line-height:1.6;margin-bottom:28px;margin-top:0;word-break:break-word}' +
    '.ui-confirm-actions{display:flex;gap:14px;justify-content:center;flex-wrap:wrap}' +
    '.ui-btn{padding:12px 36px;font-size:16px;font-weight:600;border:none;border-radius:60px;cursor:pointer;transition:transform .2s ease,box-shadow .2s ease;letter-spacing:.5px;min-width:120px;outline:none}' +
    '.ui-btn:hover{transform:translateY(-2px)}' +
    '.ui-btn:active{transform:scale(.96)}' +
    '.ui-btn-primary{background:#6366f1;color:#fff;box-shadow:0 6px 20px rgba(99,102,241,.35)}' +
    '.ui-btn-primary:hover{box-shadow:0 10px 28px rgba(99,102,241,.45)}' +
    '.ui-btn-primary.danger{background:#ef4444;box-shadow:0 6px 20px rgba(239,68,68,.35)}' +
    '.ui-btn-primary.success{background:#10b981;box-shadow:0 6px 20px rgba(16,185,129,.35)}' +
    '.ui-btn-cancel{background:var(--surface-2,#f1f5f9);color:var(--text,#334155);box-shadow:0 4px 12px rgba(0,0,0,.05)}' +
    '.ui-btn-cancel:hover{background:var(--border,#e2e8f0);box-shadow:0 6px 16px rgba(0,0,0,.08)}';

  /* ---------- 注入 ---------- */
  var styleEl = document.createElement('style');
  styleEl.textContent = CSS;
  document.head.appendChild(styleEl);

  var overlayEl = null;
  var statusEl = null, confirmEl = null;
  var currentResolve = null, autoTimer = null;
  var currentOpts = null; // 当前弹窗参数（用于 allowOutsideClick/allowEscapeKey 判断）

  function init() {
    if (overlayEl) return;
    var d = document.createElement('div');
    d.innerHTML =
      '<div class="ui-overlay" id="uiOverlay">' +
        '<div class="ui-status" id="uiStatus">' +
          '<div class="ui-status-card">' +
            '<div class="ui-status-icon" id="uiStatusIcon"></div>' +
            '<div class="ui-status-title" id="uiStatusTitle"></div>' +
            '<div class="ui-status-sub" id="uiStatusSub"></div>' +
            '<div class="ui-status-actions" id="uiStatusActions"></div>' +
          '</div>' +
        '</div>' +
        '<div class="ui-confirm" id="uiConfirm">' +
          '<div class="ui-confirm-card">' +
            '<button type="button" class="ui-confirm-close" id="uiConfirmClose">✕</button>' +
            '<span class="ui-confirm-emoji" id="uiConfirmEmoji"></span>' +
            '<h3 class="ui-confirm-title" id="uiConfirmTitle"></h3>' +
            '<p class="ui-confirm-text" id="uiConfirmText"></p>' +
            '<div class="ui-confirm-actions" id="uiConfirmActions"></div>' +
          '</div>' +
        '</div>' +
      '</div>';
    overlayEl = d.firstElementChild;
    statusEl = overlayEl.querySelector('#uiStatus');
    confirmEl = overlayEl.querySelector('#uiConfirm');
    document.body.appendChild(overlayEl);

    // 状态卡片：点击遮罩空白关闭（allowOutsideClick:false 时禁止）
    statusEl.addEventListener('click', function (e) {
      if (e.target !== statusEl) return;
      if (currentOpts && currentOpts.allowOutsideClick === false) return;
      hideStatus('backdrop');
    });
    // 确认弹窗：点击遮罩关闭（取消）（allowOutsideClick:false 时禁止）
    confirmEl.addEventListener('click', function (e) {
      if (e.target !== confirmEl) return;
      if (currentOpts && currentOpts.allowOutsideClick === false) return;
      dismissConfirm('backdrop');
    });
    confirmEl.querySelector('#uiConfirmClose').addEventListener('click', function () { dismissConfirm('close'); });
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      // allowEscapeKey:false 时禁止 Esc 关闭
      if (currentOpts && currentOpts.allowEscapeKey === false) return;
      if (confirmEl.classList.contains('ui-active')) dismissConfirm('esc');
      else if (statusEl.classList.contains('ui-active')) hideStatus('esc');
    });
  }

  function clearTimer() { clearTimeout(autoTimer); autoTimer = null; }

  /* ---------- 状态卡片（成功无按钮自动关 / 失败带按钮） ---------- */
  function showStatus(opts) {
    return new Promise(function (resolve) {
      init();
      clearTimer();
      confirmEl.classList.remove('ui-active');
      currentResolve = resolve;
      currentOpts = opts;

      statusEl.classList.remove('ui-active');

      // 图标
      var icon = STATUS_ICONS[opts.icon] || STATUS_ICONS.info;
      document.getElementById('uiStatusIcon').innerHTML = icon;

      // 标题 / 副标题
      document.getElementById('uiStatusTitle').textContent = opts.title || '';
      var sub = document.getElementById('uiStatusSub');
      if (opts.text) { sub.textContent = opts.text; sub.style.display = ''; }
      else if (opts.html) { sub.innerHTML = opts.html; sub.style.display = ''; }
      else { sub.textContent = ''; sub.style.display = 'none'; }

      // 按钮（error/warning/info 带"知道了"，success 无按钮）
      var actions = document.getElementById('uiStatusActions');
      actions.innerHTML = '';
      var withBtn = !(opts.showConfirmButton === false);
      if (withBtn) {
        var btn = document.createElement('button');
        btn.className = 'ui-close-btn' + (opts.icon === 'error' ? ' danger' : '');
        btn.textContent = opts.confirmButtonText || '知道了';
        btn.addEventListener('click', function () {
          statusEl.classList.remove('ui-active');
          clearTimer();
          resolve({ isConfirmed: true, isDismissed: false });
          currentResolve = null;
        });
        actions.appendChild(btn);
      }

      void statusEl.offsetWidth;
      statusEl.classList.add('ui-active');

      // 无按钮 + timer 自动关闭
      if (!withBtn && opts.timer) {
        autoTimer = setTimeout(function () {
          statusEl.classList.remove('ui-active');
          if (currentResolve) { currentResolve({ isConfirmed: true, isDismissed: false }); currentResolve = null; }
        }, opts.timer);
      }
    });
  }

  function hideStatus(reason) {
    clearTimer();
    statusEl.classList.remove('ui-active');
    if (currentResolve) { currentResolve({ isConfirmed: false, isDismissed: true, dismiss: reason }); currentResolve = null; }
  }

  /* ---------- 二次确认弹窗 ---------- */
  function showConfirm(opts) {
    return new Promise(function (resolve) {
      init();
      clearTimer();
      statusEl.classList.remove('ui-active');
      currentResolve = resolve;
      currentOpts = opts;

      confirmEl.classList.remove('ui-active');

      // emoji 图标
      document.getElementById('uiConfirmEmoji').textContent = CONFIRM_EMOJI[opts.icon] || '❓';

      // 标题
      document.getElementById('uiConfirmTitle').textContent = opts.title || '确认操作';

      // 正文
      var text = document.getElementById('uiConfirmText');
      if (opts.html) { text.innerHTML = opts.html; text.style.display = ''; }
      else { text.textContent = opts.text || ''; text.style.display = opts.text ? '' : 'none'; }

      // 按钮
      var actions = document.getElementById('uiConfirmActions');
      actions.innerHTML = '';

      if (opts.showCancelButton) {
        var cb = document.createElement('button');
        cb.className = 'ui-btn ui-btn-cancel';
        cb.textContent = opts.cancelButtonText || '取消';
        cb.addEventListener('click', function () { dismissConfirm('cancel'); });
        actions.appendChild(cb);
      }

      var kb = document.createElement('button');
      kb.className = 'ui-btn ui-btn-primary';
      if (opts.confirmButtonColor === '#ef4444') kb.classList.add('danger');
      else if (opts.confirmButtonColor === '#10b981') kb.classList.add('success');
      kb.textContent = opts.confirmButtonText || '确认';
      kb.addEventListener('click', function () {
        confirmEl.classList.remove('ui-active');
        clearTimer();
        resolve({ isConfirmed: true, isDismissed: false });
        currentResolve = null;
      });
      actions.appendChild(kb);

      void confirmEl.offsetWidth;
      confirmEl.classList.add('ui-active');
    });
  }

  function dismissConfirm(reason) {
    clearTimer();
    confirmEl.classList.remove('ui-active');
    if (currentResolve) { currentResolve({ isConfirmed: false, isDismissed: true, dismiss: reason }); currentResolve = null; }
  }

  /* ---------- 拦截 Swal.fire ---------- */
  if (typeof Swal !== 'undefined' && Swal.fire) {
    var _origSwal = Swal.fire.bind(Swal);
    Swal.fire = function () {
      var args = Array.prototype.slice.call(arguments);
      var o = {};

      if (args.length === 1 && typeof args[0] === 'object' && args[0] !== null) {
        o = args[0];
      } else if (args.length >= 2 && typeof args[0] === 'string') {
        o.title = args[0];
        if (typeof args[1] === 'string') { o.text = args[1]; o.icon = args[2]; }
        else { o.icon = args[1]; }
      }

      // 二次确认（有取消按钮）→ 确认弹窗；否则 → 状态卡片
      if (o.showCancelButton) return showConfirm(o);
      return showStatus(o);
    };
    Swal.mixin = function () { return Swal; };
  }
})();
