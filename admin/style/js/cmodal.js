/**
 * @file cmodal.js
 * @description CModal - 轻量级 Modal 组件，替代 Bootstrap Modal JS
 *              提供 window.bootstrap.Modal 兼容垫片，使现有代码零修改即可工作
 * @author AI
 * @version 1.0.0
 * @date 2026-08-08
 */
(function(window, document) {
    'use strict';

    // 实例缓存
    var instanceMap = new WeakMap();

    /**
     * CModal 类
     * @param {HTMLElement} element - .modal 元素
     */
    function CModal(element) {
        if (!element || !(element instanceof HTMLElement)) {
            throw new Error('CModal: element 必须是 HTMLElement');
        }

        // 如果已有实例，返回已有实例
        var existing = instanceMap.get(element);
        if (existing) return existing;

        this.element = element;
        this.isShown = false;
        this.isAnimating = false;
        this.backdrop = null;
        this.focusedBefore = null;

        // 读取配置属性
        this.config = {
            backdrop: element.getAttribute('data-bs-backdrop') !== 'static',
            keyboard: element.getAttribute('data-bs-keyboard') !== 'false'
        };

        // 绑定事件
        this._bindEvents();

        // 缓存实例
        instanceMap.set(element, this);
    }

    CModal.prototype = {
        /**
         * 显示 Modal
         */
        show: function() {
            if (this.isShown || this.isAnimating) return;
            this.isShown = true;
            this.isAnimating = true;

            // 记录当前焦点
            this.focusedBefore = document.activeElement;

            // 创建遮罩
            this.backdrop = document.createElement('div');
            this.backdrop.className = 'cmodal-backdrop';

            // 添加到 DOM
            document.body.appendChild(this.backdrop);
            document.body.classList.add('modal-open');
            this.element.style.display = '';
            this.element.classList.add('cmodal-open');

            var self = this;
            // 触发遮罩淡入
            requestAnimationFrame(function() {
                self.backdrop.classList.add('show');
            });

            // 等待动画完成
            setTimeout(function() {
                self.isAnimating = false;
                // 焦点陷阱
                self._trapFocus();
                // 尝试聚焦 Modal 内第一个可聚焦元素
                var focusable = self.element.querySelector('input, select, textarea, button, [tabindex]');
                if (focusable) focusable.focus();
            }, 300);
        },

        /**
         * 隐藏 Modal
         */
        hide: function() {
            if (!this.isShown || this.isAnimating) return;
            this.isShown = false;
            this.isAnimating = true;

            var self = this;
            // 移除遮罩淡入
            if (self.backdrop) {
                self.backdrop.classList.remove('show');
            }
            self.element.classList.remove('cmodal-open');

            setTimeout(function() {
                self.element.style.display = 'none';
                if (self.backdrop) {
                    self.backdrop.remove();
                    self.backdrop = null;
                }
                document.body.classList.remove('modal-open');
                self.isAnimating = false;

                // 恢复焦点
                if (self.focusedBefore && self.focusedBefore.focus) {
                    self.focusedBefore.focus();
                }
            }, 300);
        },

        /**
         * 切换显示/隐藏
         */
        toggle: function() {
            if (this.isShown) this.hide();
            else this.show();
        },

        /**
         * 绑定事件
         */
        _bindEvents: function() {
            var self = this;

            // data-bs-dismiss="modal" 点击关闭（事件委托，只绑定一次）
            this.element.addEventListener('click', function(e) {
                var dismissBtn = e.target.closest('[data-bs-dismiss="modal"]');
                if (dismissBtn) {
                    e.preventDefault();
                    self.hide();
                }
            });

            // 遮罩点击关闭（如果 backdrop 不是 static）
            this.element.addEventListener('click', function(e) {
                if (e.target === self.element && self.config.backdrop) {
                    self.hide();
                }
            });

            // 注意：Esc 键在全局监听器中处理（_bindGlobalEvents）
        },

        /**
         * 焦点陷阱
         */
        _trapFocus: function() {
            var self = this;
            this.element.addEventListener('keydown', function(e) {
                if (e.key !== 'Tab') return;

                var focusable = self.element.querySelectorAll(
                    'input:not([disabled]), select:not([disabled]), textarea:not([disabled]), ' +
                    'button:not([disabled]), [tabindex]:not([tabindex="-1"])'
                );
                if (focusable.length === 0) return;

                var first = focusable[0];
                var last = focusable[focusable.length - 1];

                if (e.shiftKey) {
                    if (document.activeElement === first) {
                        e.preventDefault();
                        last.focus();
                    }
                } else {
                    if (document.activeElement === last) {
                        e.preventDefault();
                        first.focus();
                    }
                }
            });
        }
    };

    /**
     * 静态方法：获取或创建实例
     */
    CModal.getInstance = function(element) {
        if (!element) return null;
        var instance = instanceMap.get(element);
        if (!instance) {
            instance = new CModal(element);
        }
        return instance;
    };

    /**
     * 全局事件绑定（Esc 键关闭）
     */
    function _bindGlobalEvents() {
        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') return;

            // 找到所有打开的 Modal
            var openModals = document.querySelectorAll('.modal.cmodal-open');
            for (var i = 0; i < openModals.length; i++) {
                var instance = instanceMap.get(openModals[i]);
                if (instance && instance.config.keyboard && instance.isShown) {
                    instance.hide();
                    break; // 只关闭最上层的
                }
            }
        });
    }

    // DOM 就绪后绑定全局事件
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', _bindGlobalEvents);
    } else {
        _bindGlobalEvents();
    }

    // ========== Bootstrap 兼容垫片 ==========
    // 挂载 window.bootstrap.Modal = CModal，使现有代码零修改即可工作
    if (!window.bootstrap) {
        window.bootstrap = {};
    }
    window.bootstrap.Modal = CModal;

    // 全局暴露
    window.CModal = CModal;

})(typeof window !== 'undefined' ? window : this, typeof document !== 'undefined' ? document : null);
