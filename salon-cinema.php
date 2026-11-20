<?php
/**
 * Plugin Name: online movie
 * Plugin URI: https://alilashkari.ir/
 * Description: تماشای همزمان فیلم با دوستان — اتاق، چت، همگام‌سازی پخش و زیرنویس + مدیریت کتابخانه‌ی فیلم از پیشخوان. شورت‌کدها: [salon] و [salon_upload]
 * Version: 1.5.1
 * Author: Ali Lashkari
 * Author URI: https://alilashkari.ir/
 * Text Domain: salon-cinema
 *
 * توسعه‌دهنده: Ali Lashkari (علی لشکری)
 * ایمیل: alilashkari020@gmail.com
 * وب‌سایت: https://alilashkari.ir/
 *
 * Copyright (c) 2026 Ali Lashkari. All Rights Reserved. See LICENSE.
 * کپی، ویرایش، توزیع یا فروش بدون اجازه‌ی کتبی نویسنده مجاز نیست.
 */

if (!defined('ABSPATH')) exit;

define('SALON_VERSION', '1.5.1');

// اطلاعات توسعه‌دهنده (برای تغییر، همین نام‌ها را در wp-config.php تعریف کن)
if (!defined('SALON_DEV_NAME'))    define('SALON_DEV_NAME', 'Ali Lashkari');
if (!defined('SALON_DEV_EMAIL'))   define('SALON_DEV_EMAIL', 'alilashkari020@gmail.com');
if (!defined('SALON_DEV_WEBSITE')) define('SALON_DEV_WEBSITE', 'alilashkari.ir');
define('SALON_FILE', __FILE__);
define('SALON_DIR', plugin_dir_path(__FILE__));
define('SALON_URL', plugin_dir_url(__FILE__));

require_once SALON_DIR . 'includes/media.php';

/* ============================================================
 *  فعال‌سازی: ساخت جدول اتاق‌ها + کلید امضا
 * ============================================================ */
register_activation_hook(__FILE__, function () {
    global $wpdb;
    $table = $wpdb->prefix . 'salon_rooms';
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE $table (
        room VARCHAR(12) NOT NULL,
        data LONGTEXT NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (room)
    ) $charset;");

    if (!get_option('salon_sign_key')) {
        update_option('salon_sign_key', wp_generate_password(40, false));
    }
    if (get_option('salon_films_dir') === false) {
        $u = wp_upload_dir();
        update_option('salon_films_dir', $u['basedir'] . '/salon-films');
    }
    if (get_option('salon_upload_roles') === false) {
        add_option('salon_upload_roles', []);
    }
    salon_media_ensure_dir();
});

/* ============================================================
 *  کمکی‌ها: امضا، آدرس عمومی فایل محلی، اعتبارسنجی آدرس بیرونی
 * ============================================================ */
function salon_sign(string $target, int $exp): string {
    return substr(hash_hmac('sha256', $target . '|' . $exp, get_option('salon_sign_key')), 0, 32);
}

// بررسی می‌کند IP عمومی و غیرمحلی باشد
function salon_is_public_ip(string $ip): bool {
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

// جلوی SSRF و آدرس‌های شبکه‌ی داخلی (IPv4/IPv6/DNS/ریدایرکت) را می‌گیرد
function salon_safe_target(?string $raw): ?string {
    if (!$raw) return null;
    $raw = trim($raw);
    $u = @parse_url($raw);
    if (!$u || empty($u['scheme']) || empty($u['host'])) return null;
    $scheme = strtolower($u['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) return null;
    if (!empty($u['user']) || !empty($u['pass'])) return null;
    if (!empty($u['port'])) {
        $port = (int) $u['port'];
        if ($port !== 80 && $port !== 443 && $port < 1024) return null;
    }
    $h = strtolower(trim($u['host'], '[]'));
    if ($h === '' || $h === 'localhost' || str_ends_with($h, '.localhost') || str_ends_with($h, '.local') || str_ends_with($h, '.internal')) {
        return null;
    }

    // اگر خود میزبان IP است (IPv4 یا IPv6)
    if (filter_var($h, FILTER_VALIDATE_IP)) {
        return salon_is_public_ip($h) ? $raw : null;
    }
    // جلوگیری از IPهای عددی/هگز/اکتال مثل 2130706433 یا 0x7f000001
    if (preg_match('/^(0x[0-9a-f]+|\d+)$/i', $h)) return null;

    // بررسی DNS واقعی تا دامنه به IP داخلی اشاره نکند
    $ips = @gethostbynamel($h);
    if (is_array($ips) && $ips) {
        foreach ($ips as $ip) {
            if (!salon_is_public_ip($ip)) return null;
        }
    } else {
        return null;
    }
    return $raw;
}

// مسیر فایل محلی زیر پوشه‌ی فیلم‌ها را به آدرس عمومی سایت تبدیل می‌کند
function salon_local_url(string $abs_path): ?string {
    // هم مسیر خام و هم realpath را امتحان می‌کنیم (روی بعضی هاست‌ها public_html سیم‌لینک است)
    $paths = [wp_normalize_path($abs_path)];
    $rp = realpath($abs_path);
    if ($rp) $paths[] = wp_normalize_path($rp);

    $u = wp_upload_dir();
    if (!empty($u['basedir']) && !empty($u['baseurl'])) {
        $ubases = [wp_normalize_path($u['basedir'])];
        $urb = realpath($u['basedir']);
        if ($urb) $ubases[] = wp_normalize_path($urb);
        foreach (array_unique($ubases) as $ubase) {
            $ubase = trailingslashit($ubase);
            foreach (array_unique($paths) as $p) {
                if (strpos($p, $ubase) === 0) {
                    $rel = substr($p, strlen($ubase));
                    $enc = implode('/', array_map('rawurlencode', explode('/', $rel)));
                    return trailingslashit($u['baseurl']) . $enc;
                }
            }
        }
    }

    $roots = [wp_normalize_path(ABSPATH)];
    $rr = realpath(ABSPATH);
    if ($rr) $roots[] = wp_normalize_path($rr);

    foreach (array_unique($roots) as $root) {
        $root = trailingslashit($root);
        foreach (array_unique($paths) as $p) {
            if (strpos($p, $root) === 0) {
                $rel = substr($p, strlen($root));
                // نام فایل‌های فارسی/با فاصله/پرانتز باید URL-encode شوند
                $enc = implode('/', array_map('rawurlencode', explode('/', $rel)));
                return site_url('/' . $enc);
            }
        }
    }
    return null;
}

function salon_local_stream_url(string $rel): string {
    $exp = (int) (microtime(true) * 1000) + 24 * 3600 * 1000;
    $sig = salon_sign('local:' . $rel, $exp);
    return add_query_arg([
        'salon_local' => 1,
        'f'           => $rel,
        'e'           => $exp,
        's'           => $sig,
    ], home_url('/'));
}

// تبدیل مسیر عمومی/نسبی که کلاینت فرستاده به مسیر واقعی فایل زیر پوشه‌ی فیلم‌ها (برای جلوگیری از خروج از پوشه)
function salon_resolve_local(string $relative): ?string {
    $dir = function_exists('salon_media_dir') ? salon_media_dir() : get_option('salon_films_dir');
    $real_dir = $dir ? realpath($dir) : false;
    if (!$real_dir) return null;
    $candidate = realpath($real_dir . '/' . ltrim($relative, '/\\'));
    if (!$candidate || !is_file($candidate)) return null;
    $prefix = rtrim(wp_normalize_path($real_dir), '/') . '/';
    if (strpos(wp_normalize_path($candidate), $prefix) !== 0) return null;
    return $candidate;
}

$GLOBALS['salon_video_ext'] = ['mp4', 'm4v', 'webm'];
// فرمت‌هایی که مرورگر پخش نمی‌کند ولی در لیست نشان داده می‌شوند (با برچسب «نیاز به آماده‌سازی»)
$GLOBALS['salon_convert_ext'] = ['mkv', 'avi', 'mov', 'ts'];
$GLOBALS['salon_lang_label'] = [
    'fa' => 'فارسی', 'en' => 'English', 'ar' => 'العربية', 'fr' => 'Français',
    'de' => 'Deutsch', 'es' => 'Español', 'tr' => 'Türkçe', 'ru' => 'Русский',
    'ja' => '日本語', 'ko' => '한국어', 'zh' => '中文', 'hi' => 'हिन्दी',
    'it' => 'Italiano', 'xx' => 'نامشخص',
];

function salon_glob_escape(string $s): string {
    return preg_replace('/[*?\[\]]/', '[$0]', $s);
}

// مثل salon-prep.php: پوشه‌ی فیلم‌ها را بازگشتی می‌خواند. id هر فیلم مسیر نسبی آن است.
function salon_scan_films(): array {
    $dir = function_exists('salon_media_dir') ? salon_media_dir() : get_option('salon_films_dir');
    if (!$dir || !is_dir($dir)) return [];
    $base_dir = untrailingslashit(wp_normalize_path($dir));
    $out = [];
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
    } catch (Exception $e) {
        return [];
    }
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $path = $f->getPathname();
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $playable = in_array($ext, $GLOBALS['salon_video_ext'], true);
        if (!$playable && !in_array($ext, $GLOBALS['salon_convert_ext'], true)) continue;
        // پارت‌های نیمه‌کاره‌ی salon-prep هیچ‌وقت نمایش داده نمی‌شوند
        if (substr($path, -5) === '.part') continue;

        // mkv و امثالش: اگر نسخه‌ی mp4 کنارش هست، همان mp4 در لیست می‌آید و این ردیف حذف می‌شود
        if (!$playable) {
            $b = preg_replace('/\.[^.\/]+$/', '', $path);
            if (is_file($b . '.mp4') || is_file($b . '.m4v') || is_file($b . '.webm')) continue;
        }

        $rel = ltrim(substr(wp_normalize_path($path), strlen($base_dir)), '/');
        $url = salon_local_url($path) ?: salon_local_stream_url($rel);
        $base = preg_replace('/\.[^.\/]+$/', '', $path);

        $subs = [];
        foreach (glob(salon_glob_escape($base) . '.*.vtt') ?: [] as $v) {
            if (!preg_match('/\.([a-z]{2}\d?)\.vtt$/i', $v, $m)) continue;
            $code = strtolower($m[1]);
            $short = preg_replace('/\d$/', '', $code);
            $sub_rel = ltrim(substr(wp_normalize_path($v), strlen($base_dir)), '/');
            $sub_url = salon_local_url($v) ?: salon_local_stream_url($sub_rel);
            $subs[] = [
                'lang'  => $code,
                'label' => $GLOBALS['salon_lang_label'][$short] ?? $short,
                'url'   => $sub_url,
                'file'  => wp_basename($v),
            ];
        }

        $out[] = [
            'id'    => $rel,
            'label' => wp_basename($base),
            'url'   => $url,
            'subs'  => $subs,
            'ready' => $playable,
            'ext'   => $ext,
        ];
    }
    usort($out, fn($a, $b) => strcasecmp($a['label'], $b['label']));
    return $out;
}

/* ============================================================
 *  دیتابیس اتاق‌ها
 * ============================================================ */
function salon_room_key_ok(string $room): bool {
    return (bool) preg_match('/^[A-Z0-9]{4,12}$/', $room);
}

function salon_default_state(): array {
    return [
        'source'  => null,   // {type:'local'|'external', url, label}
        'paused'  => true,
        'time'    => 0,
        'at'      => (int) (microtime(true) * 1000),
        'lock'    => false,
        'sub'     => null,   // {lang,label,url,delay}
        'viewers' => [],     // id => {name, joined, last_seen, token}
        'chat'    => [],     // [{id,text,name,from,at}]
        'banned'  => [],     // توکن مرورگرِ کاربرانی که کیک شده‌اند
        'kicked'  => [],     // شناسه‌ی کاربرانی که کیک شده‌اند (تا کلاینتشان بفهمد)
        'moved_to' => null,  // اگر کد اتاق عوض شده: کد جدید (این ردیف فقط یک تابلوی راهنماست)
        'moved_ok' => [],    // فقط این شناسه‌ها اجازه دارند دنبال کد جدید بروند
    ];
}

// هر درخواست «بخوان ← تغییر بده ← ذخیره کن» را برای همان اتاق پشت یک قفل MySQL می‌گذارد؛
// وگرنه poll هر ۱٫۲ ثانیه‌ی بقیه می‌تواند انتخاب فیلم/پخش/پاز را با وضعیت قدیمی بازنویسی کند.
function salon_locked(callable $fn): Closure {
    return function (WP_REST_Request $req) use ($fn) {
        global $wpdb;
        $room = strtoupper((string) $req->get_param('room'));
        $lock_name = substr('salon_' . $wpdb->prefix . $room, 0, 64);
        $locked = false;
        if (salon_room_key_ok($room)) {
            $locked = (bool) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock_name));
        }
        try {
            return $fn($req);
        } finally {
            if ($locked) $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    };
}

function salon_get_room(string $room): array {
    global $wpdb;
    $table = $wpdb->prefix . 'salon_rooms';
    $row = $wpdb->get_row($wpdb->prepare("SELECT data FROM $table WHERE room = %s", $room));
    if (!$row) return salon_default_state();
    $data = json_decode($row->data, true);
    return is_array($data) ? array_merge(salon_default_state(), $data) : salon_default_state();
}

function salon_save_room(string $room, array $state): void {
    global $wpdb;
    $table = $wpdb->prefix . 'salon_rooms';
    $wpdb->query($wpdb->prepare(
        "INSERT INTO $table (room, data, updated_at) VALUES (%s, %s, NOW())
         ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = NOW()",
        $room, wp_json_encode($state)
    ));
}

// حریم فعال (۳۰ ثانیه بی‌خبری = رفته) و محاسبه‌ی میزبان از روی زودترین ورود فعال
function salon_prune_and_host(array &$state): ?string {
    $now = (int) (microtime(true) * 1000);
    foreach ($state['viewers'] as $id => $v) {
        if ($now - (int) $v['last_seen'] > 30000) unset($state['viewers'][$id]);
    }
    if (!$state['viewers']) return null;
    $hostId = null; $earliest = PHP_INT_MAX;
    foreach ($state['viewers'] as $id => $v) {
        if ((int) $v['joined'] < $earliest) { $earliest = (int) $v['joined']; $hostId = $id; }
    }
    return $hostId;
}

function salon_head_time(array $state): float {
    if ($state['paused']) return (float) $state['time'];
    $now = (int) (microtime(true) * 1000);
    return (float) $state['time'] + ($now - (int) $state['at']) / 1000;
}

// حداکثر ۱۰ دقیقه به هر طرف
function salon_clamp_delay($n): float {
    $n = round(((float) $n) * 10) / 10;
    return max(-600, min(600, $n));
}

function salon_play_url(array $state): ?string {
    $src = $state['source'] ?? null;
    if (!$src) return null;
    if ($src['type'] === 'local') {
        if (!empty($src['url'])) return $src['url'];
        return !empty($src['id']) ? salon_local_stream_url((string) $src['id']) : null;
    }
    $exp = (int) (microtime(true) * 1000) + 24 * 3600 * 1000;
    $sig = salon_sign($src['url'], $exp);
    return add_query_arg([
        'salon_stream' => 1, 'u' => $src['url'], 'e' => $exp, 's' => $sig,
    ], home_url('/'));
}

function salon_play_fallback_url(array $state): ?string {
    $src = $state['source'] ?? null;
    if (!$src || ($src['type'] ?? '') !== 'local') return null;
    if (empty($src['id'])) return null;
    return salon_local_stream_url((string) $src['id']);
}

/* ============================================================
 *  پروکسی پخش و زیرنویس برای لینک‌های بیرونی
 *  (زودتر از هر چیز دیگری در init اجرا می‌شود تا سربار وردپرس رد نشود)
 * ============================================================ */
add_action('init', 'salon_maybe_proxy', 0);

function salon_upstream_headers(string $target, ?string $range, bool $with_referer = false): array {
    $h = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        'Accept' => '*/*',
        'Accept-Encoding' => 'identity',
    ];
    if ($with_referer) {
        $h['Referer'] = (parse_url($target, PHP_URL_SCHEME) ?: 'https') . '://' . parse_url($target, PHP_URL_HOST) . '/';
    }
    if ($range) $h['Range'] = $range;
    return $h;
}

const SALON_TYPES = [
    'mkv' => 'video/x-matroska', 'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'mov' => 'video/quicktime',
    'webm' => 'video/webm', 'avi' => 'video/x-msvideo', 'ogv' => 'video/ogg', 'ts' => 'video/mp2t',
    'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'flac' => 'audio/flac',
];

function salon_ext_of(string $url): string {
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $parts = explode('.', basename($path));
    return strtolower(end($parts));
}

function salon_maybe_proxy(): void {
    if (isset($_GET['salon_local']))  { salon_local_stream_proxy(); exit; }
    if (isset($_GET['salon_extsub'])) { salon_extsub_serve(); exit; }
    if (isset($_GET['salon_stream'])) { salon_stream_proxy(); exit; }
    if (isset($_GET['salon_sub']))    { salon_sub_proxy(); exit; }
    if (isset($_GET['salon_probe']))  { salon_probe_endpoint(); exit; }
}

function salon_extsub_dir(): string {
    $d = salon_media_dir() . '/.ext-subs';
    if (!is_dir($d)) {
        wp_mkdir_p($d);
        if (is_dir($d) && !file_exists($d . '/index.html')) @file_put_contents($d . '/index.html', '');
    }
    return $d;
}

function salon_extsub_url(string $file_name): string {
    $exp = (int) (microtime(true) * 1000) + 24 * 3600 * 1000;
    $sig = salon_sign('extsub:' . $file_name, $exp);
    return add_query_arg(['salon_extsub' => 1, 'k' => $file_name, 'e' => $exp, 's' => $sig], home_url('/'));
}

function salon_extsub_serve(): void {
    $exp = (int) ($_GET['e'] ?? 0);
    $sig = wp_unslash((string) ($_GET['s'] ?? ''));
    $key = wp_basename(wp_unslash((string) ($_GET['k'] ?? '')));
    if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]+\.vtt$/i', $key) || !$exp || (microtime(true) * 1000) > $exp) {
        http_response_code(403); echo 'forbidden'; return;
    }
    if (!hash_equals(salon_sign('extsub:' . $key, $exp), $sig)) {
        http_response_code(403); echo 'bad signature'; return;
    }
    $path = salon_extsub_dir() . '/' . $key;
    if (!is_file($path) || !is_readable($path)) { http_response_code(404); echo 'not found'; return; }
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: text/vtt; charset=utf-8');
    readfile($path);
}

function salon_local_stream_proxy(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: range');
        return;
    }
    $exp = (int) ($_GET['e'] ?? 0);
    $sig = (string) ($_GET['s'] ?? '');
    $rel = wp_unslash((string) ($_GET['f'] ?? ''));

    if ($rel === '' || !$exp || (microtime(true) * 1000) > $exp) { http_response_code(403); echo 'link expired'; return; }
    if (!hash_equals(salon_sign('local:' . $rel, $exp), $sig)) { http_response_code(403); echo 'bad signature'; return; }

    $file = salon_resolve_local($rel);
    if (!$file || !is_readable($file)) { http_response_code(404); echo 'not found'; return; }

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $allowed = array_merge($GLOBALS['salon_video_ext'], ['vtt']);
    if (!in_array($ext, $allowed, true)) { http_response_code(403); echo 'forbidden'; return; }

    while (ob_get_level() > 0) { @ob_end_clean(); }
    @set_time_limit(0);

    $size = (int) filesize($file);
    $ctype = $ext === 'vtt' ? 'text/vtt; charset=utf-8' : (SALON_TYPES[$ext] ?? 'application/octet-stream');

    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: range');
    header('Access-Control-Expose-Headers: content-length, content-range, accept-ranges');
    header('Accept-Ranges: bytes');
    header('Cache-Control: no-store');
    header('Content-Disposition: inline');
    header('Content-Type: ' . $ctype);
    header('X-Content-Type-Options: nosniff');

    $max_chunk = 4 * 1024 * 1024; // ۴ مگابایت برای جلوگیری از خطای 413 روی LiteSpeed/ModSecurity
    $start = 0;
    $end = $size > 0 ? $size - 1 : 0;
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    $is_head = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');
    if ($size > 0 && preg_match('/^bytes=(\d*)-(\d*)$/i', trim($range), $m)) {
        if ($m[1] === '' && $m[2] !== '') {
            $suffix = (int) $m[2];
            $start = max(0, $size - $suffix);
        } else {
            $start = (int) $m[1];
            if ($m[2] !== '') $end = min($end, (int) $m[2]);
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            return;
        }
        if ($ext !== 'vtt' && ($end - $start + 1) > $max_chunk) {
            $end = $start + $max_chunk - 1;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    } elseif ($size > $max_chunk && $ext !== 'vtt' && !$is_head) {
        $end = $max_chunk - 1;
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    } else {
        http_response_code(200);
    }

    $length = $size > 0 ? ($end - $start + 1) : 0;
    header('Content-Length: ' . $length);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $length === 0) return;

    $fh = @fopen($file, 'rb');
    if (!$fh) return;
    if ($start > 0) fseek($fh, $start);
    $left = $length;
    while ($left > 0 && !feof($fh) && !connection_aborted()) {
        $chunk = fread($fh, min(1048576, $left));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        @flush();
        $left -= strlen($chunk);
    }
    fclose($fh);
}

// ریدایرکت‌ها را دستی دنبال می‌کند و هر مقصد را دوباره از فیلتر SSRF رد می‌کند
function salon_resolve_redirects(string $target, int $max_hops = 5): ?string {
    $cur = $target;
    for ($i = 0; $i < $max_hops; $i++) {
        $ch = curl_init($cur);
        $loc = '';
        $hdrs = salon_upstream_headers($cur, 'bytes=0-1');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($hdrs), $hdrs),
            CURLOPT_NOBODY => false,
            CURLOPT_RANGE => '0-1',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$loc) {
                if (stripos($line, 'location:') === 0) $loc = trim(substr($line, 9));
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) {
                return 0;
            },
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code >= 300 && $code < 400 && $loc !== '') {
            if (!preg_match('/^https?:\/\//i', $loc)) {
                $p = parse_url($cur);
                $base = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (!empty($p['port']) ? ':' . $p['port'] : '');
                $loc = str_starts_with($loc, '/') ? $base . $loc : $base . '/' . ltrim($loc, '/');
            }
            $safe = salon_safe_target($loc);
            if (!$safe) return null;
            $cur = $safe;
            continue;
        }
        return $cur;
    }
    return $cur;
}

function salon_curl_passthrough(string $target, ?string $range, bool $head_only): void {
    $final = salon_resolve_redirects($target);
    if (!$final) { http_response_code(403); echo 'blocked redirect'; return; }

    while (ob_get_level() > 0) { @ob_end_clean(); }
    @set_time_limit(0);
    $ch = curl_init($final);
    $headers_out = [];
    $guess = SALON_TYPES[salon_ext_of($final)] ?? '';

    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys(salon_upstream_headers($final, $range)), salon_upstream_headers($final, $range)),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_NOBODY => $head_only,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers_out) {
            if (stripos($line, 'HTTP/') === 0) $headers_out = [];
            $headers_out[] = $line;
            return strlen($line);
        },
    ]);

    $started = false;
    if (!$head_only) {
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $chunk) use (&$started, &$headers_out, $guess) {
            if (!$started) {
                $started = true;
                salon_emit_response_headers($curl, $headers_out, $guess);
            }
            echo $chunk;
            @flush();
            return strlen($chunk);
        });
        curl_exec($ch);
        if (!$started) salon_emit_response_headers($ch, $headers_out, $guess); // خطا قبل از دریافت بدنه
    } else {
        curl_exec($ch);
        salon_emit_response_headers($ch, $headers_out, $guess);
    }
    curl_close($ch);
}

function salon_emit_response_headers($ch, array $raw_headers, string $guess = ''): void {
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($status < 200) $status = 502;
    http_response_code($status ?: 502);

    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: range');
    header('Access-Control-Expose-Headers: content-length, content-range, accept-ranges');
    header('Cache-Control: no-store');
    header('Content-Disposition: inline');
    header('X-Content-Type-Options: nosniff');

    $wanted = ['content-length', 'content-range', 'etag', 'last-modified'];
    $upstreamType = '';
    foreach ($raw_headers as $line) {
        if (!str_contains($line, ':')) continue;
        [$k, $v] = array_map('trim', explode(':', $line, 2));
        $v = str_replace(["\r", "\n"], '', $v);
        $lk = strtolower($k);
        if (in_array($lk, $wanted, true)) header("$k: $v");
        if ($lk === 'content-type') $upstreamType = strtolower($v);
    }
    header('Accept-Ranges: bytes');

    // بالادست اگر video/ یا audio/ داده همان را نگه دار؛ وگرنه از پسوند حدس بزن (هرگز html/svg نفرست)
    $finalType = (str_starts_with($upstreamType, 'video/') || str_starts_with($upstreamType, 'audio/'))
        ? $upstreamType
        : ($guess ?: 'application/octet-stream');
    header('Content-Type: ' . $finalType);
}

// محدود کردن هر پاسخ پروکسی به ۴ مگابایت تا روی هاست‌های LiteSpeed/Cloudflare خطای HTTP 413 ندهد
function salon_cap_range(?string $range, bool $head_only): string {
    $chunk = 4 * 1024 * 1024;
    if ($head_only) return 'bytes=0-0';
    if ($range && preg_match('/^bytes=(\d+)-(\d*)$/i', trim($range), $m)) {
        $start = (int) $m[1];
        $end = $m[2] !== '' ? (int) $m[2] : ($start + $chunk - 1);
        if ($end < $start) $end = $start + $chunk - 1;
        if (($end - $start + 1) > $chunk) $end = $start + $chunk - 1;
        return "bytes={$start}-{$end}";
    }
    if ($range && preg_match('/^bytes=-(\d+)$/i', trim($range), $m)) {
        $suffix = min((int) $m[1], $chunk);
        return "bytes=-{$suffix}";
    }
    return 'bytes=0-' . ($chunk - 1);
}

function salon_stream_proxy(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: range');
        return;
    }
    $exp = (int) ($_GET['e'] ?? 0);
    $sig = wp_unslash((string) ($_GET['s'] ?? ''));
    $target = salon_safe_target(isset($_GET['u']) ? wp_unslash((string) $_GET['u']) : null);

    if (!$target || !$exp || (microtime(true) * 1000) > $exp) { http_response_code(403); echo 'link expired'; return; }
    if (!hash_equals(salon_sign($target, $exp), $sig)) { http_response_code(403); echo 'bad signature'; return; }

    $head_only = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD';
    $range = salon_cap_range($_SERVER['HTTP_RANGE'] ?? null, $head_only);
    salon_curl_passthrough($target, $range, $head_only);
}

function salon_sub_proxy(): void {
    $exp = (int) ($_GET['e'] ?? 0);
    $sig = wp_unslash((string) ($_GET['s'] ?? ''));
    $target = salon_safe_target(isset($_GET['u']) ? wp_unslash((string) $_GET['u']) : null);
    if (!$target || !$exp || (microtime(true) * 1000) > $exp) { http_response_code(403); echo 'link expired'; return; }
    if (!hash_equals(salon_sign('sub:' . $target, $exp), $sig)) { http_response_code(403); echo 'bad signature'; return; }

    $final = salon_resolve_redirects($target);
    if (!$final) { http_response_code(403); echo 'blocked redirect'; return; }

    $ch = curl_init($final);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys(salon_upstream_headers($final, null)), salon_upstream_headers($final, null)),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    if ($body === false) { http_response_code(502); echo 'unreachable'; return; }
    if (in_array($status, [401, 403], true)) { http_response_code(502); echo 'forbidden'; return; }
    if (in_array($status, [404, 410], true)) { http_response_code(502); echo 'gone'; return; }
    if ($status < 200 || $status >= 400) { http_response_code(502); echo 'bad-status'; return; }
    if (str_starts_with(strtolower($ctype), 'text/html')) { http_response_code(422); echo 'not-a-file'; return; }
    if (strlen($body) > 2 * 1024 * 1024) { http_response_code(413); echo 'too-big'; return; }
    if (substr($body, 0, 2) === "PK" || substr($body, 0, 2) === "\x1f\x8b") { http_response_code(422); echo 'archive'; return; }

    header('Content-Type: application/octet-stream');
    echo $body;
}

function salon_probe_endpoint(): void {
    header('Content-Type: application/json; charset=utf-8');
    $nonce = wp_unslash((string) ($_GET['_wpnonce'] ?? ''));
    $room = strtoupper(wp_unslash((string) ($_GET['room'] ?? '')));
    $viewer = wp_unslash((string) ($_GET['viewer'] ?? ''));
    $auth_ok = ($nonce !== '' && wp_verify_nonce($nonce, 'salon_probe'));
    if (!$auth_ok && salon_room_key_ok($room) && $viewer !== '') {
        $st = salon_get_room($room);
        $auth_ok = isset($st['viewers'][$viewer]);
    }
    if (!$auth_ok) {
        http_response_code(403);
        echo wp_json_encode(['ok' => false, 'code' => 'auth']);
        return;
    }
    $target = salon_safe_target(isset($_GET['u']) ? wp_unslash((string) $_GET['u']) : null);
    if (!$target) { echo wp_json_encode(['ok' => false, 'code' => 'bad-url']); return; }

    $final = salon_resolve_redirects($target);
    if (!$final) { echo wp_json_encode(['ok' => false, 'code' => 'bad-url']); return; }

    $probe_once = function (bool $with_ref) use ($final): array {
        $ch = curl_init($final);
        $headers_arr = [];
        $hdrs = salon_upstream_headers($final, 'bytes=0-1', $with_ref);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($hdrs), $hdrs),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_NOBODY => false,
            CURLOPT_RANGE => '0-1',
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers_arr) {
                if (stripos($line, 'HTTP/') === 0) $headers_arr = [];
                $headers_arr[] = $line;
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) {
                return 0; // همین یک تکه کافی است؛ جلوی دانلود فایل چند گیگابایتی در رم را می‌گیرد
            },
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, $headers_arr];
    };

    [$status, $headers_arr] = $probe_once(false);
    if ($status === 403) {
        [$status, $headers_arr] = $probe_once(true);
    }
    if ($status === 0 && !$headers_arr) { echo wp_json_encode(['ok' => false, 'code' => 'unreachable']); return; }
    $headers = implode('', $headers_arr);

    preg_match('/^content-type:\s*(.+)$/mi', $headers, $mt);
    preg_match('/^content-range:\s*bytes\s*\d+-\d+\/(\d+)/mi', $headers, $mr);
    $type = strtolower(trim($mt[1] ?? ''));
    $total = isset($mr[1]) ? (int) $mr[1] : 0;

    if (in_array($status, [401, 403], true)) { echo wp_json_encode(['ok' => false, 'code' => 'forbidden']); return; }
    if (in_array($status, [404, 410], true)) { echo wp_json_encode(['ok' => false, 'code' => 'gone']); return; }
    if ($status !== 206 && $status !== 200) { echo wp_json_encode(['ok' => false, 'code' => 'bad-status']); return; }
    if (str_starts_with($type, 'text/html')) { echo wp_json_encode(['ok' => false, 'code' => 'not-a-file']); return; }

    $ext = salon_ext_of($final);
    echo wp_json_encode([
        'ok' => true, 'type' => $type, 'size' => $total, 'container' => $ext,
        'wideSupport' => in_array($ext, ['mp4', 'm4v', 'webm', 'ogv', 'mp3', 'm4a'], true),
    ]);
}

/* ============================================================
 *  REST API
 * ============================================================ */
add_action('rest_api_init', function () {
    $ns = 'salon/v1';

    register_rest_route($ns, '/films', ['methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => fn() => rest_ensure_response(salon_scan_films())]);

    register_rest_route($ns, '/join', ['methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => salon_locked('salon_rest_join')]);

    register_rest_route($ns, '/state', ['methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => salon_locked('salon_rest_state')]);

    register_rest_route($ns, '/ctl', ['methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => salon_locked('salon_rest_ctl')]);

    register_rest_route($ns, '/source', ['methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => salon_locked('salon_rest_source')]);

    register_rest_route($ns, '/sub', ['methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => salon_locked('salon_rest_sub')]);

    register_rest_route($ns, '/extract-sub', ['methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => salon_locked('salon_rest_extract_sub')]);

    register_rest_route($ns, '/sub-delay', ['methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => salon_locked('salon_rest_sub_delay')]);

    register_rest_route($ns, '/lock', ['methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => salon_locked('salon_rest_lock')]);

    register_rest_route($ns, '/chat', ['methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => salon_locked('salon_rest_chat')]);

    register_rest_route($ns, '/rename', ['methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => salon_locked('salon_rest_rename')]);

    register_rest_route($ns, '/kick', ['methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => salon_locked('salon_rest_kick')]);

    register_rest_route($ns, '/newcode', ['methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => salon_locked('salon_rest_newcode')]);
});

// جلوی کش شدن پاسخ‌های API توسط هاست/پلاگین کش/Cloudflare
add_filter('rest_post_dispatch', function ($result, $server, $request) {
    if (strpos($request->get_route(), '/salon/v1') === 0 && $result instanceof WP_REST_Response) {
        $result->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
    return $result;
}, 10, 3);

function salon_public_state(array $state, string $viewer_id, ?int $chat_after): array {
    $hostId = null; $earliest = PHP_INT_MAX;
    foreach ($state['viewers'] as $id => $v) {
        if ((int) $v['joined'] < $earliest) { $earliest = (int) $v['joined']; $hostId = $id; }
    }
    $present = $viewer_id !== '' && isset($state['viewers'][$viewer_id]);
    $viewer_is_host = $present && $viewer_id === $hostId;

    // شناسه‌ی بقیه فقط برای میزبان فرستاده می‌شود (لازمه‌ی کیک)؛ برای بقیه مخفی می‌ماند
    // تا کسی نتواند با شناسه‌ی میزبان کارِ او را جعل کند.
    $people = array_values(array_map(fn($id, $v) => [
        'id'     => $viewer_is_host ? $id : null,
        'name'   => $v['name'],
        'host'   => $id === $hostId,
        'me'     => $id === $viewer_id,
        'joined' => $v['joined'],
    ], array_keys($state['viewers']), $state['viewers']));
    usort($people, fn($a, $b) => ($b['host'] <=> $a['host']) ?: ($a['joined'] <=> $b['joined']));

    $chat = $state['chat'];
    if ($chat_after !== null) $chat = array_values(array_filter($chat, fn($m) => $m['id'] > $chat_after));
    $chat = array_map(fn($m) => [
        'id' => $m['id'], 'text' => $m['text'], 'name' => $m['name'], 'at' => $m['at'],
        'me' => ($m['from'] ?? '') === $viewer_id,
    ], $chat);

    if (!$present) {
        return [
            'you' => [
                'id'      => $viewer_id,
                'name'    => '',
                'host'    => false,
                'present' => false,
                'kicked'  => $viewer_id !== '' && in_array($viewer_id, $state['kicked'], true),
            ],
        ];
    }

    return [
        'you'     => [
            'id'      => $viewer_id,
            'name'    => $state['viewers'][$viewer_id]['name'] ?? '',
            'host'    => $viewer_is_host,
            'present' => true,
            'kicked'  => false,
        ],
        'source'        => $state['source'],
        'play'          => salon_play_url($state),
        'play_fallback' => salon_play_fallback_url($state),
        'lock'    => $state['lock'],
        'paused'  => $state['paused'],
        'time'    => salon_head_time($state),
        'sub'     => $state['sub'],
        'people'  => $people,
        'chat'    => $chat,
    ];
}

function salon_parse_common(WP_REST_Request $req): array {
    $room = strtoupper((string) $req->get_param('room'));
    $viewer = (string) $req->get_param('viewer');
    return [$room, $viewer];
}

function salon_rest_join(WP_REST_Request $req) {
    $room = strtoupper(sanitize_text_field((string) $req->get_param('room')));
    $name = mb_substr(sanitize_text_field((string) $req->get_param('name')), 0, 28) ?: 'مهمان';
    if (!salon_room_key_ok($room)) return new WP_Error('bad_room', 'کد اتاق نامعتبر است', ['status' => 400]);

    $token = substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $req->get_param('token')), 0, 40);
    $existing = preg_replace('/[^A-Za-z0-9]/', '', (string) $req->get_param('viewer'));
    $state = salon_get_room($room);
    $now = (int) (microtime(true) * 1000);

    // کد این اتاق عوض شده: فقط کسانی که قبلاً داخل بودند به کد جدید هدایت می‌شوند
    if (!empty($state['moved_to'])) {
        if ($existing !== '' && in_array($existing, $state['moved_ok'], true)) {
            return rest_ensure_response(['closed' => true, 'moved_to' => $state['moved_to']]);
        }
        return new WP_Error('room_closed', 'کد این اتاق عوض شده؛ لینک جدید را از میزبان بگیر', ['status' => 410]);
    }

    if ($token !== '' && in_array($token, $state['banned'], true)) {
        return new WP_Error('kicked', 'میزبان تو را از این اتاق حذف کرده است', ['status' => 403]);
    }
    salon_prune_and_host($state);

    if ($existing !== '' && isset($state['viewers'][$existing])) {
        // رفرش صفحه: همان شناسه و همان زمان ورود (میزبان بودن از دست نمی‌رود)
        $id = $existing;
        $state['viewers'][$id]['name'] = $name;
        $state['viewers'][$id]['last_seen'] = $now;
        if ($token !== '') $state['viewers'][$id]['token'] = $token;
    } else {
        if (count($state['viewers']) >= 50) {
            return new WP_Error('room_full', 'ظرفیت این اتاق تکمیل است', ['status' => 429]);
        }
        $id = substr(wp_generate_password(16, false), 0, 16);
        $state['viewers'][$id] = ['name' => $name, 'joined' => $now, 'last_seen' => $now, 'token' => $token];
    }
    salon_save_room($room, $state);

    return rest_ensure_response(salon_public_state($state, $id, null));
}

function salon_rest_state(WP_REST_Request $req) {
    [$room, $viewer] = salon_parse_common($req);
    if (!salon_room_key_ok($room)) return new WP_Error('bad_room', 'کد اتاق نامعتبر است', ['status' => 400]);

    $state = salon_get_room($room);

    if (!empty($state['moved_to'])) {
        $follow = $viewer !== '' && in_array($viewer, $state['moved_ok'], true);
        return rest_ensure_response(['closed' => true, 'moved_to' => $follow ? $state['moved_to'] : null]);
    }

    if ($viewer !== '' && isset($state['viewers'][$viewer])) {
        $state['viewers'][$viewer]['last_seen'] = (int) (microtime(true) * 1000);
        salon_prune_and_host($state);
        salon_save_room($room, $state);
    }

    $chat_after = $req->get_param('chat_after');
    return rest_ensure_response(salon_public_state($state, $viewer, $chat_after !== null ? (int) $chat_after : null));
}

function salon_is_host(array $state, string $viewer): bool {
    $hostId = null; $earliest = PHP_INT_MAX;
    foreach ($state['viewers'] as $id => $v) {
        if ((int) $v['joined'] < $earliest) { $earliest = (int) $v['joined']; $hostId = $id; }
    }
    return $viewer !== '' && $viewer === $hostId;
}

function salon_denied_if_locked(array $state, string $viewer) {
    if ($viewer === '' || !isset($state['viewers'][$viewer])) {
        return new WP_Error('not_member', 'در این اتاق نیستی', ['status' => 403]);
    }
    if ($state['lock'] && !salon_is_host($state, $viewer)) {
        return new WP_Error('denied', 'اتاق فقط برای میزبان قابل کنترل است', ['status' => 403]);
    }
    return null;
}

function salon_rest_ctl(WP_REST_Request $req) {
    [$room, $viewer] = salon_parse_common($req);
    if (!salon_room_key_ok($room)) return new WP_Error('bad_room', 'کد اتاق نامعتبر است', ['status' => 400]);
    $state = salon_get_room($room);
    if ($d = salon_denied_if_locked($state, $viewer)) return $d;

    $a = (string) $req->get_param('a');
    $time = max(0, (float) $req->get_param('time'));
    $state['time'] = $time;
    $state['at'] = (int) (microtime(true) * 1000);
    if ($a === 'pause') $state['paused'] = true;
    elseif ($a === 'play') $state['paused'] = false;
    // a === 'seek': فقط زمان عوض می‌شود، وضعیت پخش/پاز همان می‌ماند
    salon_save_room($room, $state);
    return rest_ensure_response(['ok' => true]);
}

function salon_rest_source(WP_REST_Request $req) {
    [$room, $viewer] = salon_parse_common($req);
    if (!salon_room_key_ok($room)) return new WP_Error('bad_room', 'کد اتاق نامعتبر است', ['status' => 400]);
    $state = salon_get_room($room);
    if ($d = salon_denied_if_locked($state, $viewer)) return $d;

    $type = (string) $req->get_param('type');
    $label = mb_substr(sanitize_text_field((string) $req->get_param('label')), 0, 90) ?: 'ویدیو';

    if ($type === 'local') {
        $film_id = (string) $req->get_param('id');
        $real = salon_resolve_local($film_id);
        if (!$real) return new WP_Error('bad_file', 'فیلم پیدا نشد — پوشه‌ی فیلم‌ها در تنظیمات درست است؟', ['status' => 404]);
        if (!in_array(strtolower(pathinfo($real, PATHINFO_EXTENSION)), $GLOBALS['salon_video_ext'], true)) {
            return new WP_Error('bad_file', 'این فرمت در مرورگر پخش نمی‌شود. اول فیلم را از «سالن سینما ← فیلم‌ها» آماده کن.', ['status' => 400]);
        }
        $url = salon_local_url($real) ?: '';
        $state['source'] = ['type' => 'local', 'id' => $film_id, 'url' => $url, 'label' => $label];
    } else {
        $url = salon_safe_target((string) $req->get_param('url'));
        if (!$url) return new WP_Error('bad_url', 'آدرس نامعتبر است', ['status' => 400]);
        $state['source'] = ['type' => 'external', 'url' => $url, 'label' => $label];
    }

    $state['sub'] = null;
    $state['paused'] = true;
    $state['time'] = 0;
    $state['at'] = (int) (microtime(true) * 1000);
    salon_save_room($room, $state);
    return rest_ensure_response(['ok' => true]);
}

function salon_rest_sub(WP_REST_Request $req) {
    [$room, $viewer] = salon_parse_common($req);
    if (!salon_room_key_ok($room)) return new WP_Error('bad_room', 'کد اتاق نامعتبر است', ['status' => 400]);
    $state = salon_get_room($room);
    if ($d = salon_denied_if_locked($state, $viewer)) return $d;

    $clear = $req->get_param('clear');
    if ($clear) {
        $state['sub'] = null;
    } else {
        $label = mb_substr(sanitize_text_field((string) $req->get_param('label')), 0, 90) ?: 'زیرنویس';
        $lang = sanitize_text_field((string) $req->get_param('lang')) ?: 'xx';

        $fallback = null;
        if ($req->get_param('ext_sub_file')) {
            $fname = wp_basename((string) $req->get_param('ext_sub_file'));
            $fpath = salon_extsub_dir() . '/' . $fname;
            if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]+\.vtt$/i', $fname) || !is_file($fpath)) {
                return new WP_Error('bad_file', 'زیرنویس استخراج‌شده پیدا نشد', ['status' => 404]);
            }
            $url = salon_extsub_url($fname);
        } elseif ($req->get_param('film_id') && $req->get_param('sub_file')) {
            $film_dir = dirname((string) $req->get_param('film_id'));
            $sub_rel = ($film_dir === '.' || $film_dir === '' ? '' : $film_dir . '/') . wp_basename((string) $req->get_param('sub_file'));
            $real = salon_resolve_local($sub_rel);
            if (!$real || strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'vtt') {
                return new WP_Error('bad_file', 'زیرنویس پیدا نشد', ['status' => 404]);
            }
            $stream = salon_local_stream_url($sub_rel);
            $direct = salon_local_url($real);
            $url = $direct ?: $stream;
            if ($direct) $fallback = $stream;
        } else {
            $raw = salon_safe_target((string) $req->get_param('url'));
            if (!$raw) return new WP_Error('bad_url', 'آدرس زیرنویس نامعتبر است', ['status' => 400]);
            $exp = (int) (microtime(true) * 1000) + 24 * 3600 * 1000;
            $sig = salon_sign('sub:' . $raw, $exp);
            $url = add_query_arg(['salon_sub' => 1, 'u' => $raw, 'e' => $exp, 's' => $sig], home_url('/'));
        }
        $state['sub'] = ['lang' => $lang, 'label' => $label, 'url' => $url, 'fallback' => $fallback, 'delay' => 0];
    }
    salon_save_room($room, $state);
    return rest_ensure_response(['ok' => true]);
}

function salon_rest_extract_sub(WP_REST_Request $req) {
    [$room, $viewer] = salon_parse_common($req);
    if (!salon_room_key_ok($room)) return new WP_Error('bad_room', 'کد اتاق نامعتبر است', ['status' => 400]);
    $state = salon_get_room($room);
    if ($d = salon_denied_if_locked($state, $viewer)) return $d;

    $src = $state['source'] ?? null;
    if (!$src || ($src['type'] ?? '') !== 'external' || empty($src['url'])) {
        return new WP_Error('no_ext', 'فیلم فعلی لینک بیرونی نیست', ['status' => 400]);
    }
    if (!function_exists('proc_open')) {
        return new WP_Error('no_proc', 'تابع proc_open روی این هاست غیرفعال است؛ استخراج از لینک ممکن نیست.', ['status' => 500]);
    }
    $target = salon_safe_target($src['url']);
    $final = $target ? salon_resolve_redirects($target) : null;
    if (!$final) return new WP_Error('bad_url', 'آدرس فیلم معتبر نیست', ['status' => 400]);

    $ffprobe = salon_media_bin('ffprobe');
    $ffmpeg = salon_media_bin('ffmpeg');
    [$pc, $pout] = salon_media_run([
        $ffprobe, '-v', 'error', '-protocol_whitelist', 'file,http,https,tcp,tls',
        '-print_format', 'json', '-show_streams', '-select_streams', 's', $final,
    ]);
    if ($pc !== 0 || $pout === '') {
        [$vc] = salon_media_run([$ffprobe, '-version']);
        if ($vc !== 0) return new WP_Error('no_ffmpeg', 'ffprobe روی سرور پیدا نشد (مسیرش را در تنظیمات بگذار).', ['status' => 500]);
        return new WP_Error('probe_fail', 'خواندن اطلاعات زیرنویس از این لینک ممکن نشد.', ['status' => 502]);
    }

    $info = json_decode($pout, true);
    $streams = is_array($info['streams'] ?? null) ? $info['streams'] : [];
    $image_subs = ['hdmv_pgs_subtitle', 'dvd_subtitle', 'dvb_subtitle', 'xsub'];
    $labels = salon_media_lang_labels();

    $dir = salon_extsub_dir();
    $hash = md5($src['url']);
    $used = [];
    $to_extract = [];
    $subs_out = [];

    foreach ($streams as $s) {
        $idx = (int) ($s['index'] ?? -1);
        $scodec = $s['codec_name'] ?? '';
        if ($idx < 0 || in_array($scodec, $image_subs, true)) continue;
        $slang = $s['tags']['language'] ?? '';
        $stitle = $s['tags']['title'] ?? '';
        $short = salon_media_lang_short($slang);
        $suffix = $short; $n = 2;
        while (in_array($suffix, $used, true)) { $suffix = $short . $n; $n++; }
        $used[] = $suffix;

        $fname = $hash . '.' . $suffix . '.vtt';
        $fpath = $dir . '/' . $fname;
        $label = ($labels[$short] ?? $short) . ($stitle && $stitle !== 'und' ? " — $stitle" : '');

        $item = ['lang' => $suffix, 'label' => $label, 'url' => salon_extsub_url($fname), 'file' => $fname];
        $subs_out[] = $item;
        if (!is_file($fpath) || filesize($fpath) === 0) {
            $to_extract[] = ['idx' => $idx, 'path' => $fpath];
        }
    }

    if (!$subs_out) {
        return rest_ensure_response(['ok' => true, 'subs' => [], 'message' => 'این فایل زیرنویس متنی داخلی ندارد (احتمالاً چسبیده به تصویر است یا زیرنویس ندارد).']);
    }

    if ($to_extract) {
        @set_time_limit(0);
        $cmd = [$ffmpeg, '-v', 'error', '-y', '-protocol_whitelist', 'file,http,https,tcp,tls', '-i', $final, '-vn', '-an'];
        foreach ($to_extract as $t) {
            $cmd[] = '-map';
            $cmd[] = '0:' . $t['idx'];
            $cmd[] = '-c:s';
            $cmd[] = 'webvtt';
            $cmd[] = $t['path'];
        }
        [$fc, , $ferr] = salon_media_run($cmd);
        if ($fc !== 0) {
            foreach ($to_extract as $t) {
                if (is_file($t['path']) && filesize($t['path']) === 0) @unlink($t['path']);
            }
            $subs_out = array_values(array_filter($subs_out, fn($it) => is_file($dir . '/' . $it['file']) && filesize($dir . '/' . $it['file']) > 0));
            if (!$subs_out) {
                return new WP_Error('extract_fail', 'استخراج زیرنویس ناموفق بود: ' . trim($ferr), ['status' => 502]);
            }
        }
    }

    return rest_ensure_response(['ok' => true, 'subs' => $subs_out]);
}

function salon_rest_sub_delay(WP_REST_Request $req) {
    [$room, $viewer] = salon_parse_common($req);
    if (!salon_room_key_ok($room)) return new WP_Error('bad_room', 'کد اتاق نامعتبر است', ['status' => 400]);
    $state = salon_get_room($room);
    if ($d = salon_denied_if_locked($state, $viewer)) return $d;
    if (!$state['sub']) return new WP_Error('no_sub', 'زیرنویسی فعال نیست', ['status' => 400]);

    $state['sub']['delay'] = salon_clamp_delay($req->get_param('delay'));
    salon_save_room($room, $state);
    return rest_ensure_response(['ok' => true, 'delay' => $state['sub']['delay']]);
}

function salon_rest_lock(WP_REST_Request $req) {
    [$room, $viewer] = salon_parse_common($req);
    if (!salon_room_key_ok($room)) return new WP_Error('bad_room', 'کد اتاق نامعتبر است', ['status' => 400]);
    $state = salon_get_room($room);
    if (!salon_is_host($state, $viewer)) return new WP_Error('denied', 'فقط میزبان', ['status' => 403]);

    $state['lock'] = (bool) $req->get_param('on');
    salon_save_room($room, $state);
    return rest_ensure_response(['ok' => true]);
}

function salon_rest_chat(WP_REST_Request $req) {
    [$room, $viewer] = salon_parse_common($req);
    if (!salon_room_key_ok($room)) return new WP_Error('bad_room', 'کد اتاق نامعتبر است', ['status' => 400]);
    $state = salon_get_room($room);
    if ($viewer === '' || !isset($state['viewers'][$viewer])) return new WP_Error('not_member', 'در این اتاق نیستی', ['status' => 403]);
    $text = mb_substr(trim(sanitize_text_field((string) $req->get_param('text'))), 0, 500);
    if ($text === '') return new WP_Error('empty', 'خالی است', ['status' => 400]);

    $name = $state['viewers'][$viewer]['name'] ?? 'مهمان';
    $nextId = 1;
    foreach ($state['chat'] as $m) $nextId = max($nextId, $m['id'] + 1);

    $state['chat'][] = ['id' => $nextId, 'text' => $text, 'name' => $name, 'from' => $viewer, 'at' => time()];
    if (count($state['chat']) > 200) $state['chat'] = array_slice($state['chat'], -200);

    salon_save_room($room, $state);
    return rest_ensure_response(['ok' => true]);
}

function salon_rest_rename(WP_REST_Request $req) {
    [$room, $viewer] = salon_parse_common($req);
    if (!salon_room_key_ok($room)) return new WP_Error('bad_room', 'کد اتاق نامعتبر است', ['status' => 400]);
    $state = salon_get_room($room);
    $name = mb_substr(sanitize_text_field((string) $req->get_param('name')), 0, 28);
    if ($name === '' || !isset($state['viewers'][$viewer])) return new WP_Error('bad', 'نامعتبر', ['status' => 400]);

    $state['viewers'][$viewer]['name'] = $name;
    salon_save_room($room, $state);
    return rest_ensure_response(['ok' => true]);
}

/* ============================================================
 *  کیک کردن کاربر و عوض کردن کد اتاق (فقط میزبان)
 * ============================================================ */
function salon_room_exists(string $room): bool {
    global $wpdb;
    $table = $wpdb->prefix . 'salon_rooms';
    return (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM $table WHERE room = %s", $room));
}

function salon_rest_kick(WP_REST_Request $req) {
    [$room, $viewer] = salon_parse_common($req);
    if (!salon_room_key_ok($room)) return new WP_Error('bad_room', 'کد اتاق نامعتبر است', ['status' => 400]);
    $state = salon_get_room($room);
    if (!salon_is_host($state, $viewer)) return new WP_Error('denied', 'فقط میزبان می‌تواند کاربر را حذف کند', ['status' => 403]);

    $target = preg_replace('/[^A-Za-z0-9]/', '', (string) $req->get_param('target'));
    if ($target === '' || $target === $viewer || !isset($state['viewers'][$target])) {
        return new WP_Error('no_user', 'کاربر پیدا نشد', ['status' => 404]);
    }

    // توکن مرورگر را هم بن می‌کنیم تا با رفرش صفحه (که شناسه‌ی تازه می‌گیرد) برنگردد
    $token = (string) ($state['viewers'][$target]['token'] ?? '');
    if ($token !== '' && !in_array($token, $state['banned'], true)) $state['banned'][] = $token;
    $state['banned'] = array_slice($state['banned'], -100);
    $state['kicked'][] = $target;
    $state['kicked'] = array_slice($state['kicked'], -50);
    unset($state['viewers'][$target]);

    salon_save_room($room, $state);
    return rest_ensure_response(['ok' => true]);
}

function salon_rest_newcode(WP_REST_Request $req) {
    global $wpdb;
    [$room, $viewer] = salon_parse_common($req);
    if (!salon_room_key_ok($room)) return new WP_Error('bad_room', 'کد اتاق نامعتبر است', ['status' => 400]);
    $state = salon_get_room($room);
    if (!salon_is_host($state, $viewer)) return new WP_Error('denied', 'فقط میزبان می‌تواند کد را عوض کند', ['status' => 403]);

    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $new = '';
    for ($try = 0; $try < 10 && $new === ''; $try++) {
        $c = '';
        for ($i = 0; $i < 6; $i++) $c .= $chars[random_int(0, strlen($chars) - 1)];
        if (!salon_room_exists($c)) $new = $c;
    }
    if ($new === '') return new WP_Error('busy', 'کد جدید ساخته نشد، دوباره امتحان کن', ['status' => 500]);

    // اتاق جدید: همان وضعیت (فیلم، زمان، چت، حاضران با همان شناسه‌ها)، ولی بدون لیست بن و کیک قدیمی
    $fresh = $state;
    $fresh['banned'] = []; $fresh['kicked'] = [];
    $fresh['moved_to'] = null; $fresh['moved_ok'] = [];
    salon_save_room($new, $fresh);

    // کد قدیمی: فقط یک تابلو که حاضران فعلی را به کد جدید می‌فرستد؛ بقیه (کیک‌شده‌ها) چیزی نمی‌بینند
    $old = salon_default_state();
    $old['moved_to'] = $new;
    $old['moved_ok'] = array_map('strval', array_keys($state['viewers']));
    salon_save_room($room, $old);

    // اتاق‌های خیلی قدیمی را پاک کن
    $table = $wpdb->prefix . 'salon_rooms';
    $wpdb->query("DELETE FROM $table WHERE updated_at < (NOW() - INTERVAL 30 DAY)");

    return rest_ensure_response(['ok' => true, 'room' => $new]);
}

/* ============================================================
 *  شورت‌کد و بارگذاری فایل‌ها
 * ============================================================ */
add_action('wp_enqueue_scripts', function () {
    wp_register_style('salon-cinema', SALON_URL . 'assets/salon.css', [], SALON_VERSION);
    wp_register_script('salon-cinema', SALON_URL . 'assets/salon.js', [], SALON_VERSION, true);
    wp_localize_script('salon-cinema', 'SalonConfig', [
        'apiBase'    => esc_url_raw(rest_url('salon/v1')),
        'homeUrl'    => esc_url_raw(home_url('/')),
        'probeNonce' => wp_create_nonce('salon_probe'),
    ]);
});

add_shortcode('salon', function () {
    wp_enqueue_style('salon-cinema');
    wp_enqueue_script('salon-cinema');
    return '<div id="salon-app" dir="rtl">در حال بارگذاری سالن…</div>';
});

// لینک‌های توسعه‌دهنده در لیست افزونه‌ها
add_filter('plugin_row_meta', function ($links, $file) {
    if ($file === plugin_basename(SALON_FILE)) {
        $links[] = '<a href="' . esc_url('https://' . SALON_DEV_WEBSITE) . '" target="_blank" rel="noopener">' . esc_html(SALON_DEV_NAME) . ' — ' . esc_html(SALON_DEV_WEBSITE) . '</a>';
        $links[] = '<a href="mailto:' . esc_attr(SALON_DEV_EMAIL) . '">' . esc_html(SALON_DEV_EMAIL) . '</a>';
    }
    return $links;
}, 10, 2);
