/* ============================================================
   EECMS Admin - UI3 Interaction Script
   视图切换 / 主题切换 / 导航搜索 / iframe 懒加载
   ============================================================ */
(function () {
  'use strict';

  /* ---------- View titles mapping ---------- */
  var VIEW_TITLES = {
    dashboard: '后台首页',
    setting: '网站设置',
    user: '修改信息',
    api: 'API接口设置',
    s3: 'S3存储设置',
    users: '用户管理',
    images: '图片管理',
    apikeys: 'API密钥管理',
    regconfig: '注册设置',
    packages: '套餐管理',
    apigroups: '接口分组',
    redeem: '兑换码管理',
    access: '访问控制',
    changelog: '更新日志'
  };

  var navItems = document.querySelectorAll('.nav-item[data-view]');
  var views = document.querySelectorAll('.view');
  var crumbCurrent = document.getElementById('crumbCurrent');

  /* ---------- View switching with iframe lazy loading ---------- */
  function switchView(name) {
    if (!VIEW_TITLES[name]) return;

    // 移动端点击导航后自动收起侧边栏抽屉
    closeSidebar();

    // Update nav active state
    navItems.forEach(function (item) {
      item.classList.toggle('active', item.dataset.view === name);
    });

    // Update view visibility
    views.forEach(function (view) {
      var isActive = view.id === 'view-' + name;
      view.classList.toggle('active', isActive);
      
      // Lazy load iframe: set src from data-src when view becomes active
      if (isActive) {
        var iframe = view.querySelector('iframe');
        if (iframe && iframe.dataset.src && !iframe.src) {
          iframe.src = iframe.dataset.src;
        }
      }
    });

    // Update breadcrumb
    if (crumbCurrent) crumbCurrent.textContent = VIEW_TITLES[name];
    // 浏览器标题使用网站名称（与 $conf['name'] 一致，由 index.php 注入）
    var siteName = window.ADMIN_SITE_NAME || 'EECMS 图床管理台';
    document.title = VIEW_TITLES[name] + ' - ' + siteName;
  }

  // Make switchView available globally for dropdown menu links
  window.switchView = switchView;

  // Bind nav item clicks
  navItems.forEach(function (item) {
    item.addEventListener('click', function (e) {
      e.preventDefault();
      switchView(item.dataset.view);
    });
  });

  // Handle dropdown menu links with data-url (e.g., "修改信息")
  document.querySelectorAll('.nav-item-link[data-url]').forEach(function (link) {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      var url = link.dataset.url;
      // Find the nav item that matches this URL
      navItems.forEach(function (navItem) {
        if (navItem.dataset.url === url) {
          switchView(navItem.dataset.view);
        }
      });
    });
  });

  /* ---------- Mobile sidebar drawer ---------- */
  var sidebarEl = document.querySelector('.sidebar');
  var sidebarOverlay = document.getElementById('sidebarOverlay');
  var sidebarToggle = document.getElementById('sidebarToggle');
  var mobileQuery = window.matchMedia('(max-width: 768px)');

  function openSidebar() {
    if (sidebarEl) sidebarEl.classList.add('open');
    if (sidebarOverlay) sidebarOverlay.classList.add('show');
    document.body.style.overflow = 'hidden';
  }
  function closeSidebar() {
    if (sidebarEl) sidebarEl.classList.remove('open');
    if (sidebarOverlay) sidebarOverlay.classList.remove('show');
    document.body.style.overflow = '';
  }
  function toggleSidebar() {
    if (sidebarEl && sidebarEl.classList.contains('open')) {
      closeSidebar();
    } else {
      openSidebar();
    }
  }

  if (sidebarToggle) {
    sidebarToggle.addEventListener('click', toggleSidebar);
  }
  if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', closeSidebar);
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeSidebar();
  });
  // 从移动端切回桌面端时自动复位抽屉状态
  if (mobileQuery.addEventListener) {
    mobileQuery.addEventListener('change', function (e) {
      if (!e.matches) closeSidebar();
    });
  } else if (mobileQuery.addListener) {
    mobileQuery.addListener(function (e) {
      if (!e.matches) closeSidebar();
    });
  }

  /* ---------- Theme switching ---------- */
  var themeBtn = document.getElementById('themeToggle');

  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    try { localStorage.setItem('admin_theme', theme); } catch (e) {}
    // Sync theme to all iframes
    syncThemeToIframes(theme);
  }

  function syncThemeToIframes(theme) {
    views.forEach(function (view) {
      var iframe = view.querySelector('iframe');
      if (iframe && iframe.contentWindow && iframe.contentDocument) {
        try {
          iframe.contentDocument.documentElement.setAttribute('data-theme', theme);
        } catch (e) {
          // Cross-origin or not loaded yet, will be handled on iframe load
        }
      }
    });
  }

  if (themeBtn) {
    // Apply saved theme (already done in head inline script, but ensure it's applied)
    var saved = null;
    try { saved = localStorage.getItem('admin_theme') || 'light'; } catch (e) {}
    if (saved) {
      document.documentElement.setAttribute('data-theme', saved);
    }

    themeBtn.addEventListener('click', function () {
      var current = document.documentElement.getAttribute('data-theme');
      var next = current === 'dark' ? 'light' : 'dark';
      applyTheme(next);
    });
  }

  // Sync theme to iframe when it loads
  views.forEach(function (view) {
    var iframe = view.querySelector('iframe');
    if (iframe) {
      iframe.addEventListener('load', function () {
        var currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
        try {
          if (iframe.contentDocument) {
            iframe.contentDocument.documentElement.setAttribute('data-theme', currentTheme);
          }
        } catch (e) {}
      });
    }
  });

  /* ---------- Navigation search ---------- */
  var navSearch = document.getElementById('navSearch') || document.querySelector('.nav-search input');
  if (navSearch) {
    navSearch.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        var q = navSearch.value.trim().toLowerCase();
        if (!q) return;
        var matched = null;
        Object.keys(VIEW_TITLES).forEach(function (key) {
          if (VIEW_TITLES[key].toLowerCase().indexOf(q) !== -1 || key.toLowerCase().indexOf(q) !== -1) {
            matched = matched || key;
          }
        });
        if (matched) {
          switchView(matched);
          navSearch.value = '';
          navSearch.blur();
        }
      }
    });
  }

  /* ---------- Keyboard shortcut ⌘K / Ctrl+K ---------- */
  document.addEventListener('keydown', function (e) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      if (navSearch) navSearch.focus();
    }
  });

  /* ---------- Segment control switching ---------- */
  document.querySelectorAll('.segment').forEach(function (seg) {
    seg.querySelectorAll('button').forEach(function (btn) {
      btn.addEventListener('click', function () {
        seg.querySelectorAll('button').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
      });
    });
  });

  /* ---------- Filter chip switching ---------- */
  document.querySelectorAll('.chip-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var parent = btn.closest('.filters');
      if (!parent) return;
      parent.querySelectorAll('.chip-btn').forEach(function (b) { b.classList.remove('chip-active'); });
      btn.classList.add('chip-active');
    });
  });

  /* ---------- Initialize: load default view iframe ---------- */
  // The dashboard view's iframe already has src set in PHP, but ensure it's loaded
  var defaultView = document.querySelector('.view.active iframe');
  if (defaultView && defaultView.dataset.src && !defaultView.src) {
    defaultView.src = defaultView.dataset.src;
  }

})();
