<?php
/**
 * Plugin Name: Plausible Analytics
 * Description: Adds Plausible tracking, article metadata and read-depth events for kolodahearthstone.com.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class KolodaHS_Plausible_Analytics
{
    private const DOMAIN = 'kolodahearthstone.com';
    private const TRACKER_SRC = 'https://stats.hs-manacost.ru/js/script.outbound-links.tagged-events.pageview-props.js';

    public static function bootstrap(): void
    {
        add_action('wp_head', [__CLASS__, 'render_tracker'], 20);
    }

    public static function render_tracker(): void
    {
        if (is_admin()) {
            return;
        }

        $props = self::content_props();
        $attrs = [
            'defer' => true,
            'data-domain' => self::DOMAIN,
            'src' => self::TRACKER_SRC,
        ];

        foreach ($props as $name => $value) {
            if ($value !== '') {
                $attrs['event-' . $name] = $value;
            }
        }

        echo "\n" . '<script' . self::html_attrs($attrs) . '></script>' . "\n";

        if (is_singular('post')) {
            self::render_article_events_script($props);
        }
    }

    private static function content_props(): array
    {
        $props = [
            'content_type' => self::content_type(),
            'post_type' => is_singular() ? get_post_type() : '',
            'author' => '',
            'primary_category' => '',
            'categories' => '',
            'tags' => '',
        ];

        if (!is_singular()) {
            return array_filter($props, static fn($value) => $value !== '');
        }

        $post_id = get_queried_object_id();
        if (!$post_id) {
            return array_filter($props, static fn($value) => $value !== '');
        }

        $author_id = (int) get_post_field('post_author', $post_id);
        if ($author_id) {
            $props['author'] = self::clean_prop(get_the_author_meta('display_name', $author_id), 120);
        }

        $categories = self::term_names($post_id, 'category', 8);
        $tags = self::term_names($post_id, 'post_tag', 16);

        if ($categories) {
            $props['primary_category'] = self::clean_prop($categories[0], 120);
            $props['categories'] = self::clean_prop(implode(', ', $categories), 240);
        }

        if ($tags) {
            $props['tags'] = self::clean_prop(implode(', ', $tags), 240);
        }

        return array_filter($props, static fn($value) => $value !== '');
    }

    private static function content_type(): string
    {
        if (is_singular('post')) {
            return 'article';
        }
        if (is_page()) {
            return 'page';
        }
        if (is_front_page() || is_home()) {
            return 'home';
        }
        if (is_category()) {
            return 'category_archive';
        }
        if (is_tag()) {
            return 'tag_archive';
        }
        if (is_search()) {
            return 'search';
        }
        if (is_404()) {
            return '404';
        }
        if (is_archive()) {
            return 'archive';
        }

        return 'other';
    }

    private static function term_names(int $post_id, string $taxonomy, int $limit): array
    {
        $terms = get_the_terms($post_id, $taxonomy);
        if (!$terms || is_wp_error($terms)) {
            return [];
        }

        $names = [];
        foreach ($terms as $term) {
            $names[] = self::clean_prop($term->name, 80);
        }

        return array_slice(array_values(array_filter(array_unique($names))), 0, $limit);
    }

    private static function render_article_events_script(array $props): void
    {
        $payload = wp_json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        echo "<script>\n";
        echo "(function(){\n";
        echo "var baseProps={$payload};\n";
        echo "window.plausible=window.plausible||function(){(window.plausible.q=window.plausible.q||[]).push(arguments)};\n";
        echo "function copy(extra){var out={},k;for(k in baseProps){if(Object.prototype.hasOwnProperty.call(baseProps,k)){out[k]=String(baseProps[k]);}}for(k in extra){if(Object.prototype.hasOwnProperty.call(extra,k)){out[k]=String(extra[k]);}}return out;}\n";
        echo "function send(name,props){window.plausible(name,{props:copy(props||{})});}\n";
        echo "function bucket(percent){if(percent>=100)return '100';if(percent>=90)return '90';if(percent>=75)return '75';if(percent>=50)return '50';if(percent>=25)return '25';return '0-24';}\n";
        echo "var start=Date.now(),maxDepth=0,readSent=false,depthSent=false;\n";
        echo "function articleRoot(){return document.querySelector('article')||document.querySelector('.entry-content')||document.querySelector('.post-content')||document.querySelector('.td-post-content')||document.body;}\n";
        echo "function depth(){var root=articleRoot(),doc=document.documentElement,body=document.body,top=window.pageYOffset||doc.scrollTop||body.scrollTop||0,view=window.innerHeight||doc.clientHeight||0;if(root&&root!==body){var rect=root.getBoundingClientRect(),startTop=top+rect.top,height=Math.max(root.scrollHeight,rect.height,1),seen=Math.max(0,Math.min(height,top+view-startTop));return Math.round(seen/height*100);}var full=Math.max(body.scrollHeight,body.offsetHeight,doc.clientHeight,doc.scrollHeight,doc.offsetHeight)-view;return full<=0?100:Math.round(top/full*100);}\n";
        echo "function check(){var current=Math.max(0,Math.min(100,depth()));if(current>maxDepth){maxDepth=current;}if(!readSent&&maxDepth>=90&&Date.now()-start>=15000){readSent=true;send('Article Read',{depth_bucket:bucket(maxDepth)});}}\n";
        echo "function sendDepth(){if(depthSent)return;check();depthSent=true;send('Scroll Depth',{depth_bucket:bucket(maxDepth)});}\n";
        echo "window.addEventListener('scroll',check,{passive:true});window.addEventListener('resize',check,{passive:true});window.addEventListener('pagehide',sendDepth);document.addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden'){sendDepth();}else{check();}});setTimeout(check,1000);setInterval(check,5000);\n";
        echo "})();\n";
        echo "</script>\n";
    }

    private static function html_attrs(array $attrs): string
    {
        $html = '';
        foreach ($attrs as $name => $value) {
            if ($value === true) {
                $html .= ' ' . esc_attr($name);
                continue;
            }
            $html .= ' ' . esc_attr($name) . '="' . esc_attr((string) $value) . '"';
        }

        return $html;
    }

    private static function clean_prop($value, int $max): string
    {
        $value = html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', trim($value));

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }

        return substr($value, 0, $max);
    }
}

KolodaHS_Plausible_Analytics::bootstrap();
