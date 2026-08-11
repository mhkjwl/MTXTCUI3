/**
 * @file cdropdown.js
 * @description CDropdown - 轻量级下拉菜单组件，替代 Bootstrap Dropdown JS
 *              监听 data-bs-toggle="dropdown" 触发，切换 .dropdown-menu 的 .show 类
 *              支持点击外部关闭、Esc 关闭、菜单项点击后关闭
 * @author AI
 * @version 1.0.0
 * @date 2026-08-08
 */
(function(window, document) {
    'use strict';

    var CDropdown = {
        /**
         * 当前打开的菜单
         */
        _openMenu: null,

        /**
         * 初始化：绑定全局事件
         */
        init: function() {
            var self = this;

            // 点击触发器
            document.addEventListener('click', function(e) {
                var trigger = e.target.closest('[data-bs-toggle="dropdown"]');
                if (trigger) {
                    e.preventDefault();
                    var menu = trigger.parentElement.querySelector('.dropdown-menu');
                    if (menu) {
                        self.toggle(menu, trigger);
                    }
                    return;
                }

                // 点击菜单项后关闭（除非标记 data-bs-stop-close）
                if (self._openMenu && self._openMenu.contains(e.target)) {
                    var item = e.target.closest('.dropdown-item');
                    if (item && !item.hasAttribute('data-bs-stop-close')) {
                        self.close();
                    }
                    return;
                }

                // 点击外部关闭
                if (self._openMenu) {
                    self.close();
                }
            });

            // Esc 关闭
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && self._openMenu) {
                    self.close();
                }
            });
        },

        /**
         * 切换菜单显示
         */
        toggle: function(menu, trigger) {
            if (this._openMenu === menu) {
                this.close();
            } else {
                this.close();
                this._show(menu, trigger);
            }
        },

        /**
         * 显示菜单
         */
        _show: function(menu, trigger) {
            var dropdownParent = menu.closest('.dropdown') || menu.parentElement;
            menu.classList.add('show');
            dropdownParent.classList.add('show');
            if (trigger) {
                trigger.setAttribute('aria-expanded', 'true');
            }
            this._openMenu = menu;
        },

        /**
         * 关闭当前菜单
         */
        close: function() {
            if (this._openMenu) {
                var dropdownParent = this._openMenu.closest('.dropdown') || this._openMenu.parentElement;
                this._openMenu.classList.remove('show');
                dropdownParent.classList.remove('show');
                // 清除触发器的 aria-expanded
                var triggers = dropdownParent.querySelectorAll('[data-bs-toggle="dropdown"]');
                for (var i = 0; i < triggers.length; i++) {
                    triggers[i].setAttribute('aria-expanded', 'false');
                }
                this._openMenu = null;
            }
        }
    };

    // 暴露到全局
    window.CDropdown = CDropdown;

    // Bootstrap 兼容垫片
    if (!window.bootstrap) {
        window.bootstrap = {};
    }
    window.bootstrap.Dropdown = {
        toggle: function(el) {
            if (el && el.parentElement) {
                var menu = el.parentElement.querySelector('.dropdown-menu');
                if (menu) CDropdown.toggle(menu);
            }
        },
        hide: function() { CDropdown.close(); }
    };

    // DOM 就绪后初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { CDropdown.init(); });
    } else {
        CDropdown.init();
    }

})(typeof window !== 'undefined' ? window : this, typeof document !== 'undefined' ? document : null);
