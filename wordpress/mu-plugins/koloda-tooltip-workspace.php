<?php
/**
 * Plugin Name: Koloda Tooltip Workspace
 * Description: Combines HS Tooltip editor boxes into one tabbed workspace.
 *
 * @package KolodaHearthstone
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keep the workspace limited to the screens already supported by hs-tooltip.
 *
 * @return string[]
 */
function kh_tooltip_workspace_post_types(): array
{
    return array('post', 'page');
}

/**
 * @return array<string, string>
 */
function kh_tooltip_workspace_parts(): array
{
    return array(
        'hs_tooltip_search' => 'hs_smart_tooltip_render_search_box',
        'hs_tooltip_bgs_search' => 'hs_smart_tooltip_render_bgs_search_box',
        'hs_tooltip_toggle' => 'hs_smart_tooltip_render_toggle_box',
    );
}

function kh_tooltip_workspace_is_available(): bool
{
    foreach (kh_tooltip_workspace_parts() as $renderer) {
        if (!function_exists($renderer)) {
            return false;
        }
    }

    return true;
}

/**
 * Reuse the existing hs-tooltip renderers so their field IDs and save nonces
 * remain authoritative.
 */
function kh_tooltip_workspace_merge_meta_boxes(WP_Post $post): void
{
    if (!current_user_can('edit_post', $post->ID)
        || !in_array($post->post_type, kh_tooltip_workspace_post_types(), true)
        || !kh_tooltip_workspace_is_available()) {
        return;
    }

    foreach (array_keys(kh_tooltip_workspace_parts()) as $box_id) {
        foreach (array('side', 'normal', 'advanced') as $context) {
            remove_meta_box($box_id, $post->post_type, $context);
        }
    }

    add_meta_box(
        'kh_tooltip_workspace',
        esc_html__('HS Tooltip — вставка карт', 'hs-smart-tooltip'),
        'kh_tooltip_workspace_render',
        $post->post_type,
        'side',
        'high'
    );
}

function kh_tooltip_workspace_render(WP_Post $post): void
{
    if (!current_user_can('edit_post', $post->ID) || !kh_tooltip_workspace_is_available()) {
        return;
    }
    ?>
    <div class="kh-tooltip-workspace" data-kh-tooltip-workspace>
        <div class="kh-tooltip-workspace__tabs" role="tablist" aria-label="<?php echo esc_attr__('Вставка карт', 'hs-smart-tooltip'); ?>">
            <button type="button" class="kh-tooltip-workspace__tab is-active" data-kh-tooltip-tab="cards" role="tab" aria-selected="true" aria-controls="kh-tooltip-panel-cards">
                <?php esc_html_e('Карты', 'hs-smart-tooltip'); ?>
            </button>
            <button type="button" class="kh-tooltip-workspace__tab" data-kh-tooltip-tab="bgs" role="tab" aria-selected="false" aria-controls="kh-tooltip-panel-bgs">
                <?php esc_html_e('Battlegrounds', 'hs-smart-tooltip'); ?>
            </button>

            <label class="kh-tooltip-workspace__pin" title="<?php echo esc_attr__('Панель останется на экране при прокрутке', 'hs-smart-tooltip'); ?>">
                <input type="checkbox" class="kh-tooltip-workspace__pin-input" checked>
                <span><?php esc_html_e('Закрепить', 'hs-smart-tooltip'); ?></span>
            </label>
        </div>

        <div id="kh-tooltip-panel-cards" class="kh-tooltip-workspace__panel is-active" data-kh-tooltip-panel="cards" role="tabpanel">
            <?php hs_smart_tooltip_render_search_box($post); ?>
        </div>
        <div id="kh-tooltip-panel-bgs" class="kh-tooltip-workspace__panel" data-kh-tooltip-panel="bgs" role="tabpanel" hidden>
            <?php hs_smart_tooltip_render_bgs_search_box($post); ?>
        </div>
        <div class="kh-tooltip-workspace__toggle">
            <?php hs_smart_tooltip_render_toggle_box($post); ?>
        </div>
    </div>
    <?php
}

foreach (kh_tooltip_workspace_post_types() as $kh_tooltip_workspace_post_type) {
    add_action(
        'add_meta_boxes_' . $kh_tooltip_workspace_post_type,
        'kh_tooltip_workspace_merge_meta_boxes',
        9999
    );
}
unset($kh_tooltip_workspace_post_type);

add_action(
    'admin_enqueue_scripts',
    static function (string $hook): void {
        if (!in_array($hook, array('post.php', 'post-new.php'), true)
            || !current_user_can('edit_posts')
            || !kh_tooltip_workspace_is_available()) {
            return;
        }

        $assets = array(
            'css' => __DIR__ . '/koloda-tooltip-workspace/workspace.css',
            'js' => __DIR__ . '/koloda-tooltip-workspace/workspace.js',
        );

        if (is_readable($assets['css'])) {
            wp_enqueue_style(
                'koloda-tooltip-workspace',
                plugins_url('koloda-tooltip-workspace/workspace.css', __FILE__),
                array(),
                (string) filemtime($assets['css'])
            );
        }

        if (is_readable($assets['js'])) {
            wp_enqueue_script(
                'koloda-tooltip-workspace',
                plugins_url('koloda-tooltip-workspace/workspace.js', __FILE__),
                array(),
                (string) filemtime($assets['js']),
                true
            );
        }
    }
);
