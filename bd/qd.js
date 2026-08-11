/**
 * qd.js — qd 设计系统通用交互
 * @file       qd.js
 * @description 提供 qd 设计系统的通用 UI 交互：侧边栏导航联动、
 *              个人中心标签页切换、通知开关切换、图库筛选、进度条动画。
 *              页面已有的业务 JS（上传、API 密钥管理等）保持内联不变。
 * @author     EECMS Team
 * @version    1.0.0-dev
 * @date       2026-08-04
 */
(function () {
  'use strict';

  // ===== 侧边栏导航 active 联动 =====
  var sideNavLinks = document.querySelectorAll('.side-nav a');
  sideNavLinks.forEach(function (link) {
    link.addEventListener('click', function () {
      sideNavLinks.forEach(function (item) { item.classList.remove('active'); });
      link.classList.add('active');
    });
  });

  // ===== 个人中心：标签页切换（data-tab / data-panel） =====
  var profileTabButtons = document.querySelectorAll('.profile-tab-row .filter-pill');
  var profilePanels = document.querySelectorAll('.profile-tabs .tab-panel');
  profileTabButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      profileTabButtons.forEach(function (item) { item.classList.remove('active'); });
      button.classList.add('active');
      var target = button.dataset.tab;
      profilePanels.forEach(function (panel) {
        panel.classList.toggle('active', panel.dataset.panel === target);
      });
    });
  });

  // ===== 通知开关切换（toggle-switch） =====
  var toggleSwitches = document.querySelectorAll('.toggle-switch');
  toggleSwitches.forEach(function (toggle) {
    toggle.addEventListener('click', function () {
      var isOn = toggle.classList.toggle('is-on');
      toggle.setAttribute('aria-checked', String(isOn));
    });
  });

  // ===== 图库筛选（filter-pill / gallery-card） =====
  var filterButtons = document.querySelectorAll('.filter-pill[data-filter]');
  var galleryCards = document.querySelectorAll('.gallery-card');
  filterButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      filterButtons.forEach(function (item) { item.classList.remove('active'); });
      button.classList.add('active');
      var currentFilter = button.dataset.filter;
      galleryCards.forEach(function (card) {
        var matches = currentFilter === '全部' || card.dataset.category === currentFilter;
        card.classList.toggle('is-hidden', !matches);
      });
    });
  });

  // ===== 进度条动画（queue-progress span） =====
  var progressBars = document.querySelectorAll('.queue-progress span');
  progressBars.forEach(function (bar) {
    var target = bar.dataset.progress || '0';
    bar.style.width = '0';
    window.requestAnimationFrame(function () {
      setTimeout(function () { bar.style.width = target + '%'; }, 180);
    });
  });

  // ===== 移动端侧边栏开合 =====
  var menuToggle = document.getElementById('menuToggle');
  var sidebar = document.getElementById('sidebar');
  var sidebarOverlay = document.getElementById('sidebarOverlay');
  if (menuToggle && sidebar && sidebarOverlay) {
    function openSidebar() {
      sidebar.classList.add('open');
      sidebarOverlay.classList.add('show');
    }
    function closeSidebar() {
      sidebar.classList.remove('open');
      sidebarOverlay.classList.remove('show');
    }
    menuToggle.addEventListener('click', function () {
      if (sidebar.classList.contains('open')) closeSidebar();
      else openSidebar();
    });
    sidebarOverlay.addEventListener('click', closeSidebar);
    // 窗口放大时自动收起移动端侧边栏
    window.addEventListener('resize', function () {
      if (window.innerWidth > 1280) closeSidebar();
    });
  }
})();
