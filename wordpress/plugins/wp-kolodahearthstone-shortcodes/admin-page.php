<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('KHSH_Admin_Page')) {

class KHSH_Admin_Page {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_styles']);
    }

    public function add_admin_menu() {
        add_menu_page(
            'Шорткод',
            'Шорткод',
            'edit_posts',
            'khsh-shortcodes',
            [$this, 'render_about_page'],
            'dashicons-shortcode',
            30
        );
        
        add_submenu_page(
            'khsh-shortcodes',
            'О плагине',
            'О плагине',
            'edit_posts',
            'khsh-shortcodes',
            [$this, 'render_about_page']
        );
    }

    public function admin_styles($hook) {
        if (strpos($hook, 'khsh-shortcodes') === false) {
            return;
        }
        
        wp_add_inline_style('wp-admin', $this->get_admin_css());
    }

    private function get_admin_css() {
        return '
        .khsh-admin-page {
            max-width: 1200px;
            margin: 20px 20px 0 0;
        }
        .khsh-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .khsh-header h1 {
            color: #fff;
            font-size: 32px;
            margin: 0 0 10px 0;
            font-weight: 700;
        }
        .khsh-header p {
            font-size: 16px;
            margin: 0;
            opacity: 0.95;
        }
        .khsh-shortcodes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        .khsh-shortcode-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        .khsh-shortcode-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .khsh-shortcode-icon {
            font-size: 48px;
            margin-bottom: 15px;
            display: block;
        }
        .khsh-shortcode-card h3 {
            margin: 0 0 12px 0;
            color: #23282d;
            font-size: 20px;
            font-weight: 700;
        }
        .khsh-shortcode-card p {
            color: #666;
            line-height: 1.6;
            margin: 0 0 15px 0;
        }
        .khsh-shortcode-example {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 12px 15px;
            margin: 15px 0;
            border-radius: 4px;
            font-family: "Courier New", monospace;
            font-size: 13px;
            color: #333;
            word-break: break-all;
        }
        .khsh-shortcode-params {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        .khsh-shortcode-params h4 {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #23282d;
            font-weight: 600;
        }
        .khsh-param-item {
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .khsh-param-item:last-child {
            border-bottom: none;
        }
        .khsh-param-name {
            font-weight: 600;
            color: #23282d;
            font-family: "Courier New", monospace;
            font-size: 13px;
        }
        .khsh-param-desc {
            color: #666;
            font-size: 12px;
            margin-top: 4px;
        }
        .khsh-features {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .khsh-features h2 {
            margin: 0 0 20px 0;
            color: #23282d;
            font-size: 24px;
        }
        .khsh-features-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .khsh-features-list li {
            padding: 12px 15px;
            background: #f8f9fa;
            border-radius: 8px;
            color: #333;
        }
        .khsh-features-list li:before {
            content: "✓ ";
            color: #46b450;
            font-weight: bold;
            margin-right: 8px;
        }
        @media (max-width: 782px) {
            .khsh-shortcodes-grid {
                grid-template-columns: 1fr;
            }
        }
        ';
    }

    public function render_about_page() {
        ?>
        <div class="wrap khsh-admin-page">
            <div class="khsh-header">
                <h1>📝 KolodaHearthstone: Shortcodes</h1>
                <p>Объединенный плагин для работы с кастомными шорткодами в WordPress</p>
            </div>

            <div class="khsh-features">
                <h2>🎯 Возможности плагина</h2>
                <ul class="khsh-features-list">
                    <li>Создание красивых баннеров с кастомными стилями</li>
                    <li>Интерактивные спойлеры с поддержкой цветовых схем</li>
                    <li>Стилизованные таблицы на два столбца</li>
                    <li>Голосования и опросы с AJAX</li>
                    <li>Интеграция с редактором TinyMCE</li>
                    <li>Шаблоны для быстрой вставки</li>
                    <li>Адаптивный дизайн</li>
                    <li>Поддержка всех популярных цветов</li>
                </ul>
            </div>

            <h2 style="margin: 30px 0 20px 0; font-size: 24px; color: #23282d;">📦 Доступные шорткоды</h2>
            
            <div class="khsh-shortcodes-grid">
                <!-- Баннер -->
                <div class="khsh-shortcode-card">
                    <span class="khsh-shortcode-icon">🎯</span>
                    <h3>Баннер</h3>
                    <p>Создавайте красивые баннеры с заголовком, цветом и ссылкой. Идеально подходит для призывов к действию.</p>
                    <div class="khsh-shortcode-example">
                        [banner title="Подпишитесь на Telegram" color="blue" url="https://t.me/channel"]<br>
                        Получайте самые свежие новости!<br>
                        [/banner]
                    </div>
                    <div class="khsh-shortcode-params">
                        <h4>Параметры:</h4>
                        <div class="khsh-param-item">
                            <div class="khsh-param-name">title</div>
                            <div class="khsh-param-desc">Заголовок баннера (обязательно)</div>
                        </div>
                        <div class="khsh-param-item">
                            <div class="khsh-param-name">color</div>
                            <div class="khsh-param-desc">Цвет: default, blue, green, red, purple, orange, yellow, brown</div>
                        </div>
                        <div class="khsh-param-item">
                            <div class="khsh-param-name">url</div>
                            <div class="khsh-param-desc">Ссылка (необязательно, делает баннер кликабельным)</div>
                        </div>
                    </div>
                </div>

                <!-- Спойлер -->
                <div class="khsh-shortcode-card">
                    <span class="khsh-shortcode-icon">📦</span>
                    <h3>Спойлер</h3>
                    <p>Интерактивные спойлеры с возможностью скрывать/показывать контент. Поддерживает все виды контента.</p>
                    <div class="khsh-shortcode-example">
                        [spoiler title="Скрытый контент" color="green" open="0"]<br>
                        Здесь ваш скрытый текст<br>
                        [/spoiler]
                    </div>
                    <div class="khsh-shortcode-params">
                        <h4>Параметры:</h4>
                        <div class="khsh-param-item">
                            <div class="khsh-param-name">title</div>
                            <div class="khsh-param-desc">Заголовок спойлера (обязательно)</div>
                        </div>
                        <div class="khsh-param-item">
                            <div class="khsh-param-name">color</div>
                            <div class="khsh-param-desc">Цвет: default, blue, green, red, purple, orange, yellow, brown</div>
                        </div>
                        <div class="khsh-param-item">
                            <div class="khsh-param-name">open</div>
                            <div class="khsh-param-desc">0 или 1 - открыт ли спойлер по умолчанию</div>
                        </div>
                        <div class="khsh-param-item">
                            <div class="khsh-param-name">icon</div>
                            <div class="khsh-param-desc">Иконка (по умолчанию ▼)</div>
                        </div>
                    </div>
                </div>

                <!-- Таблица -->
                <div class="khsh-shortcode-card">
                    <span class="khsh-shortcode-icon">📊</span>
                    <h3>Таблица</h3>
                    <p>Создавайте стилизованные таблицы на два столбца с поддержкой цветовых схем.</p>
                    <div class="khsh-shortcode-example">
                        [table color="purple"]<br>
                        Категория|Значение<br>
                        Статистика|100%<br>
                        [/table]
                    </div>
                    <div class="khsh-shortcode-params">
                        <h4>Параметры:</h4>
                        <div class="khsh-param-item">
                            <div class="khsh-param-name">color</div>
                            <div class="khsh-param-desc">Цвет: default, blue, green, red, purple, orange, yellow, brown</div>
                        </div>
                    </div>
                    <p style="font-size: 12px; color: #999; margin-top: 15px;">
                        <strong>Формат данных:</strong> Каждая строка в формате "Категория|Значение". Для разделения строк используйте [row] или новую строку.
                    </p>
                </div>

                <!-- Опрос -->
                <div class="khsh-shortcode-card">
                    <span class="khsh-shortcode-icon">📋</span>
                    <h3>Опрос</h3>
                    <p>Создавайте интерактивные опросы с двумя вариантами ответа. Результаты сохраняются и отображаются в реальном времени.</p>
                    <div class="khsh-shortcode-example">
                        [khs_poll question="Какой ваш любимый класс?" option1="Маг" option2="Воин"]<br>
                        Выберите ваш любимый класс в Hearthstone<br>
                        [/khs_poll]
                    </div>
                    <div class="khsh-shortcode-params">
                        <h4>Параметры:</h4>
                        <div class="khsh-param-item">
                            <div class="khsh-param-name">question</div>
                            <div class="khsh-param-desc">Текст вопроса (обязательно)</div>
                        </div>
                        <div class="khsh-param-item">
                            <div class="khsh-param-name">option1</div>
                            <div class="khsh-param-desc">Первый вариант ответа (обязательно)</div>
                        </div>
                        <div class="khsh-param-item">
                            <div class="khsh-param-name">option2</div>
                            <div class="khsh-param-desc">Второй вариант ответа (обязательно)</div>
                        </div>
                    </div>
                    <p style="font-size: 12px; color: #999; margin-top: 15px;">
                        <strong>Особенности:</strong> Каждый пользователь может проголосовать только один раз. Результаты сохраняются и отображаются после голосования.
                    </p>
                </div>
            </div>

            <div class="khsh-features">
                <h2>💡 Как использовать</h2>
                <ol style="line-height: 2; color: #333;">
                    <li><strong>Через редактор:</strong> В редакторе постов нажмите кнопку "Кастомные шорткоды" над редактором для быстрого доступа ко всем шорткодам.</li>
                    <li><strong>Через TinyMCE:</strong> В визуальном редакторе используйте кнопки отдельных модулей для вставки шорткодов.</li>
                    <li><strong>Вручную:</strong> Вставьте шорткод напрямую в текстовый редактор, используя примеры выше.</li>
                    <li><strong>Шаблоны:</strong> Используйте функцию "Шаблоны" для сохранения часто используемых шорткодов и быстрой вставки.</li>
                </ol>
            </div>

            <div style="background: #fff; border: 1px solid #ddd; border-radius: 12px; padding: 25px; margin-top: 30px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                <h3 style="margin: 0 0 15px 0; color: #23282d;">ℹ️ Информация</h3>
                <p style="margin: 0; color: #666; line-height: 1.8;">
                    <strong>Версия:</strong> 2.0<br>
                    <strong>Автор:</strong> Manacost<br>
                    <strong>Описание:</strong> Объединенный плагин для работы с кастомными шорткодами (баннеры, спойлеры, таблицы, опросы)
                </p>
            </div>
        </div>
        <?php
    }
}

new KHSH_Admin_Page();
}




