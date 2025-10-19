<?php
/**
* Plugin Name: YAZGU - Backblaze -> BunnyCDN Aktivite Yöneticisi (Profesyonel)
* Description: BuddyBoss/BuddyPress aktivitelerindeki medya ve metin içeriklerini Backblaze B2'ye yükler, BunnyCDN ile sunar. Gelişmiş hata yönetimi, tekrar-yükleme kontrolü, admin ayarları ve REST API sağlar.
* Version: 3.1.0
* Author: Yazgu
*/


if (!defined('ABSPATH')) exit;


// Autoload AWS SDK, yoksa admin notice
if (file_exists(__DIR__ . '/vendor/autoload.php')) require_once __DIR__ . '/vendor/autoload.php';


use Aws\S3\S3Client;

class Yazgu_Aktivite_Plugin implements ArrayAccess, IteratorAggregate {
private static $instance = null;
private $s3 = null;
private $data = [];

// Varsayılan bucket eşlemeleri
private $default_bucket_mapping = [
'videolar' => ['bucket'=>'yazgu-aktivite-videolar','exts'=>['mp4','webm','mpeg','mov']],
'sesler' => ['bucket'=>'yazgu-aktivite-sesler','exts'=>['mp3','wav','ogg','m4a']],
'resimler' => ['bucket'=>'yazgu-aktivite-resimler','exts'=>['jpg','jpeg','png','webp','gif','svg']],
'belgeler' => ['bucket'=>'yazgu-aktivite-belgeler','exts'=>['pdf','doc','docx','xls','xlsx','ppt','css','js','eot','ttf','woff','woff2']],
'metinler' => ['bucket'=>'yazgu-aktivite-metinler','exts'=>['json']]
];


// Varsayılan CDN CNAME map (admin panelinden değiştirilebilir)
private $default_cdn_domains = [
    'yazgu-aktivite-resimler'=>'https://resimcdn.yazgu.com',
    'yazgu-aktivite-videolar'=>'https://videocdn.yazgu.com',
    'yazgu-aktivite-sesler'=>'https://sescdn.yazgu.com',
    'yazgu-aktivite-belgeler'=>'https://belgecdn.yazgu.com',
    'yazgu-aktivite-metinler'=>'https://metincdn.yazgu.com'
];


private $log_file;


private function __construct() {
$this->log_file = WP_CONTENT_DIR . '/yazgu-aktivite.log';


add_action('plugins_loaded', [$this, 'maybe_load_dependencies']);
add_action('admin_menu', [$this, 'add_admin_menu']);
add_action('admin_init', [$this, 'register_settings']);
add_action('bp_activity_posted_update', [$this, 'handle_activity_post'], 20, 3);
add_action('add_attachment', [$this, 'handle_attachment_s3_meta']);

add_filter('wp_handle_upload', [$this, 'handle_wp_upload'], 20, 1);
add_filter('wp_get_attachment_url', [$this, 'filter_attachment_url'], 10, 2);
add_action('rest_api_init', [$this, 'register_rest_routes']);
}


// ArrayAccess & IteratorAggregate implementasyonu eklendi
public function getIterator(): \ArrayIterator {
    return new \ArrayIterator($this->data);
}

public function offsetExists(mixed $offset): bool {
    return isset($this->data[$offset]);
}

public function offsetGet(mixed $offset): mixed {
    return $this->data[$offset] ?? null;
}

public function offsetSet(mixed $offset, mixed $value): void {
    $this->data[$offset] = $value;
}

public function offsetUnset(mixed $offset): void {
    unset($this->data[$offset]);
}

public static function instance(): self {
    if (!self::$instance) self::$instance = new self();
    return self::$instance;
}



private function log($msg) {
$date = current_time('mysql');
if (is_writable(dirname($this->log_file))) @file_put_contents($this->log_file, "[$date] $msg\n", FILE_APPEND);
}


// Burada admin panel, ayarlar ve sanitize fonksiyonları aynı şekilde eklenir...


private function get_s3_client() {
if ($this->s3) return $this->s3;
$opts = get_option('yazgu_aktivite_options', []);
$key = $opts['backblaze_key'] ?? '';
$secret = $opts['backblaze_secret'] ?? '';
$endpoint = $opts['backblaze_endpoint'] ?? '';




if (!$key || !$secret || !$endpoint) {
$this->log('Backblaze kimlik bilgileri eksik.');
return false;
}




try {
$this->s3 = new S3Client([
'version'=>'latest',
'region'=>'us-east-1',
'endpoint'=>$endpoint,
'use_path_style_endpoint'=>true,
'credentials'=>['key'=>$key,'secret'=>$secret]
]);
return $this->s3;
} catch (\Exception $e) {
$this->log('S3Client oluşturulamadı: ' . $e->getMessage());
return false;
}
}














    /**
     * Composer / AWS SDK kontrolü. Eğer yüklenmemişse admin notice göster.
     */
    public function maybe_load_dependencies() {
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
        if (!class_exists('\Aws\S3\S3Client')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p><strong>YAZGU:</strong> AWS SDK (aws/aws-sdk-php) bulunamadı. Lütfen plugin dizinine composer autoload ekleyin.</p></div>';
            });
            $this->log('AWS SDK bulunamadı. vendor/autoload.php kontrol edin.');
        }
    }

    /* ----------------------------- AYARLAR / ADMIN ---------------------------- */
    public function add_admin_menu() {
        add_options_page('Yazgu Backblaze', 'Yazgu Backblaze', 'manage_options', 'yazgu-backblaze', [ $this, 'render_admin_page' ]);
    }

    public function register_settings() {
        register_setting('yazgu_aktivite_options_group', 'yazgu_aktivite_options', [ $this, 'sanitize_options' ]);

        add_settings_section('yazgu_general_section', 'Genel Ayarlar', null, 'yazgu-backblaze');

        add_settings_field('backblaze_key', 'Backblaze Key', [ $this, 'field_input_text' ], 'yazgu-backblaze', 'yazgu_general_section', [ 'label_for' => 'backblaze_key', 'name' => 'backblaze_key' ]);
        add_settings_field('backblaze_secret', 'Backblaze Secret', [ $this, 'field_input_text' ], 'yazgu-backblaze', 'yazgu_general_section', [ 'label_for' => 'backblaze_secret', 'name' => 'backblaze_secret' ]);
        add_settings_field('backblaze_endpoint', 'Backblaze Endpoint', [ $this, 'field_input_text' ], 'yazgu-backblaze', 'yazgu_general_section', [ 'label_for' => 'backblaze_endpoint', 'name' => 'backblaze_endpoint' ]);
        add_settings_field('cdn_domains', 'CDN CNAMEs (JSON)', [ $this, 'field_textarea_json' ], 'yazgu-backblaze', 'yazgu_general_section', [ 'label_for' => 'cdn_domains', 'name' => 'cdn_domains' ]);
        add_settings_field('bucket_mapping', 'Bucket Mapping (JSON)', [ $this, 'field_textarea_json' ], 'yazgu-backblaze', 'yazgu_general_section', [ 'label_for' => 'bucket_mapping', 'name' => 'bucket_mapping' ]);
    }

    public function sanitize_options($input) {
        $out = [];
        $out['backblaze_key'] = isset($input['backblaze_key']) ? sanitize_text_field($input['backblaze_key']) : '';
        $out['backblaze_secret'] = isset($input['backblaze_secret']) ? sanitize_text_field($input['backblaze_secret']) : '';
        $out['backblaze_endpoint'] = isset($input['backblaze_endpoint']) ? esc_url_raw($input['backblaze_endpoint']) : '';

        // JSON alanlarını doğrula
        $cdn = isset($input['cdn_domains']) ? trim($input['cdn_domains']) : '';
        $map = isset($input['bucket_mapping']) ? trim($input['bucket_mapping']) : '';

        $out['cdn_domains'] = $this->validate_json_map($cdn, $this->default_cdn_domains);
        $out['bucket_mapping'] = $this->validate_json_map($map, $this->default_bucket_mapping);

        return $out;
    }





// YENİ: JSON hatalarını admin notice ile göster ve fallback uygula
private function validate_json_map($json_text, $fallback) {
if (empty($json_text)) return $fallback;
$decoded = json_decode($json_text, true);
if (json_last_error() !== JSON_ERROR_NONE) {
add_action('admin_notices', function() use ($json_text) {
echo '<div class="notice notice-error"><p><strong>YAZGU:</strong> JSON alanında hata var: '. esc_html($json_text) .'</p></div>';
});
$this->log('JSON hatası: ' . json_last_error_msg() . ' -> ' . $json_text);
return $fallback;
}
return $decoded;
}

    public function field_input_text($args) {
        $opts = get_option('yazgu_aktivite_options', []);
        $name = $args['name'];
        $val = isset($opts[$name]) ? esc_attr($opts[$name]) : '';
        printf('<input type="text" id="%s" name="yazgu_aktivite_options[%s]" value="%s" class="regular-text" />', esc_attr($name), esc_attr($name), $val);
    }

    public function field_textarea_json($args) {
        $opts = get_option('yazgu_aktivite_options', []);
        $name = $args['name'];
        $val = isset($opts[$name]) ? $opts[$name] : '';
        if (empty($val)) {
            $val = ($name === 'cdn_domains') ? json_encode($this->default_cdn_domains, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) : json_encode($this->default_bucket_mapping, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        } elseif (is_array($val)) {
            $val = json_encode($val, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        }
        printf('<textarea id="%s" name="yazgu_aktivite_options[%s]" rows="8" cols="80" class="large-text code">%s</textarea>', esc_attr($name), esc_attr($name), esc_textarea($val));
    }

    public function render_admin_page() {
    if (!current_user_can('manage_options')) wp_die('Yetkiniz yok.');
    ?>
    <div class="wrap">
        <h1>Yazgu Backblaze & BunnyCDN Ayarları</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('yazgu_aktivite_options_group');
            do_settings_sections('yazgu-backblaze');
            submit_button();
            ?>
        </form>
        <h2>Kısa Kullanım Notları</h2>
        <ul>
            <li>Backblaze kimlik bilgilerini buraya girin veya sunucu ortam değişkeni / wp-config sabitleri ile sağlayın.</li>
            <li>CDN CNAME eşlemelerini JSON formatında girin. Örnek: <code>{"yazgu-aktivite-resimler":"https://resimler.example.com"}</code></li>
            <li>Bucket mapping JSON'u varsayılan yapıyı takip eder. Burada bucket adlarını değiştirebilirsiniz.</li>
        </ul>
    </div>
    <?php
}


    /* ----------------------------- YARDIMCI FONKSİYONLAR ---------------------------- */
private function pick_bucket_by_ext($ext) {
$ext = strtolower(trim($ext));
if (!$ext) return null;
if (empty($ext)) return null;


$opts = get_option('yazgu_aktivite_options', []);
$mapping = isset($opts['bucket_mapping']) && is_array($opts['bucket_mapping']) ? $opts['bucket_mapping'] : $this->default_bucket_mapping;


foreach ($mapping as $pair) {
if (isset($pair['bucket'], $pair['exts']) && is_array($pair['exts'])) {
if (in_array($ext, $pair['exts'], true)) return $pair['bucket'];
}
}


return null;
}
    
    
    
    
    

    private function generate_cdn_url($bucket, $key) {
        $opts = get_option('yazgu_aktivite_options', []);
        $cdn_map = isset($opts['cdn_domains']) && is_array($opts['cdn_domains']) ? $opts['cdn_domains'] : $this->default_cdn_domains;

        if (!isset($cdn_map[$bucket])) return false;
        return rtrim($cdn_map[$bucket], '/') . '/' . ltrim($key, '/');
    }

    private function build_object_key($basename, $subpath = '') {
        $basename = sanitize_file_name($basename);
        $subpath = trim($subpath, '/');
        return ($subpath ? ($subpath . '/') : '') . $basename;
    }

    /* ----------------------------- AKTİVİTE YÜKLEME ---------------------------- */
    public function handle_activity_post($content, $user_id, $activity_id) {
        // asenkron işleme veya manuel tetikleme yapılmadan, doğrudan yüklemeyi dene
        try {
            $this->upload_activity($content, $user_id, $activity_id);
        } catch (\Exception $e) {
            $this->log('handle_activity_post hata: ' . $e->getMessage());
        }
    }




private function s3_object_exists($bucket, $key) {
$s3 = $this->get_s3_client();
try {
$s3->headObject(['Bucket'=>$bucket,'Key'=>$key]);
return true;
} catch (\Aws\S3\Exception\S3Exception $e) {
return false;
}
}




// upload_activity fonksiyonu Backblaze üzerinde zaten var mı kontrol eklenmiş hali
private function upload_activity($content, $user_id, $activity_id) {
    $s3 = $this->get_s3_client();
    if (!$s3) {
        $this->log("[DEBUG] S3 client alınamadı");
        return [];
    }

    if ($this->is_activity_processed($activity_id)) {
        $this->log("[DEBUG] Activity $activity_id daha önce işlendi, atlanıyor.");
        return [];
    }

    if (!$this->acquire_activity_lock($activity_id)) {
        $this->log("[DEBUG] Activity $activity_id için kilit alınamadı, atlanıyor.");
        return [];
    }

    $uploaded = [];
    $did_anything = false;

    try {
        $upload_dir = wp_upload_dir()['basedir'] . '/backblazeye-aktarilacak';
        if (!file_exists($upload_dir)) wp_mkdir_p($upload_dir);

        $username = bp_core_get_username($user_id) ?: 'user-' . $user_id;
        $timestamp = current_time('Y-m-d-H-i-s');
        $year = date_i18n('Y');
        $month = date_i18n('m');

        $this->log("[DEBUG] Başlangıç upload_activity: activity_id=$activity_id, username=$username");

        // JSON yükleme
        $existing_json = function_exists('bp_activity_get_meta')
            ? bp_activity_get_meta($activity_id, '_yazgu_json_key', true)
            : get_option('yazgu_activity_json_' . $activity_id, '');

        if (empty($existing_json)) {
            $json_basename = $username . '-' . $timestamp . '.json';
            $json_subpath = "$username/$year/$month";
            $json_key = $this->build_object_key($json_basename, $json_subpath);

            $tmp_json_file = $upload_dir . '/' . $json_basename;
            $json_data = wp_json_encode([
                'user_id'     => $user_id,
                'username'    => $username,
                'activity_id' => $activity_id,
                'content'     => $content,
                'timestamp'   => $timestamp
            ], JSON_UNESCAPED_UNICODE);

            if (file_put_contents($tmp_json_file, $json_data) !== false) {
                try {
                    // Backblaze üzerinde zaten var mı kontrol
                    try {
                        $s3->headObject([
                            'Bucket' => 'yazgu-aktivite-metinler',
                            'Key'    => $json_key
                        ]);
                        $this->log("JSON zaten Backblaze üzerinde mevcut: $json_key");
                    } catch (\Aws\S3\Exception\S3Exception $e) {
                        // Yoksa yükle
                        $s3->putObject([
                            'Bucket'     => 'yazgu-aktivite-metinler',
                            'Key'        => $json_key,
                            'SourceFile' => $tmp_json_file
                        ]);
                        $this->log("JSON yüklendi: $json_key");
                        $did_anything = true;
                        $uploaded[] = $json_key;

                        if (function_exists('bp_activity_update_meta')) {
                            bp_activity_update_meta($activity_id, '_yazgu_json_key', $json_key);
                        } else {
                            update_option('yazgu_activity_json_' . $activity_id, $json_key);
                        }
                    }
                } catch (\Exception $e) {
                    $this->log("[DEBUG] JSON yükleme hatası: " . $e->getMessage());
                }

                unlink($tmp_json_file);
            }
        } else {
            $this->log("[DEBUG] JSON zaten var: $existing_json");
        }

        if ($did_anything) {
            $this->mark_activity_processed($activity_id);
            $this->log("[DEBUG] Activity $activity_id işaretlendi (processed).");
        } else {
            $this->log("[DEBUG] Activity $activity_id için değişiklik yapılmadı (did_anything=false).");
        }
    } catch (\Exception $e) {
        $this->log("[DEBUG] upload_activity genel hata: " . $e->getMessage());
    } finally {
        $this->release_activity_lock($activity_id);
        $this->log("[DEBUG] Kilit serbest bırakıldı: activity_id=$activity_id");
    }

    return $uploaded;
}


    /* ----------------------------- YENİ FONKSİYONLAR: ACTIVITY LOCK & PROCESS ---------------------------- */


private function save_activity_media_meta($activity_id, $uploaded_array) {
    if (empty($uploaded_array) || !is_array($uploaded_array)) {
        return;
    }

    // Mevcut kaydı al
    $existing = [];
    if (function_exists('bp_activity_get_meta')) {
        $existing = bp_activity_get_meta($activity_id, 'yazgu_uploaded_media', true);
        if (!is_array($existing)) $existing = [];
    } else {
        $existing = get_option('yazgu_activity_media_' . intval($activity_id), []);
        if (!is_array($existing)) $existing = [];
    }

    // Array deduplication map oluştur
    $map = [];
    foreach ($existing as $item) {
        if (!empty($item['bucket']) && !empty($item['key'])) {
            $map[$item['bucket'] . '|' . $item['key']] = $item;
        }
    }

    foreach ($uploaded_array as $item) {
        if (!empty($item['bucket']) && !empty($item['key'])) {
            // Zaten eklenmişse atla
            $map[$item['bucket'] . '|' . $item['key']] = $item;
        }
    }

    $merged = array_values($map);

    if (function_exists('bp_activity_update_meta')) {
        bp_activity_update_meta($activity_id, 'yazgu_uploaded_media', $merged);
    } else {
        update_option('yazgu_activity_media_' . intval($activity_id), $merged);
    }
}





private function acquire_activity_lock($activity_id) {
    $transient = 'yazgu_activity_lock_' . intval($activity_id);

    // Eğer transient varsa başka işlem yapılıyor demektir
    if (get_transient($transient)) {
        $this->log("Kilit zaten mevcut (transient) for activity $activity_id");
        return false;
    }

    // Eğer BP meta varsa kontrol et
    if (function_exists('bp_activity_get_meta')) {
        $proc = bp_activity_get_meta($activity_id, '_yazgu_processing', true);
        if (!empty($proc)) {
            $this->log("Kilit zaten mevcut (meta) for activity $activity_id");
            return false;
        }
        if (function_exists('bp_activity_update_meta')) {
            bp_activity_update_meta($activity_id, '_yazgu_processing', time());
        } else {
            update_option('yazgu_activity_processing_' . $activity_id, time());
        }
    } else {
        // fallback: option
        if (get_option('yazgu_activity_processing_' . $activity_id)) {
            $this->log("Kilit zaten mevcut (option) for activity $activity_id");
            return false;
        }
        update_option('yazgu_activity_processing_' . $activity_id, time());
    }

    // Kısa ömürlü transient (aynı anda paralel çalışanları engeller)
    set_transient($transient, 1, 60);
    return true;
}

private function release_activity_lock($activity_id) {
    $transient = 'yazgu_activity_lock_' . intval($activity_id);
    delete_transient($transient);

    if (function_exists('bp_activity_get_meta') && function_exists('bp_activity_delete_meta')) {
        bp_activity_delete_meta($activity_id, '_yazgu_processing');
    } else {
        delete_option('yazgu_activity_processing_' . $activity_id);
    }
}

private function is_activity_processed($activity_id) {
    if (function_exists('bp_activity_get_meta')) {
        $p = bp_activity_get_meta($activity_id, '_yazgu_processed', true);
        return !empty($p);
    }
    return (bool) get_option('yazgu_activity_processed_' . $activity_id, false);
}

private function mark_activity_processed($activity_id) {
    if (function_exists('bp_activity_update_meta')) {
        bp_activity_update_meta($activity_id, '_yazgu_processed', 1);
    } else {
        update_option('yazgu_activity_processed_' . $activity_id, 1);
    }
}


    /* ----------------------------- WP UPLOAD (Dokümanlar) ---------------------------- */


   
public function handle_wp_upload($upload) {
    $file_path = $upload['file'] ?? '';
    if (!$file_path || !file_exists($file_path)) return $upload;

    $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $bucket = $this->pick_bucket_by_ext($ext);
    if (!$bucket) return $upload;

    $s3 = $this->get_s3_client();
    if (!$s3) { 
        $this->log('Backblaze client alınamadı (wp_upload).'); 
        return $upload; 
    }

    $username   = wp_get_current_user()->user_login ?: 'user-' . get_current_user_id();
    $timestamp  = current_time('Y-m-d-H-i-s');
    $year       = date_i18n('Y');
    $month      = date_i18n('m');

    $basename = $username . '-' . $timestamp . '.' . $ext;
    $subpath  = $username . '/' . $year . '/' . $month;
    $key      = $this->build_object_key($basename, $subpath);

    try {
        $s3->putObject([
            'Bucket'     => $bucket,
            'Key'        => $key,
            'SourceFile' => $file_path
        ]);

        $post_id = $upload['id'] ?? 0;
        if ($post_id) {
            update_post_meta($post_id, '_yazgu_s3_bucket', $bucket);
            update_post_meta($post_id, '_yazgu_s3_key', $key);
        }

        $cdn_url = $this->generate_cdn_url($bucket, $key);
        if ($cdn_url) $upload['url'] = $cdn_url;

    } catch (\Exception $e) {
        $this->log('WP Upload yükleme hatası: ' . $e->getMessage());
    }

    return $upload;
}




    /* ----------------------------- ATTACHMENT URL FİLTRESİ ---------------------------- */
    
public function handle_attachment_s3_meta($post_ID) {
    $file_path = get_attached_file($post_ID);
    if (!$file_path || !file_exists($file_path)) return;

    $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $bucket = $this->pick_bucket_by_ext($ext);
    if (!$bucket) return;

    $s3 = $this->get_s3_client();
    if (!$s3) return;

    $user = get_userdata(get_post_field('post_author', $post_ID));
    $username   = $user ? $user->user_login : 'user-' . get_post_field('post_author', $post_ID);
    $timestamp  = current_time('Y-m-d-H-i-s');
    $year       = date_i18n('Y');
    $month      = date_i18n('m');

    $basename = $username . '-' . $timestamp . '.' . $ext;
    $subpath  = $username . '/' . $year . '/' . $month;
    $key      = $this->build_object_key($basename, $subpath);

    try {
        $s3->putObject([
            'Bucket'     => $bucket,
            'Key'        => $key,
            'SourceFile' => $file_path
        ]);

        update_post_meta($post_ID, '_yazgu_s3_bucket', $bucket);
        update_post_meta($post_ID, '_yazgu_s3_key', $key);

    } catch (\Exception $e) {
        $this->log('Attachment S3 yükleme hatası: ' . $e->getMessage());
    }
}



    
    
    
    public function filter_attachment_url($url, $post_id = 0) {
        if (!$post_id) return $url;

        // Öncelikle post meta'dan _yazgu_s3_key / _yazgu_s3_bucket oku
        $s3_key = get_post_meta($post_id, '_yazgu_s3_key', true);
        $s3_bucket = get_post_meta($post_id, '_yazgu_s3_bucket', true);
        if ($s3_key && $s3_bucket) {
            $cdn = $this->generate_cdn_url($s3_bucket, $s3_key);
            if ($cdn) return $cdn;
        }

        // Eğer meta yoksa mevcut davranışı bozma
        return $url;
    }

    /* ----------------------------- REST API ---------------------------- */
    public function register_rest_routes() {
        register_rest_route('yazgu/v1', '/activity-media/(?P<id>\\d+)', [
            'methods' => 'GET',
            'callback' => [ $this, 'rest_get_activity_media' ],
            'permission_callback' => function() { return is_user_logged_in(); }
        ]);

        register_rest_route('yazgu/v1', '/recent-media', [
            'methods' => 'GET',
            'callback' => [ $this, 'rest_get_recent_media' ],
            'permission_callback' => function() { return current_user_can('read'); }
        ]);
    }

    public function rest_get_activity_media($request) {
        $activity_id = intval($request['id']);
        if (function_exists('bp_activity_get_meta')) {
            $data = bp_activity_get_meta($activity_id, 'yazgu_uploaded_media', true);
        } else {
            $data = get_option('yazgu_activity_media_' . $activity_id, []);
        }
        if (empty($data)) return rest_ensure_response([]);
        return rest_ensure_response($data);
    }

    public function rest_get_recent_media($request) {
        // Basit örnek: son 50 aktivite için option/bp meta oku (uygulamaya göre optimize edilmelidir)
        $results = [];
        global $wpdb;

        // Eğer BuddyPress varsa aktivite tablosundan al
        if ( $wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($wpdb->prefix . "bp_activity") . "'") ) {
            $table = $wpdb->prefix . 'bp_activity';
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id FROM {$table} ORDER BY date_recorded DESC LIMIT %d", 50));
            if ($rows) {
                foreach ($rows as $r) {
                    $id = intval($r->id);
                    if (function_exists('bp_activity_get_meta')) {
                        $m = bp_activity_get_meta($id, 'yazgu_uploaded_media', true);
                    } else {
                        $m = get_option('yazgu_activity_media_' . $id, []);
                    }
                    if (!empty($m)) {
                        $results[$id] = $m;
                    }
                }
            }
        }

        return rest_ensure_response($results);
    }

}



/* ----------------------------- CDN URL REWRITE (BuddyBoss + WP) ---------------------------- */
function yazgu_cdn_rewrite_in_content_advanced($content) {
    if (empty($content)) return $content;

    $opts = get_option('yazgu_aktivite_options', []);
    $cdn_map = $opts['cdn_domains'] ?? [
        'yazgu-aktivite-resimler' => 'https://resimcdn.yazgu.com',
        'yazgu-aktivite-videolar' => 'https://videocdn.yazgu.com',
        'yazgu-aktivite-sesler' => 'https://sescdn.yazgu.com',
        'yazgu-aktivite-belgeler' => 'https://belgecdn.yazgu.com',
        'yazgu-aktivite-metinler' => 'https://metincdn.yazgu.com',
    ];

    $bucket_map = $opts['bucket_mapping'] ?? [
        'resimler' => ['bucket'=>'yazgu-aktivite-resimler','exts'=>['jpg','jpeg','png','webp','gif','svg']],
        'videolar' => ['bucket'=>'yazgu-aktivite-videolar','exts'=>['mp4','webm','mpeg','mov']],
        'sesler'   => ['bucket'=>'yazgu-aktivite-sesler','exts'=>['mp3','wav','ogg','m4a']],
        'belgeler' => ['bucket'=>'yazgu-aktivite-belgeler','exts'=>['pdf','doc','docx','xls','xlsx','ppt','css','js','eot','ttf','woff','woff2']],
        'metinler' => ['bucket'=>'yazgu-aktivite-metinler','exts'=>['json']]
    ];

    // Regex ile Backblaze URL’lerini yakala
    $pattern = '#https://f\d+\.backblazeb2\.com/file/(yazgu-aktivite-[^/]+)/(.*?\.(\w+))#i';
    return preg_replace_callback($pattern, function($matches) use ($cdn_map, $bucket_map) {
        $bucket_name = $matches[1]; // yazgu-aktivite-resimler gibi
        $file_path = $matches[2];   // musab/2025/09/filename.jpg
        $ext = strtolower($matches[3]);

        // Bucket tipini bul
        foreach ($bucket_map as $type => $info) {
            if ($info['bucket'] === $bucket_name && in_array($ext, $info['exts'], true)) {
                $cdn = $cdn_map[$bucket_name] ?? null;
                if ($cdn) return rtrim($cdn, '/') . '/' . $file_path;
            }
        }
        // Eşleşmezse orijinal URL'i bırak
        return $matches[0];
    }, $content);
}

// Farklı içerik filtrelerine uygula
add_filter('the_content', 'yazgu_cdn_rewrite_in_content_advanced');
add_filter('post_thumbnail_html', 'yazgu_cdn_rewrite_in_content_advanced');
add_filter('bp_get_activity_content_body', 'yazgu_cdn_rewrite_in_content_advanced');
add_filter('bp_get_activity_content', 'yazgu_cdn_rewrite_in_content_advanced');
add_filter('bp_get_media_attachment', 'yazgu_cdn_rewrite_in_content_advanced');
add_filter('bp_attachments_get_attachment_url', 'yazgu_cdn_rewrite_in_content_advanced');


Yazgu_Aktivite_Plugin::instance();


register_activation_hook(__FILE__, function(){
$defaults = [
'cdn_domains' => null,
'bucket_mapping' => null,
'backblaze_key' => '',
'backblaze_secret' => '',
'backblaze_endpoint' => ''
];
add_option('yazgu_aktivite_options', $defaults);
});

