<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('KHSH_Tables')) {

class KHSH_Tables {

    private $plugin_dir;
    private $plugin_url;

    public function __construct() {
        $this->plugin_dir = KHSH_PLUGIN_DIR . 'modules/tables/';
        $this->plugin_url = KHSH_PLUGIN_URL . '/modules/tables/';
        
        add_shortcode('table', [$this, 'render_table']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
        add_action('admin_init', [$this, 'add_tinymce_button']);
    }

    public function enqueue_styles() {
        // Шрифт подключим через @font-face в шорткоде
    }

    public function add_tinymce_button() {
        // Кнопка убрана из панели - теперь доступна только через "Кастомные шорткоды"
        // if (current_user_can('edit_posts') || current_user_can('edit_pages')) {
        //     add_filter('mce_external_plugins', [$this, 'add_tinymce_plugin'], 10);
        //     add_filter('mce_buttons', [$this, 'register_tinymce_button'], 10);
        //     add_filter('mce_buttons_2', [$this, 'register_tinymce_button'], 10);
        //     add_filter('mce_css', [$this, 'add_tinymce_css'], 10);
        // }
    }

    public function add_tinymce_plugin($plugin_array) {
        $plugin_path = $this->plugin_url . 'tinymce-table.js';
        if (file_exists($this->plugin_dir . 'tinymce-table.js')) {
            $plugin_array['mtp_table'] = $plugin_path . '?v=' . filemtime($this->plugin_dir . 'tinymce-table.js');
        }
        return $plugin_array;
    }

    public function add_tinymce_css($mce_css) {
        if (!empty($mce_css)) $mce_css .= ',';
        $mce_css .= $this->plugin_url . 'tinymce-table.css';
        return $mce_css;
    }

    public function register_tinymce_button($buttons) {
        array_push($buttons, 'mtp_table');
        return $buttons;
    }

    public function render_table($atts, $content = null) {
        $atts = shortcode_atts([
            'color' => 'default' // default, blue, green, red, purple, orange, yellow, brown
        ], $atts);

        $color_map = [
            'default' => ['bg' => 'rgba(139,117,95,0.15)', 'border' => 'rgba(139,117,95,0.3)', 'text' => '#2d1b0e', 'header_bg' => 'rgba(139,117,95,0.2)'],
            'blue' => ['bg' => 'rgba(49,130,206,0.15)', 'border' => 'rgba(49,130,206,0.3)', 'text' => '#1e3a5f', 'header_bg' => 'rgba(49,130,206,0.2)'],
            'green' => ['bg' => 'rgba(56,161,105,0.15)', 'border' => 'rgba(56,161,105,0.3)', 'text' => '#22543d', 'header_bg' => 'rgba(56,161,105,0.2)'],
            'red' => ['bg' => 'rgba(229,62,62,0.15)', 'border' => 'rgba(229,62,62,0.3)', 'text' => '#742a2a', 'header_bg' => 'rgba(229,62,62,0.2)'],
            'purple' => ['bg' => 'rgba(128,90,213,0.15)', 'border' => 'rgba(128,90,213,0.3)', 'text' => '#553c9a', 'header_bg' => 'rgba(128,90,213,0.2)'],
            'orange' => ['bg' => 'rgba(237,137,54,0.15)', 'border' => 'rgba(237,137,54,0.3)', 'text' => '#7c2d12', 'header_bg' => 'rgba(237,137,54,0.2)'],
            'yellow' => ['bg' => 'rgba(255,193,7,0.15)', 'border' => 'rgba(255,193,7,0.3)', 'text' => '#78350f', 'header_bg' => 'rgba(255,193,7,0.2)'],
            'brown' => ['bg' => 'rgba(139,90,60,0.15)', 'border' => 'rgba(139,90,60,0.3)', 'text' => '#3d2817', 'header_bg' => 'rgba(139,90,60,0.2)']
        ];

        $colors = isset($color_map[$atts['color']]) ? $color_map[$atts['color']] : $color_map['default'];
        $unique_id = 'table-' . uniqid();
        
        // Парсим содержимое таблицы
        $rows = [];
        if ($content) {
            // Очищаем контент от тегов, но не трогаем текст
            $content = strip_tags($content);
            $content = trim($content);

            // 1. Разбиваем строку по нашему разделителю [row]
            $lines = explode('[row]', $content);
            
            // Если разделителя нет (старый формат или одна строка), пробуем разбить по \n на всякий случай
            if (count($lines) < 2 && strpos($content, '[row]') === false) {
                 $lines = explode("\n", $content);
            }

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // 2. Разделяем строку на колонки по символу |
                $parts = explode('|', $line, 2);
                
                if (count($parts) >= 2) {
                    $rows[] = [
                        'label' => trim($parts[0]),
                        'value' => trim($parts[1])
                    ];
                } elseif (count($parts) === 1 && !empty($parts[0])) {
                    $rows[] = [
                        'label' => trim($parts[0]),
                        'value' => ''
                    ];
                }
            }
        }
        
        if (empty($rows)) {
            return '<p style="color:#e74c3c;">Ошибка: таблица пуста.</p>';
        }
        
        // Путь к шрифту - используем путь из decks плагина
        $font_url = plugins_url('../../wp-kolodahearthstone-decks/font/2318-font.otf', __FILE__);
        
        $html = '<style>
        @font-face {
            font-family: "TableFont";
            src: url("' . esc_url($font_url) . '") format("opentype");
            font-weight: normal;
            font-style: normal;
        }
        #'.$unique_id.'.mtp-table-wrapper {
            margin: 25px 0;
            width: 100%;
            /* Убираем внешние тени контейнера, чтобы было похоже на спойлер */
        }
        #'.$unique_id.' .mtp-table {
            width: 100%;
            /* СТРУКТУРА СПОЙЛЕРА: Толстая рамка и скругление */
            border: 2px solid '.$colors['border'].'; 
            border-radius: 12px;
            background: '.$colors['bg'].'; /* Общий фон как у спойлера */
            border-collapse: separate; 
            border-spacing: 0;
            overflow: hidden; /* Обрезаем углы */
            box-sizing: border-box;
        }
        /* Внутренние линии строк */
        #'.$unique_id.' .mtp-table td {
            padding: 15px 20px;
            font-size: 1rem;
            line-height: 1.5;
            color: #3d2f1f;
            vertical-align: middle;
            border-bottom: 1px solid rgba(139, 117, 95, 0.2); /* Тонкая внутренняя линия */
        }
        /* Убираем линию у последней строки, чтобы не конфликтовала с рамкой */
        #'.$unique_id.' .mtp-table tr:last-child td {
            border-bottom: none;
        }
        
        /* ЛЕВАЯ КОЛОНКА (Аналог заголовка) */
        #'.$unique_id.' .mtp-table td:first-child {
            font-weight: 700;
            color: '.$colors['text'].';
            font-family: "TableFont", sans-serif;
            width: 45%;
            /* Фон левой части чуть плотнее, как у заголовка */
            background-color: '.$colors['header_bg'].'; 
            /* Вертикальный разделитель */
            border-right: 2px solid '.$colors['border'].'; 
        }
        
        /* ПРАВАЯ КОЛОНКА (Контент) */
        #'.$unique_id.' .mtp-table td:last-child {
            width: 55%;
            /* Фон правой части прозрачнее */
            background-color: rgba(255, 255, 255, 0.3);
            word-break: break-word;
        }

        /* Hover-эффект для строки */
        #'.$unique_id.' .mtp-table tr:hover td {
            background-color: rgba(255, 255, 255, 0.5);
            transition: background 0.2s ease;
        }

        @media (max-width: 600px) {
            #'.$unique_id.'.mtp-table-wrapper {
                padding: 0 15px;
                margin: 20px 0;
            }
            #'.$unique_id.' .mtp-table td {
                padding: 12px 15px;
                font-size: 0.95rem;
            }
        }
        </style>
        <div id="'.$unique_id.'" class="mtp-table-wrapper">
            <table class="mtp-table">';
        
        foreach ($rows as $row) {
            $html .= '<tr>';
            $html .= '<td>'.esc_html($row['label']).'</td>';
            $html .= '<td>'.esc_html($row['value']).'</td>';
            $html .= '</tr>';
        }
        
        $html .= '</table></div>';
        
        return $html;
    }
}

new KHSH_Tables();
}




