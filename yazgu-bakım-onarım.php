<?php
/**
 * Plugin Name: YAZGU - Akıllı Bakım ve Onarım (Pro Sürüm)
 * Description: WordPress + BuddyBoss için profesyonel bakım ve onarım eklentisi. Otomatik onarım, güvenli loglama, arşivleme ve admin panelinden log görüntüleme içerir.
 * Version: 3.0
 * Author: YAZGU.COM
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// --- 1. LOG sistemi --- //
function yazgu_log($message) {
    $log_file = WP_CONTENT_DIR . '/yazgu-db-health.log';
    $time = current_time('mysql');

    // Log boyutu kontrolü (5MB üzeri ise arşivle)
    if ( file_exists($log_file) && filesize($log_file) > 5 * 1024 * 1024 ) {
        $archive_name = WP_CONTENT_DIR . '/yazgu-db-health-' . date('Ymd-His') . '.log';
        @rename($log_file, $archive_name);
        @file_put_contents($log_file, "[$time] Log arşivlendi: $archive_name\n", FILE_APPEND | LOCK_EX);
    }

    @file_put_contents($log_file, "[$time] $message\n", FILE_APPEND | LOCK_EX);
}

// --- 2. Veritabanı bakım fonksiyonu --- //
function yazgu_db_health_check() {
    global $wpdb;
    yazgu_log('Bakım işlemi başlatıldı.');

    // Action Scheduler kilitlerini temizle
    $deleted = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'action_scheduler_lock_%'" );
    yazgu_log("Action Scheduler kilitleri temizlendi ($deleted kayıt)." );

    // MySQL bağlantısını yenile
    if ( $wpdb->dbh ) {
        @mysqli_ping( $wpdb->dbh );
        yazgu_log('MySQL bağlantısı yenilendi.');
    }

    // Out of sync hatalarını önle
    if ( function_exists('mysqli_more_results') && mysqli_more_results($wpdb->dbh) ) {
        while ( mysqli_more_results($wpdb->dbh) && mysqli_next_result($wpdb->dbh) ) {
            mysqli_use_result($wpdb->dbh);
        }
        yazgu_log('MySQL bağlantı senkronizasyonu sıfırlandı.');
    }

    // Tablo kontrolü (hızlı mod)
    $tables = $wpdb->get_results('SHOW TABLES', ARRAY_N);
    foreach ( $tables as $table ) {
        $check = $wpdb->get_row( "CHECK TABLE {$table[0]}" );
        if ( isset($check->Msg_type) && $check->Msg_type === 'error' ) {
            $wpdb->query( "REPAIR TABLE {$table[0]}" );
            yazgu_log("{$table[0]} tablosu onarıldı.");
        }
    }

    // Güvenli transient temizliği
    $expired_transients = $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' AND option_name NOT LIKE '_transient_timeout_%' AND option_name IN (SELECT REPLACE(option_name, '_timeout', '') FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP())"
    );
    yazgu_log("Süresi dolmuş transientler temizlendi ($expired_transients kayıt)." );

    // LiteSpeed ana sayfa önbellek temizliği
    if ( class_exists('LiteSpeed_Cache_API') ) {
        LiteSpeed_Cache_API::purge_home();
        yazgu_log('LiteSpeed ana sayfa önbelleği temizlendi.');
    }

    update_option('yazgu_db_health_last_check', current_time('mysql'));
    yazgu_log('Bakım işlemi başarıyla tamamlandı.');
}

// --- 3. Cron planlama (saatlik) --- //
add_action('yazgu_db_health_event', 'yazgu_db_health_check');
if ( ! wp_next_scheduled('yazgu_db_health_event') ) {
    wp_schedule_event(time(), 'hourly', 'yazgu_db_health_event');
}

// --- 4. Yönetici paneli menüsü --- //
add_action('admin_menu', function() {
    add_menu_page(
        'YAZGU Bakım',
        'YAZGU Bakım',
        'manage_options',
        'yazgu-db-health',
        'yazgu_db_health_admin_page',
        'dashicons-shield-alt',
        80
    );
});

// --- 5. Admin paneli içeriği (log görüntüleme) --- //
function yazgu_db_health_admin_page() {
    $log_file = WP_CONTENT_DIR . '/yazgu-db-health.log';
    echo '<div class="wrap"><h1>🩺 YAZGU - Bakım ve Onarım Logları</h1>';

    if ( file_exists($log_file) ) {
        echo '<p><strong>Log dosyası:</strong> ' . esc_html($log_file) . '</p>';
        echo '<textarea style="width:100%;height:500px;background:#111;color:#0f0;font-family:monospace;">' . esc_textarea(file_get_contents($log_file)) . '</textarea>';
    } else {
        echo '<p>Henüz log oluşturulmamış.</p>';
    }

    echo '<form method="post"><p><input type="submit" name="yazgu_clear_log" class="button button-secondary" value="Logları Temizle"></p></form>';

    if ( isset($_POST['yazgu_clear_log']) ) {
        @unlink($log_file);
        echo '<div class="updated notice"><p>Log dosyası temizlendi.</p></div>';
        yazgu_log('Log dosyası manuel olarak temizlendi.');
    }

    echo '</div>';
}

// --- 6. Yönetici panelinde son kontrol bilgisi --- //
add_action('admin_notices', function() {
    if ( ! current_user_can('manage_options') ) return;
    $last = get_option('yazgu_db_health_last_check');
    echo '<div class="notice notice-success is-dismissible"><p><strong>YAZGU Bakım Sistemi:</strong> Son kontrol: ' . esc_html($last ?: 'Henüz yapılmadı') . '</p></div>';
});

// --- 7. Deaktivasyon temizliği --- //
register_deactivation_hook(__FILE__, function(){
    wp_clear_scheduled_hook('yazgu_db_health_event');
    yazgu_log('Eklenti devre dışı bırakıldı, cron temizlendi.');
});




// --- 8. BuddyBoss Elementor Panel Entegrasyonu --- //
add_action('plugins_loaded', function() {

    if (!function_exists('bp_is_active')) {
        add_shortcode('bb_func', function() {
            return '<p style="color:red;font-weight:bold;">⚠️ BuddyBoss aktif değil veya yüklenmedi.</p>';
        });
        return;
    }

    add_shortcode('bb_func', function($atts) {
        $atts = shortcode_atts(['function' => 'messages'], $atts);
        $func = sanitize_text_field($atts['function']);
        ob_start();

        echo '<div class="bb-shortcode-wrapper">';

        $bp = function_exists('buddypress') ? buddypress() : null;
        if (!$bp) {
            echo '<p style="color:red;">⚠️ BuddyBoss/BuddyPress yüklenmedi.</p>';
            return ob_get_clean();
        }

        switch ($func) {
            case 'messages':
                if (bp_is_active('messages') && function_exists('bp_get_template_part')) {
                    bp_get_template_part('members/single/messages/home');
                } else {
                    echo '<p style="color:red;">⚠️ Mesaj fonksiyonu bulunamadı.</p>';
                }
                break;

            case 'activity':
                if (bp_is_active('activity') && function_exists('bp_activity_screen_index')) {
                    bp_activity_screen_index();
                } else {
                    echo '<p style="color:orange;">📢 Aktivite fonksiyonu bulunamadı.</p>';
                }
                break;

            case 'search':
                if (bp_is_active('search') && function_exists('buddyboss_global_search_form')) {
                    buddyboss_global_search_form();
                } else {
                    echo '<p style="color:orange;">🔎 Arama fonksiyonu bulunamadı.</p>';
                }
                break;

            default:
                echo '<p style="color:gray;">⚙️ Geçersiz fonksiyon adı.</p>';
        }

        echo '</div>';
        $content = ob_get_clean();
        if ($content === null || $content === '') {
            $content = '<p style="color:gray;">(Boş çıktı — şablon yüklenemedi)</p>';
        }
        return $content;
    });

    // Log gösterimi (sadece admin için)
    add_action('wp_footer', function() {
        if (!current_user_can('manage_options')) return;
        echo "<pre style='background:#111;color:#0f0;padding:12px;border-radius:10px;font-size:13px;line-height:1.4em;'>";
        echo "🧩 BuddyBoss Elementor Panel Log\n";
        echo "--------------------------------------\n";
        echo "BuddyBoss aktif mi?: " . (function_exists('bp_is_active') ? "✅ Evet" : "❌ Hayır") . "\n\n";

        if (function_exists('buddypress') && property_exists(buddypress(), 'active_components')) {
            foreach (buddypress()->active_components as $k => $v) {
                echo " - $k: ✅ Aktif\n";
            }
        } else {
            echo "❌ BuddyBoss/BuddyPress yüklenmedi.\n";
        }
        echo "</pre>";
    });

});



// --- 9. PHP 8.2+ kses null uyarısı fix (gelişmiş güvenli sürüm) --- //
if ( ! function_exists('yazgu_kses_safety_filter') ) {

    /**
     * PHP 8.2+ 'preg_replace(): null passed to $subject' uyarılarını güvenli şekilde engeller.
     * wp_kses_no_null()'u override etmeden, sadece içerik filtresini güvenli hale getirir.
     *
     * @since 3.2
     */
    function yazgu_kses_safety_filter( $content ) {
        // Null veya array gelirse boş string döndür
        if ( $content === null || is_array($content) ) {
            return '';
        }

        // String değilse stringe dönüştür
        if ( ! is_string( $content ) ) {
            $content = (string) $content;
        }

        // Güvenlik: boş string değilse ve hala preg_replace hatası riski varsa temizle
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content);

        return $content;
    }

    // Filtreyi en erken aşamada uygula
    add_filter( 'pre_kses', 'yazgu_kses_safety_filter', 0 );

    // Optional: PHP 8.2+ uyarılarını log'dan gizlemek için
    if ( version_compare(PHP_VERSION, '8.2', '>=') ) {
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
    }
}