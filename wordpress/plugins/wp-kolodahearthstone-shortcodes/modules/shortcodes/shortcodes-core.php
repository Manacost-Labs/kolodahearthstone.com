<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('WP_Custom_Shortcodes')) {

class WP_Custom_Shortcodes {

    private $plugin_dir;
    private $plugin_url;

    public function __construct() {
        $this->plugin_dir = KHSH_PLUGIN_DIR . 'modules/shortcodes/';
        $this->plugin_url = KHSH_PLUGIN_URL . '/modules/shortcodes/';
        
        add_action('media_buttons', [$this, 'add_custom_shortcodes_button'], 11);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('admin_head', [$this, 'fix_tinymce_fullscreen_in_modals']);
    }

    public function add_custom_shortcodes_button($editor_id = 'content') {
        if ($editor_id !== 'content') {
            return;
        }
        
        echo '<button type="button" id="mtp-custom-shortcodes-btn" class="button" style="margin-left: 5px;">
            <span class="dashicons dashicons-shortcode" style="margin-top: 3px;"></span> Кастомные шорткоды
        </button>';
    }

    public function enqueue_admin_scripts($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'])) {
            return;
        }
        
        wp_enqueue_script(
            'mtp-custom-shortcodes',
            $this->plugin_url . 'custom-shortcodes.js',
            ['jquery'],
            filemtime($this->plugin_dir . 'custom-shortcodes.js'),
            true
        );
        
        wp_enqueue_style(
            'mtp-custom-shortcodes',
            $this->plugin_url . 'custom-shortcodes.css',
            [],
            filemtime($this->plugin_dir . 'custom-shortcodes.css')
        );
        
        wp_enqueue_style(
            'mtp-tinymce-modals',
            $this->plugin_url . 'tinymce-modals.css',
            [],
            filemtime($this->plugin_dir . 'tinymce-modals.css')
        );
    }

    /**
     * Исправляет z-index и отключает CSS transform у родительских контейнеров
     * модального окна при активации FullscreenStateChanged в TinyMCE
     */
    public function fix_tinymce_fullscreen_in_modals() {
        // Проверяем, что мы на странице редактирования поста
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->base, ['post', 'post-new'])) {
            return;
        }
        ?>
        <script type="text/javascript">
        (function($) {
            'use strict';
            
            // Функция для поиска модального окна, содержащего редактор
            function findModalWindow(editorElement) {
                if (!editorElement) return null;
                
                var current = editorElement;
                var doc = editorElement.ownerDocument || document;
                
                // Сначала ищем в текущем документе (может быть iframe)
                while (current && current !== doc.body && current !== doc.documentElement) {
                    if (current.classList && current.classList.contains('mce-window')) {
                        return current;
                    }
                    current = current.parentElement;
                }
                
                // Если не нашли в текущем документе и это iframe, ищем в родительском документе
                if (doc !== document && window.parent && window.parent.document) {
                    var parentDoc = window.parent.document;
                    var parentWindows = parentDoc.querySelectorAll('.mce-window');
                    // Ищем модальное окно, которое содержит этот iframe
                    for (var i = 0; i < parentWindows.length; i++) {
                        var modalWindow = parentWindows[i];
                        var iframes = modalWindow.querySelectorAll('iframe');
                        for (var j = 0; j < iframes.length; j++) {
                            try {
                                if (iframes[j].contentWindow === window || 
                                    iframes[j].contentDocument === doc) {
                                    return modalWindow;
                                }
                            } catch(e) {
                                // Игнорируем ошибки CORS
                            }
                        }
                    }
                }
                
                return null;
            }
            
            // Функция для исправления стилей родительских контейнеров
            function fixParentContainers(modalWindow, isFullscreen) {
                if (!modalWindow) return;
                
                // Находим все родительские элементы до body
                var parents = [];
                var current = modalWindow.parentElement;
                while (current && current !== document.body && current !== document.documentElement) {
                    parents.push(current);
                    current = current.parentElement;
                }
                
                if (isFullscreen) {
                    // При активации полного экрана
                    // Сохраняем оригинальный z-index модального окна
                    var modalZIndex = window.getComputedStyle(modalWindow).zIndex;
                    if (!modalWindow.getAttribute('data-original-z-index')) {
                        modalWindow.setAttribute('data-original-z-index', modalZIndex || 'auto');
                    }
                    
                    // Устанавливаем высокий z-index для модального окна
                    modalWindow.style.zIndex = '999999';
                    
                    // Отключаем transform у всех родительских контейнеров
                    parents.forEach(function(parent) {
                        var currentTransform = window.getComputedStyle(parent).transform;
                        if (currentTransform && currentTransform !== 'none') {
                            // Сохраняем оригинальный transform в data-атрибут
                            if (!parent.getAttribute('data-original-transform')) {
                                parent.setAttribute('data-original-transform', currentTransform);
                            }
                            parent.style.transform = 'none';
                        }
                    });
                } else {
                    // При выходе из полного экрана
                    // Восстанавливаем z-index модального окна
                    var originalZIndex = modalWindow.getAttribute('data-original-z-index');
                    if (originalZIndex) {
                        if (originalZIndex === 'auto') {
                            modalWindow.style.zIndex = '';
                        } else {
                            modalWindow.style.zIndex = originalZIndex;
                        }
                        modalWindow.removeAttribute('data-original-z-index');
                    } else {
                        modalWindow.style.zIndex = '';
                    }
                    
                    // Восстанавливаем transform у родительских контейнеров
                    parents.forEach(function(parent) {
                        var originalTransform = parent.getAttribute('data-original-transform');
                        if (originalTransform) {
                            parent.style.transform = originalTransform;
                            parent.removeAttribute('data-original-transform');
                        } else {
                            parent.style.transform = '';
                        }
                    });
                }
            }
            
            // Ждем загрузки TinyMCE
            $(document).ready(function() {
                if (typeof tinymce === 'undefined') {
                    return;
                }
                
                // Обрабатываем уже загруженные редакторы
                tinymce.on('AddEditor', function(e) {
                    var editor = e.editor;
                    
                    // Подписываемся на событие изменения состояния полного экрана
                    editor.on('FullscreenStateChanged', function(e) {
                        var isFullscreen = e.state;
                        var container = editor.getContainer();
                        var editorElement = container;
                        
                        // Пытаемся найти iframe внутри контейнера
                        var iframe = container.querySelector('iframe');
                        if (iframe && iframe.contentWindow) {
                            try {
                                var iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                                editorElement = iframeDoc.body || iframeDoc.documentElement;
                            } catch(ex) {
                                // Если нет доступа к iframe, используем контейнер
                                editorElement = container;
                            }
                        }
                        
                        if (editorElement) {
                            var modalWindow = findModalWindow(editorElement);
                            if (modalWindow) {
                                // Исправляем стили с небольшой задержкой, чтобы TinyMCE успел применить свои стили
                                setTimeout(function() {
                                    fixParentContainers(modalWindow, isFullscreen);
                                }, 50);
                            }
                        }
                    });
                });
                
                // Обрабатываем редакторы, которые уже были загружены
                if (tinymce.editors && tinymce.editors.length > 0) {
                    tinymce.editors.forEach(function(editor) {
                        // Проверяем, не подписаны ли уже на это событие
                        if (editor.hasEventListeners && editor.hasEventListeners('FullscreenStateChanged')) {
                            return;
                        }
                        
                        editor.on('FullscreenStateChanged', function(e) {
                            var isFullscreen = e.state;
                            var container = editor.getContainer();
                            var editorElement = container;
                            
                            // Пытаемся найти iframe внутри контейнера
                            var iframe = container.querySelector('iframe');
                            if (iframe && iframe.contentWindow) {
                                try {
                                    var iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                                    editorElement = iframeDoc.body || iframeDoc.documentElement;
                                } catch(ex) {
                                    // Если нет доступа к iframe, используем контейнер
                                    editorElement = container;
                                }
                            }
                            
                            if (editorElement) {
                                var modalWindow = findModalWindow(editorElement);
                                if (modalWindow) {
                                    setTimeout(function() {
                                        fixParentContainers(modalWindow, isFullscreen);
                                    }, 50);
                                }
                            }
                        });
                    });
                }
            });
        })(jQuery);
        </script>
        <?php
    }
}

new WP_Custom_Shortcodes();
}




