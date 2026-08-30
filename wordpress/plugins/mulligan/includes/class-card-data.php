<?php
if (!defined('ABSPATH')) exit;

/**
 * Загрузка и кеширование данных карт с HearthstoneJSON.
 * Источник: https://hearthstonejson.com/  (https://api.hearthstonejson.com/v1/latest/<locale>/cards.collectible.json)
 */
class HS_Mulligan_Card_Data {

    const TRANSIENT  = 'hs_mulligan_cards_index_v3';
    const URL_RU     = 'https://api.hearthstonejson.com/v1/latest/ruRU/cards.collectible.json';
    const URL_EN     = 'https://api.hearthstonejson.com/v1/latest/enUS/cards.collectible.json';
    const IMAGE_TPL  = 'https://art.hearthstonejson.com/v1/render/latest/ruRU/256x/%s.png';
    const IMAGE_BIG  = 'https://art.hearthstonejson.com/v1/render/latest/ruRU/512x/%s.png';
    const TILE_TPL   = 'https://art.hearthstonejson.com/v1/tiles/%s.png';
    const TTL        = DAY_IN_SECONDS;

    /**
     * Возвращает индекс карт: dbfId => ['name','cost','image','cardId','class'].
     * Если в кеше пусто — возвращаем пустой массив и планируем прогрев в cron,
     * чтобы НЕ блокировать запрос пользователя сетью на 10-15 секунд.
     */
    public static function get_index() {
        $cached = get_transient(self::TRANSIENT);
        if (is_array($cached) && !empty($cached)) return $cached;

        // В контексте админки/CLI/cron — можно блокирующе.
        if (self::is_safe_to_block()) {
            $index = self::fetch();
            if ($index) {
                set_transient(self::TRANSIENT, $index, self::TTL);
                return $index;
            }
            set_transient(self::TRANSIENT, array(), 5 * MINUTE_IN_SECONDS);
            return array();
        }

        // На фронте кеш пустой — планируем прогрев и возвращаем пустой массив.
        // Шорткод деградирует мягко (карты будут показаны как "dbfId N" без картинок),
        // но запрос не зависнет на 10+ секунд.
        if (!wp_next_scheduled('hs_mulligan_refresh_cards')) {
            wp_schedule_single_event(time() + 5, 'hs_mulligan_refresh_cards');
        }
        return array();
    }

    private static function is_safe_to_block() {
        if (defined('DOING_CRON') && DOING_CRON) return true;
        if (defined('WP_CLI') && WP_CLI) return true;
        if (is_admin() && !wp_doing_ajax()) return true;
        // AJAX-декодер в админке (TinyMCE) — допустимо блокировать, автор видит индикатор.
        if (wp_doing_ajax() && current_user_can('edit_posts') &&
            isset($_REQUEST['action']) && strpos((string) $_REQUEST['action'], 'hs_mulligan_') === 0) {
            return true;
        }
        return false;
    }

    /**
     * Принудительный прогрев — вызывается из cron / на активации плагина.
     */
    public static function refresh() {
        $index = self::fetch();
        if ($index) {
            set_transient(self::TRANSIENT, $index, self::TTL);
            return true;
        }
        return false;
    }

    private static function fetch() {
        foreach (array(self::URL_RU, self::URL_EN) as $url) {
            $resp = wp_remote_get($url, array(
                'timeout'     => 12,
                'redirection' => 3,
                'headers'     => array(
                    'Accept'          => 'application/json',
                    'Accept-Encoding' => 'gzip, deflate',
                ),
            ));
            if (is_wp_error($resp)) continue;
            if (wp_remote_retrieve_response_code($resp) !== 200) continue;
            $body = wp_remote_retrieve_body($resp);
            $data = json_decode($body, true);
            if (!is_array($data)) continue;

            $idx = array();
            foreach ($data as $card) {
                if (!isset($card['dbfId'])) continue;
                $dbf = (int) $card['dbfId'];
                $card_id = isset($card['id']) ? (string) $card['id'] : '';
                $idx[$dbf] = array(
                    'name'    => isset($card['name']) ? (string) $card['name'] : '',
                    'cost'    => isset($card['cost']) ? (int) $card['cost'] : 0,
                    'cardId'  => $card_id,
                    'class'   => isset($card['cardClass']) ? (string) $card['cardClass'] : '',
                    'image'   => $card_id ? sprintf(self::IMAGE_TPL, $card_id) : '',
                    'imageBig'=> $card_id ? sprintf(self::IMAGE_BIG, $card_id) : '',
                    'tile'    => $card_id ? sprintf(self::TILE_TPL, $card_id) : '',
                    'text'    => isset($card['text']) ? (string) $card['text'] : '',
                    'type'    => isset($card['type']) ? (string) $card['type'] : '',
                    'rarity'  => isset($card['rarity']) ? (string) $card['rarity'] : '',
                );
            }
            if ($idx) return $idx;
        }
        return null;
    }
}
