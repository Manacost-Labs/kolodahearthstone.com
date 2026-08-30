<?php
/*
Plugin Name: KolodaHearthstone: Social
Description: Эмодзи, Лайки, Статусы и Счетчик просмотров
Version: 2.2
Author: Manacost
*/

if (!defined('ABSPATH')) exit;

if (!class_exists('My_Community_Plus')) {

class My_Community_Plus {

    public function __construct() {
        // ЯДРО: Трекер просмотров
        add_action('wp_footer', [$this, 'tracker_script']);
        add_action('wp_ajax_mcp_track_view', [$this, 'ajax_track_view']);
        add_action('wp_ajax_nopriv_mcp_track_view', [$this, 'ajax_track_view']);
        
        // ВИЗУАЛ: Просмотры
        // СОВМЕСТИМОСТЬ С AIOSEO: Используем приоритет 20, чтобы наш фильтр применялся после SEO плагинов
        add_filter('the_content', [$this, 'add_views_counter'], 20);
        
        // ЛАЙКИ КОММЕНТАРИЕВ
        add_filter('comment_text', [$this, 'add_like_btn'], 20, 2);
        add_action('wp_ajax_mcp_like', [$this, 'ajax_like']);
        add_action('wp_ajax_nopriv_mcp_like', [$this, 'ajax_like']);
        
        // ЭМОДЗИ (Priority 0 - самый ранний запуск)
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        add_action('wp_ajax_mcp_save_emoji', [$this, 'ajax_save_emoji']);
        add_action('wp_ajax_mcp_delete_emoji', [$this, 'ajax_delete_emoji']);
        // Используем 0, чтобы сработать до wptexturize и прочего
        add_filter('comment_text', [$this, 'replace_emojis'], 0); 

        // СКРИПТЫ
        add_action('wp_footer', [$this, 'footer_scripts']);

        // ПРОФИЛЬ
        add_filter('get_comment_author_link', [$this, 'render_badges_in_comments_safe'], 10, 3);
        add_filter('mtp_profile_badges', [$this, 'render_badge_in_profile'], 10, 2);
        add_action('mtp_profile_form_fields', [$this, 'render_inputs']);
        add_action('mtp_profile_save_data', [$this, 'save_inputs']);
        add_action('mtp_profile_socials', [$this, 'render_social_buttons']);
        add_action('mtp_profile_stats', [$this, 'render_stats_row']);

        // АДМИНКА CRM
        add_action('wp_ajax_mcp_update_slot', [$this, 'ajax_update_slot']);
        add_action('wp_ajax_mcp_update_title', [$this, 'ajax_update_user_title']);
    }

    // ================== НОВОЕ: ЭМОДЗИ ==================
    public function admin_scripts($hook) {
        if (strpos($hook, 'mcp-emojis') !== false) {
            wp_enqueue_media();
        }
    }

    public function replace_emojis($content) {
        $emojis = get_option('mcp_custom_emojis', []);
        if (empty($emojis)) return $content;

        foreach ($emojis as $code => $url) {
            // Добавлены стили box-shadow:none и display:inline для корректного отображения
            $img = '<img src="'.esc_url($url).'" alt="'.$code.'" style="vertical-align:middle; width:24px; height:24px; display:inline !important; border:none !important; box-shadow:none !important; margin:0 2px !important;" class="mcp-emoji">';
            $content = str_replace($code, $img, $content);
        }
        return $content;
    }

    public function page_emojis() {
        $emojis = get_option('mcp_custom_emojis', []);
        ?>
        <div class="wrap">
            <h1>🎨 Управление Эмодзи</h1>
            <p>Загружайте картинки (рекомендуется 256x256 px). В комментариях используйте код для вставки.</p>
            
            <details style="background:#fff; border:1px solid #ccd0d4; padding:15px; margin-bottom:20px; box-shadow:0 1px 1px rgba(0,0,0,.04); max-width:600px;">
                <summary style="cursor:pointer; font-weight:600; font-size:1.1em; outline:none;">▶ Добавить новый эмодзи (Нажмите, чтобы развернуть)</summary>
                
                <div style="margin-top:20px; border-top:1px solid #eee; padding-top:15px;">
                    <label style="display:block; margin-bottom:5px; font-weight:600;">Код эмодзи (например :smile:):</label>
                    <input type="text" id="new-emoji-code" placeholder=":smile:" style="width:200px; padding:6px; font-size:14px;">
                    
                    <div style="margin-top:15px;">
                        <button class="button" id="upload-emoji-btn">📷 Выбрать картинку</button>
                    </div>
                    <input type="hidden" id="new-emoji-url">
                    <div id="preview-emoji" style="margin-top:10px; min-height:32px;"></div>
                    
                    <button class="button button-primary" onclick="mcpSaveEmoji()" style="margin-top:15px;">Сохранить Эмодзи</button>
                </div>
            </details>

            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Код</th><th>Картинка</th><th>Действие</th></tr></thead>
                <tbody id="emoji-list">
                    <?php if(empty($emojis)) echo '<tr><td colspan="3">Эмодзи нет. Добавьте первый!</td></tr>'; ?>
                    <?php foreach($emojis as $code => $url): ?>
                        <tr>
                            <td><code><?php echo esc_html($code); ?></code></td>
                            <td><img src="<?php echo esc_url($url); ?>" style="width:32px; height:32px; vertical-align:middle;"></td>
                            <td><button class="button button-link-delete" onclick="mcpDelEmoji('<?php echo esc_js($code); ?>')">Удалить</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <script>
            jQuery(document).ready(function($){
                var frame;
                $('#upload-emoji-btn').click(function(e) {
                    e.preventDefault();
                    if (frame) { frame.open(); return; }
                    frame = wp.media({ title: 'Выберите эмодзи', button: { text: 'Использовать' }, multiple: false });
                    frame.on('select', function() {
                        var attachment = frame.state().get('selection').first().toJSON();
                        $('#new-emoji-url').val(attachment.url);
                        $('#preview-emoji').html('<img src="'+attachment.url+'" style="width:48px; border:1px solid #ccc; padding:2px;">');
                    });
                    frame.open();
                });
            });
            function mcpSaveEmoji(){
                var c = jQuery('#new-emoji-code').val();
                var u = jQuery('#new-emoji-url').val();
                if(!c || !u) return alert('Заполните код и выберите картинку');
                jQuery.post(ajaxurl, {action:'mcp_save_emoji', code:c, url:u}, function(r){ location.reload(); });
            }
            function mcpDelEmoji(c){
                if(confirm('Удалить эмодзи '+c+'?')) jQuery.post(ajaxurl, {action:'mcp_delete_emoji', code:c}, function(r){ location.reload(); });
            }
        </script>
        <?php
    }

    public function ajax_save_emoji() {
        if(!current_user_can('manage_options')) wp_send_json_error();
        $code = sanitize_text_field($_POST['code']);
        $url = esc_url_raw($_POST['url']);
        $emojis = get_option('mcp_custom_emojis', []);
        $emojis[$code] = $url;
        update_option('mcp_custom_emojis', $emojis);
        wp_send_json_success();
    }

    public function ajax_delete_emoji() {
        if(!current_user_can('manage_options')) wp_send_json_error();
        $code = sanitize_text_field($_POST['code']);
        $emojis = get_option('mcp_custom_emojis', []);
        if(isset($emojis[$code])) unset($emojis[$code]);
        update_option('mcp_custom_emojis', $emojis);
        wp_send_json_success();
    }

    // ================== ВИЗУАЛ В СТАТЬЕ (ПРОСМОТРЫ) ==================
    public function add_views_counter($content) {
        if (!is_singular('post') && !is_singular('user_deck')) return $content;
        if (is_admin()) return $content;

        $pid = get_the_ID();
        $views = (int) get_post_meta($pid, 'mcp_post_views', true);
        if ($views == 0) $views = 1;

        $html = "<div class='mcp-post-footer' style='margin-top:20px; padding-top:15px; border-top:1px solid #edf2f7; display:flex; align-items:center; justify-content:flex-end;'>";
        $html .= "<div style='background:#f7fafc; border:1px solid #cbd5e0; color:#2d3748; padding:5px 12px; border-radius:20px; font-size:0.85rem; font-weight:700; display:flex; align-items:center; gap:8px;' title='Просмотры'>";
        $html .= "<span class='dashicons dashicons-visibility' style='font-size:18px; width:18px; height:18px; color:#4a5568;'></span> {$views}";
        $html .= "</div></div>";
        
        return $content . $html;
    }

    // ================== ЛАЙКИ И СКРИПТЫ ==================
    public function footer_scripts() { 
        ?> 
        <script>
            function mcpLike(b){
                if(b.classList.contains('l'))return;
                b.classList.add('l');
                jQuery.post('<?php echo admin_url('admin-ajax.php');?>',{action:'mcp_like',cid:b.getAttribute('data-id')},function(r){
                    b.classList.remove('l');
                    if(r.success){
                        b.querySelector('.cnt').innerText=r.data.likes;
                        b.classList.toggle('liked');
                    }
                });
            } 
        </script> 
        <style>
            .mcp-like-btn{background:none; border:1px solid #e2e8f0; padding:4px 12px; border-radius:6px; cursor:pointer; color:#718096; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:5px; transition:all 0.2s;}
            .mcp-like-btn:hover{border-color:#cbd5e0; color:#4a5568;}
            .mcp-like-btn.liked{color:#e53e3e; background:#fff5f5; border-color:#feb2b2;}
            .dashicons { vertical-align: middle; }
            /* Эмодзи в комментах */
            .mcp-emoji { box-shadow: none !important; margin: 0 2px !important; }
        </style> 
        <?php 
    }

    public function ajax_like() { if (!is_user_logged_in()) wp_send_json_error(); $cid = intval($_POST['cid']); $uid = get_current_user_id(); $user_likes = get_user_meta($uid, 'mcp_liked_comments', true); if (!is_array($user_likes)) $user_likes = []; $likes_count = (int) get_comment_meta($cid, 'mcp_likes', true); if (in_array($cid, $user_likes)) { $likes_count--; if ($likes_count < 0) $likes_count = 0; $key = array_search($cid, $user_likes); unset($user_likes[$key]); } else { $likes_count++; $user_likes[] = $cid; $comment = get_comment($cid); if ($comment && $comment->user_id) { $author_karma = (int) get_user_meta($comment->user_id, 'mcp_karma', true); update_user_meta($comment->user_id, 'mcp_karma', $author_karma + 1); } } update_user_meta($uid, 'mcp_liked_comments', array_values($user_likes)); update_comment_meta($cid, 'mcp_likes', $likes_count); wp_send_json_success(['likes' => $likes_count]); }
    public function add_like_btn($content, $comment) { $likes = (int) get_comment_meta($comment->comment_ID, 'mcp_likes', true); $uid = get_current_user_id(); $is_liked = false; if ($uid) { $user_likes = get_user_meta($uid, 'mcp_liked_comments', true); if (is_array($user_likes) && in_array($comment->comment_ID, $user_likes)) $is_liked = true; } $cls = $is_liked ? 'liked' : ''; return $content . "<div style='margin-top:8px;'><button class='mcp-like-btn {$cls}' data-id='{$comment->comment_ID}' onclick='mcpLike(this)'><span class='dashicons dashicons-heart' style='font-size:14px;width:14px;height:14px;'></span> <span class='cnt'>{$likes}</span></button></div>"; }

    // ================== ПРОФИЛЬ И CRM ==================
    public function render_badges_in_comments_safe($return, $author, $comment_id) { $comment = get_comment($comment_id); $uid = $comment->user_id; if (!$uid || is_admin()) return $return; return $this->get_badges_html($uid) . ' ' . $return; }
    public function render_badge_in_profile($html, $uid) { return $html . '<div style="margin-top:10px;">' . $this->get_badges_html($uid) . '</div>'; }
    private function get_badges_html($uid) { $s1=get_user_meta($uid,'mcp_title_s1',true); $s2=get_user_meta($uid,'mcp_title_s2',true); $s3=get_user_meta($uid,'mcp_title_s3',true); $c=get_user_meta($uid,'mcp_custom_title',true); $h=''; if($c)$h.=$this->badge($c,'gold'); if($s1)$h.=$this->badge_by_name($s1); if($s2)$h.=$this->badge_by_name($s2); if($s3)$h.=$this->badge_by_name($s3); return $h; }
    private function badge($l,$c){ $s="display:inline-block;padding:3px 8px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:white;margin-right:4px;vertical-align:middle;line-height:1;box-shadow:0 1px 2px rgba(0,0,0,0.1);"; switch($c){ case 'gold':$b="background:#d69e2e;";break; case 'blue':$b="background:#3182ce;";break; case 'purple':$b="background:#805ad5;";break; case 'red':$b="background:#e53e3e;";break; case 'green':$b="background:#38a169;";break; default:$b="background:#718096;";} return "<span class='mcp-badge' style='{$s} {$b}'>{$l}</span>"; }
    private function badge_by_name($n){ if(!$n)return''; $c='blue'; if(mb_stripos($n,'Ютуб')!==false)$c='red'; if(mb_stripos($n,'Стрим')!==false)$c='purple'; if(mb_stripos($n,'Автор')!==false)$c='green'; if(mb_stripos($n,'Легенда')!==false)$c='gold'; return $this->badge($n,$c); }
    public function render_stats_row($u){ 
        $k=(int)get_user_meta($u,'mcp_karma',true); 
        $c=get_comments(['user_id'=>$u,'count'=>true]); 
        $d=count_user_posts($u,'user_deck'); 
        $favs = get_user_meta($u, 'mtp_user_favs', true); 
        $s = is_array($favs) ? count($favs) : 0; 
        
        $stats = [
            ['num'=>$k,'label'=>'КАРМА','color'=>'linear-gradient(135deg, #FFC107 0%, #FFA000 100%)','shadow'=>'rgba(255,193,7,0.25)'],
            ['num'=>$c,'label'=>'КОММЕНТОВ','color'=>'linear-gradient(135deg, #3182ce 0%, #2c5282 100%)','shadow'=>'rgba(49,130,206,0.25)'],
            ['num'=>$d,'label'=>'КОЛОД','color'=>'linear-gradient(135deg, #38a169 0%, #2f855a 100%)','shadow'=>'rgba(56,161,105,0.25)'],
            ['num'=>$s,'label'=>'СОХРАНЕНО','color'=>'linear-gradient(135deg, #805ad5 0%, #6b46c1 100%)','shadow'=>'rgba(128,90,213,0.25)']
        ];
        
        echo '<div style="display:grid; grid-template-columns:repeat(4,1fr); gap:20px; margin-bottom:40px;" class="mtp-stats-grid">';
        foreach($stats as $stat) {
            $box_style = "text-align:center; padding:28px 20px; background:rgba(250,245,240,0.85); border-radius:16px; box-shadow:0 6px 20px {$stat['shadow']}, 0 0 0 1px rgba(139,117,95,0.12); border:none; transition:all 0.35s cubic-bezier(0.4, 0, 0.2, 1); position:relative; overflow:hidden; backdrop-filter:blur(10px);";
            $box_style_hover = "transform:translateY(-4px) scale(1.02); box-shadow:0 12px 32px {$stat['shadow']}, 0 0 0 1px rgba(139,117,95,0.15);";
            $num_style = "font-weight:900; font-size:2.2rem; background:{$stat['color']}; -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; line-height:1.2; margin-bottom:8px; letter-spacing:-0.03em; text-shadow:none; display:block;";
            $lbl_style = "font-size:0.75rem; text-transform:uppercase; color:#6b5d4a; font-weight:900; letter-spacing:1px; margin-top:4px;";
            
            echo "<div style='$box_style' onmouseover=\"this.style.cssText='$box_style $box_style_hover'\" onmouseout=\"this.style.cssText='$box_style'\">";
            echo "<div style='$num_style'>{$stat['num']}</div>";
            echo "<div style='$lbl_style'>{$stat['label']}</div>";
            echo "</div>";
        }
        echo '</div>';
        echo '<style>
        @media (max-width:900px){
            .mtp-stats-grid{grid-template-columns:repeat(2,1fr) !important;gap:15px !important;margin-bottom:30px !important}
            .mtp-stats-grid > div{padding:20px 15px !important}
            .mtp-stats-grid > div > div:first-child{font-size:1.8rem !important}
        }
        @media (max-width:600px){
            .mtp-stats-grid{grid-template-columns:repeat(2,1fr) !important;gap:12px !important;margin-bottom:25px !important}
            .mtp-stats-grid > div{padding:18px 12px !important}
            .mtp-stats-grid > div > div:first-child{font-size:1.6rem !important}
            .mtp-stats-grid > div > div:last-child{font-size:0.7rem !important}
        }
        @media (max-width:400px){
            .mtp-stats-grid{grid-template-columns:1fr !important;gap:10px !important}
            .mtp-stats-grid > div{padding:15px 10px !important}
        }
        </style>';
    }

    public function page_crm_dashboard(){ 
        $s=isset($_GET['s'])?sanitize_text_field($_GET['s']):''; 
        $a=['number'=>200]; 
        if($s){
            if(is_numeric($s))$a['include']=[intval($s)];
            else $a['search']="*{$s}*";
        } 
        $us=get_users($a); 
        $tr=[];$tb=[];$tc=[];$tk=[];$td=[];
        
        // ОПТИМИЗАЦИЯ: Загружаем все мета-данные одним запросом для всех пользователей
        $user_ids = wp_list_pluck($us, 'ID');
        $all_meta = [];
        if (!empty($user_ids)) {
            global $wpdb;
            $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
            $meta_keys = ['mcp_total_views', 'mcp_liked_comments', 'mcp_title_s1', 'mcp_title_s2', 'mcp_title_s3'];
            $meta_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
            
            $meta_query = $wpdb->prepare(
                "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} 
                WHERE user_id IN ($placeholders) 
                AND meta_key IN ($meta_placeholders)",
                array_merge($user_ids, $meta_keys)
            );
            $meta_results = $wpdb->get_results($meta_query);
            
            // Группируем мета-данные по user_id
            foreach ($meta_results as $meta) {
                if (!isset($all_meta[$meta->user_id])) {
                    $all_meta[$meta->user_id] = [];
                }
                $all_meta[$meta->user_id][$meta->meta_key] = $meta->meta_value;
            }
        }
        
        // ОПТИМИЗАЦИЯ: Загружаем количество постов и комментариев одним запросом
        $posts_count = [];
        $comments_count = [];
        if (!empty($user_ids)) {
            // Количество постов user_deck для каждого пользователя
            $posts_placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
            $posts_query = $wpdb->prepare(
                "SELECT post_author, COUNT(*) as count FROM {$wpdb->posts} 
                WHERE post_author IN ($posts_placeholders) 
                AND post_type = 'user_deck' 
                AND post_status = 'publish'
                GROUP BY post_author",
                ...$user_ids
            );
            $posts_results = $wpdb->get_results($posts_query);
            foreach ($posts_results as $row) {
                $posts_count[$row->post_author] = (int)$row->count;
            }
            
            // Количество комментариев для каждого пользователя
            $comments_placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
            $comments_query = $wpdb->prepare(
                "SELECT user_id, COUNT(*) as count FROM {$wpdb->comments} 
                WHERE user_id IN ($comments_placeholders) 
                AND comment_approved = '1'
                GROUP BY user_id",
                ...$user_ids
            );
            $comments_results = $wpdb->get_results($comments_query);
            foreach ($comments_results as $row) {
                $comments_count[$row->user_id] = (int)$row->count;
            }
        }
        
        foreach($us as $u){ 
            $uid=$u->ID; 
            // ОПТИМИЗАЦИЯ: Используем предзагруженные данные
            $v=(int)(isset($all_meta[$uid]['mcp_total_views']) ? $all_meta[$uid]['mcp_total_views'] : 0); 
            $d=isset($posts_count[$uid]) ? $posts_count[$uid] : 0; 
            $c=isset($comments_count[$uid]) ? $comments_count[$uid] : 0; 
            $liked_comments = isset($all_meta[$uid]['mcp_liked_comments']) ? $all_meta[$uid]['mcp_liked_comments'] : '';
            $l=count((array)($liked_comments ? maybe_unserialize($liked_comments) : [])); 
            if(!$s){
                $tr[$uid]=$v;
                $tb[$uid]=$d;
                $tc[$uid]=$c;
                $tk[$uid]=$l;
            } 
            $td[]=[
                'uid'=>$uid,
                'user'=>$u,
                'views'=>$v,
                'decks'=>$d,
                'comments'=>$c,
                'likes'=>$l,
                's1'=>isset($all_meta[$uid]['mcp_title_s1']) ? $all_meta[$uid]['mcp_title_s1'] : '',
                's2'=>isset($all_meta[$uid]['mcp_title_s2']) ? $all_meta[$uid]['mcp_title_s2'] : '',
                's3'=>isset($all_meta[$uid]['mcp_title_s3']) ? $all_meta[$uid]['mcp_title_s3'] : ''
            ]; 
        } 
        echo '<div class="wrap"><h1 style="margin-bottom:20px;">🏆 Центр Управления</h1><form method="get" style="background:#fff;padding:15px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.1);display:flex;gap:10px;"><input type="hidden" name="page" value="mcp-stats"><input type="text" name="s" value="'.esc_attr($s).'" placeholder="ID или Имя..." style="width:300px;"><button class="button button-primary">Найти</button>'; 
        if($s)echo '<a href="?page=mcp-stats" class="button">Сбросить</a>'; 
        echo '</form>'; 
        if(!$s){ 
            arsort($tr); 
            arsort($tb); 
            arsort($tc); 
            arsort($tk); 
            echo '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:30px;">'; 
            $this->render_nomination_card('👁️ Читатели',$tr); 
            $this->render_nomination_card('🃏 Строители',$tb); 
            $this->render_nomination_card('💬 Общение',$tc); 
            $this->render_nomination_card('❤️ Карма',$tk); 
            echo '</div>'; 
        } 
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th style="width:50px;">ID</th><th style="width:200px;">Игрок</th><th>Титул 1</th><th>Титул 2</th><th>Титул 3</th><th>👁️</th><th>🃏</th><th>💬</th><th>❤️</th></tr></thead><tbody>'; 
        $tl=['— Пусто —'=>'','🔴 Ютубер'=>'Ютубер','🟣 Стример'=>'Стример','✍️ Автор'=>'Автор','🏆 Легенда'=>'Легенда','🛡️ Модератор'=>'Модератор','👑 VIP'=>'VIP']; 
        if(empty($td))echo '<tr><td colspan="9">Не найдено</td></tr>'; 
        foreach($td as $r){ 
            echo "<tr><td>#{$r['uid']}</td><td>".get_avatar($r['uid'],20)." <strong><a href='".get_edit_user_link($r['uid'])."'>{$r['user']->display_name}</a></strong></td><td>".$this->render_select($r['uid'],1,$r['s1'],$tl)."</td><td>".$this->render_select($r['uid'],2,$r['s2'],$tl)."</td><td>".$this->render_select($r['uid'],3,$r['s3'],$tl)."</td><td>{$r['views']}</td><td>{$r['decks']}</td><td>{$r['comments']}</td><td>{$r['likes']}</td></tr>"; 
        } 
        echo '</tbody></table></div><script>function mcpSave(uid,slot,val){jQuery.post(ajaxurl,{action:"mcp_update_slot",uid:uid,slot:slot,val:val});} function mcpCustom(uid,slot){let t=prompt("Свой титул:");if(t)mcpSave(uid,slot,t);}</script>'; 
    }
    private function render_nomination_card($t, $d) { echo '<div style="background:#fff;padding:15px;border-top:3px solid #3182ce;box-shadow:0 1px 3px rgba(0,0,0,0.1);"><h4>'.$t.'</h4><ul style="margin:0;padding:0;">'; $i=0; foreach($d as $u=>$v){if($i++>=5)break;if($v==0)continue;$us=get_userdata($u);echo "<li style='display:flex;justify-content:space-between;border-bottom:1px dashed #eee;padding:3px 0;'><span>{$us->display_name}</span><strong>{$v}</strong></li>";} echo '</ul></div>'; }
    private function render_select($uid,$s,$c,$l){ $h="<select onchange='mcpSave($uid,$s,this.value)' style='width:90px;font-size:11px;'>"; foreach($l as $k=>$v){ $sel=($c==$v)?'selected':''; $h.="<option value='$v' $sel>$k</option>"; } if($c&&!in_array($c,$l))$h.="<option value='$c' selected>$c</option>"; return $h."</select><button class='button button-small' onclick='mcpCustom($uid,$s)'>+</button>"; }
    public function admin_menu(){ 
        add_menu_page('Community','Community','manage_options','mcp-stats',[$this,'page_crm_dashboard']); 
        add_submenu_page('mcp-stats','Эмодзи','Эмодзи','manage_options','mcp-emojis',[$this,'page_emojis']);
        add_submenu_page('mcp-stats','Settings','Settings','manage_options','mcp-set',[$this,'page_settings']);
    }
    public function ajax_update_slot() { if(!current_user_can('manage_options'))wp_send_json_error(); update_user_meta(intval($_POST['uid']),'mcp_title_s'.intval($_POST['slot']),sanitize_text_field($_POST['val'])); wp_send_json_success(); }
    public function ajax_update_user_title() { if(!current_user_can('manage_options'))wp_send_json_error(); update_user_meta(intval($_POST['uid']),'mcp_manual_status',sanitize_text_field($_POST['val'])); wp_send_json_success(); }
    public function tracker_script() { if(!is_singular('post'))return; ?> <script>setTimeout(function(){jQuery.post('<?php echo admin_url('admin-ajax.php');?>',{action:'mcp_track_view',pid:<?php echo get_the_ID();?>});},3000);</script> <?php }
    public function ajax_track_view() { $p=intval($_POST['pid']); update_post_meta($p,'mcp_post_views',(int)get_post_meta($p,'mcp_post_views',true)+1); if(is_user_logged_in()){$u=get_current_user_id();update_user_meta($u,'mcp_total_views',(int)get_user_meta($u,'mcp_total_views',true)+1);} wp_send_json_success(); }
    public function render_social_buttons($u){ $t=get_user_meta($u,'mcp_twitch',true); $y=get_user_meta($u,'mcp_youtube',true); if($t)echo '<a href="'.$t.'" target="_blank" style="display:block;text-align:center;background:linear-gradient(135deg, #9146FF 0%, #7b3ae8 100%);color:white;padding:12px;border-radius:10px;font-weight:800;margin-bottom:10px;text-decoration:none;font-size:0.9rem;box-shadow:0 4px 12px rgba(145,70,255,0.25);transition:all 0.3s ease;letter-spacing:0.3px;" onmouseover="this.style.transform=\'translateY(-2px)\'; this.style.boxShadow=\'0 6px 16px rgba(145,70,255,0.35)\'" onmouseout="this.style.transform=\'translateY(0)\'; this.style.boxShadow=\'0 4px 12px rgba(145,70,255,0.25)\'">Twitch</a>'; if($y)echo '<a href="'.$y.'" target="_blank" style="display:block;text-align:center;background:linear-gradient(135deg, #FF0000 0%, #cc0000 100%);color:white;padding:12px;border-radius:10px;font-weight:800;text-decoration:none;font-size:0.9rem;box-shadow:0 4px 12px rgba(255,0,0,0.25);transition:all 0.3s ease;letter-spacing:0.3px;" onmouseover="this.style.transform=\'translateY(-2px)\'; this.style.boxShadow=\'0 6px 16px rgba(255,0,0,0.35)\'" onmouseout="this.style.transform=\'translateY(0)\'; this.style.boxShadow=\'0 4px 12px rgba(255,0,0,0.25)\'">YouTube</a>'; }
    public function render_inputs($u){ $t=get_user_meta($u,'mcp_twitch',true); $y=get_user_meta($u,'mcp_youtube',true); echo '<label class="mtp-label">Twitch</label><input type="text" name="mcp_twitch" class="mtp-input" value="'.esc_attr($t).'">'; echo '<label class="mtp-label">YouTube</label><input type="text" name="mcp_youtube" class="mtp-input" value="'.esc_attr($y).'">'; if(user_can($u,'manage_options')){ $tt=get_user_meta($u,'mcp_custom_title',true); echo '<label class="mtp-label">Титул (Admin)</label><input type="text" name="mcp_custom_title" class="mtp-input" value="'.esc_attr($tt).'">'; } }
    public function save_inputs($u){ if(isset($_POST['mcp_twitch'])) update_user_meta($u,'mcp_twitch',sanitize_text_field($_POST['mcp_twitch'])); if(isset($_POST['mcp_youtube'])) update_user_meta($u,'mcp_youtube',sanitize_text_field($_POST['mcp_youtube'])); if(isset($_POST['mcp_custom_title'])&&user_can($u,'manage_options')) update_user_meta($u,'mcp_custom_title',sanitize_text_field($_POST['mcp_custom_title'])); }
    public function register_settings(){register_setting('mcp_grp','mcp_status_rules');} public function page_settings(){ echo '<div class="wrap"><h1>Настройки</h1><form method="post" action="options.php">'; settings_fields('mcp_grp'); do_settings_sections('mcp_grp'); echo '<textarea name="mcp_status_rules">'.esc_textarea(get_option('mcp_status_rules')).'</textarea>'; submit_button(); echo '</form></div>'; }
}
new My_Community_Plus();
}