<?php
/*
Plugin Name: KolodaHearthstone: Shortcodes
Description: Объединенный плагин для работы с кастомными шорткодами (баннеры, спойлеры, таблицы, опросы)
Version: 2.0
Author: Manacost
*/

if (!defined('ABSPATH')) exit;

// Определяем константы для путей плагина
define('KHSH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KHSH_PLUGIN_URL', plugins_url('', __FILE__));

// Загрузка модулей
require_once KHSH_PLUGIN_DIR . 'modules/shortcodes/shortcodes-core.php';
require_once KHSH_PLUGIN_DIR . 'modules/banner/banner.php';
require_once KHSH_PLUGIN_DIR . 'modules/spoilers/spoilers.php';
require_once KHSH_PLUGIN_DIR . 'modules/tables/tables.php';
require_once KHSH_PLUGIN_DIR . 'modules/polls/polls.php';

// Загрузка админ-панели
require_once KHSH_PLUGIN_DIR . 'admin-page.php';

