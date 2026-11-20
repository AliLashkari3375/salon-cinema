<?php
/**
 * مدیریت کتابخانه‌ی فیلم — جایگزین salon-prep.php
 *
 *  - صفحه‌ی «سالن سینما ← فیلم‌ها» در پیشخوان (آپلود، آماده‌سازی، زیرنویس، حذف)
 *  - شورت‌کد [salon_upload] برای آپلود از فرانت (فقط نقش‌هایی که در تنظیمات مجاز شده‌اند)
 *  - آپلود تکه‌تکه (chunked) تا فایل‌های چند گیگابایتی از محدودیت upload_max_filesize رد شوند
 *  - آماده‌سازی با ffmpeg: استخراج زیرنویس داخلی به .vtt و ساخت mp4 بدون انکود مجدد
 *
 * هیچ توکن ثابتی وجود ندارد؛ همه‌ی درخواست‌ها با دسترسی و nonce وردپرس بررسی می‌شوند.
 *
 * توسعه‌دهنده: Ali Lashkari — alilashkari020@gmail.com — https://alilashkari.ir/
 * Copyright (c) 2026 Ali Lashkari. All Rights Reserved. See LICENSE.
 */

if (!defined('ABSPATH')) exit;

/* ============================================================
 *  تنظیمات و ثابت‌ها
 * ============================================================ */
function salon_media_video_ext(): array    { return ['mp4', 'm4v', 'webm', 'mkv', 'avi', 'mov', 'ts']; }
function salon_media_playable_ext(): array { return ['mp4', 'm4v', 'webm']; }
function salon_media_sub_ext(): array      { return ['vtt', 'srt']; }

function salon_media_dir(): string {
    $d = trim((string) get_option('salon_films_dir', ''));
    if ($d === '') {
        $u = wp_upload_dir();
        $d = $u['basedir'] . '/salon-films';
    }
    return rtrim($d, '/\\');
}

// اگر «پوشه‌ی ffmpeg» در تنظیمات خالی باشد، چند جای رایج هاست را خودکار امتحان می‌کنیم
// (مثلاً /home/USER/bin بالاتر از public_html؛ همان جایی که ffmpeg را با File Manager می‌گذارند)
function salon_media_autodetect_dir(): string {
    static $found = null;
    if ($found !== null) return $found;
    $found = '';
    $root = untrailingslashit(wp_normalize_path(ABSPATH));
    $parent = rtrim(dirname($root), '/');
    $cands = [$root . '/bin'];
    if ($parent !== '' && $parent !== '.') { $cands[] = $parent . '/bin'; $cands[] = $parent . '/ffmpeg'; }
    foreach ($cands as $d) {
        if (is_file($d . '/ffmpeg') && is_file($d . '/ffprobe')) { $found = $d; break; }
    }
    return $found;
}

function salon_media_bin(string $name): string {
    $dir = trim((string) get_option('salon_ffmpeg_dir', ''));
    if ($dir === '') $dir = salon_media_autodetect_dir();
    return $dir === '' ? $name : rtrim($dir, '/\\') . '/' . $name;
}

// توضیح دقیق این‌که چرا یک برنامه (ffmpeg/ffprobe) اجرا نمی‌شود
function salon_media_bin_problem(string $name): string {
    $bin = salon_media_bin($name);
    if (strpos($bin, '/') === false) {
        return "✗ $name روی سرور پیدا نشد. پوشه‌ای که ffmpeg و ffprobe داخلش است را در «سالن سینما ← تنظیمات ← پوشه‌ی ffmpeg» بنویس (مثلاً /home/USER/bin).";
    }
    if (!is_file($bin)) {
        return "✗ فایل $bin وجود ندارد. مسیر «پوشه‌ی ffmpeg» را در تنظیمات اصلاح کن (باید خود پوشه باشد، نه فایل).";
    }
    if (!is_executable($bin)) {
        return "✗ فایل $bin اجازه‌ی اجرا ندارد. در File Manager هاست روی آن Permissions را 755 بگذار.";
    }
    return "✗ $name اجرا شد ولی خطا داد. نسخه‌ی ffmpeg با معماری سرور سازگار است؟ ('{$bin}')";
}

function salon_media_upload_roles(): array {
    $roles = get_option('salon_upload_roles', []);
    return is_array($roles) ? array_values($roles) : [];
}

// ادمین همیشه مجاز است؛ بقیه فقط اگر نقششان در تنظیمات تیک خورده باشد
function salon_media_can_upload(): bool {
    if (!is_user_logged_in()) return false;
    if (current_user_can('manage_options')) return true;
    $user = wp_get_current_user();
    return (bool) array_intersect((array) $user->roles, salon_media_upload_roles());
}

function salon_media_can_manage(): bool {
    return current_user_can('manage_options');
}

/* ============================================================
 *  پوشه‌ی فیلم‌ها: ساخت خودکار + محافظت
 * ============================================================ */
function salon_media_ensure_dir(): bool {
    $dir = salon_media_dir();
    if (!is_dir($dir) && !wp_mkdir_p($dir)) return false;
    if (!is_writable($dir)) return false;

    if (!file_exists($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '');
    }
    // اجرای اسکریپت داخل این پوشه ممنوع (سازگار با Apache 2.2 و 2.4 و LiteSpeed)
    if (!file_exists($dir . '/.htaccess')) {
        $ht  = "Options -Indexes\n";
        $ht .= "<FilesMatch \"\\.(php|phtml|php[0-9]|phar|cgi|shtml)(\\.|$)\">\n";
        $ht .= "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n";
        $ht .= "  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n";
        $ht .= "</FilesMatch>\n";
        @file_put_contents($dir . '/.htaccess', $ht);
    }
    return true;
}

/* ============================================================
 *  نام فایل‌ها
 * ============================================================ */
// نام فایل آپلودی را امن می‌کند؛ نام فارسی/با فاصله/پرانتز را نگه می‌دارد
function salon_media_clean_name(string $name): string {
    $name = wp_unslash($name);
    $name = wp_basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', (string) $name);
    $name = preg_replace('/[\/\\\\:*?"<>|#]+/u', '_', (string) $name);
    $name = preg_replace('/\s+/u', ' ', (string) $name);
    $name = trim((string) $name, " .");
    if ($name === '' || $name === '.' || $name === '..') return '';

    $ext  = pathinfo($name, PATHINFO_EXTENSION);
    $base = $ext !== '' ? substr($name, 0, -(strlen($ext) + 1)) : $name;

    // بخش‌های خطرناک وسط نام (مثل evil.php.mp4) بی‌اثر می‌شوند
    $parts = explode('.', $base);
    foreach ($parts as &$p) {
        if (preg_match('/^(php\d*|phtml|phar|pl|py|cgi|sh|shtml|asp|aspx|jsp|exe|htaccess)$/i', $p)) $p .= '_';
    }
    unset($p);
    $base = implode('.', $parts);
    if (function_exists('mb_substr') && mb_strlen($base, 'UTF-8') > 140) {
        $base = mb_substr($base, 0, 140, 'UTF-8');
    }
    $base = trim($base, " .");
    if ($base === '') return '';
    return $ext !== '' ? $base . '.' . strtolower($ext) : $base;
}

/**
 * نام آپلودی را بررسی می‌کند.
 * فیلم: پسوند باید در لیست مجاز باشد.
 * زیرنویس: باید به شکل «نام فیلم.fa.vtt» یا «.srt» باشد؛ srt خودکار به vtt تبدیل می‌شود.
 * خروجی: ['name' => نام نهایی روی دیسک, 'kind' => video|sub, 'srt' => bool] یا WP_Error
 */
function salon_media_resolve_subdir(string $raw_dir): string {
    $raw_dir = trim(str_replace('\\', '/', wp_unslash($raw_dir)), '/');
    if ($raw_dir === '' || $raw_dir === '.') return '';
    $parts = array_filter(explode('/', $raw_dir), fn($p) => $p !== '' && $p !== '.');
    foreach ($parts as $part) {
        if ($part === '..') return '';
    }
    $rel = implode('/', $parts);
    $base = realpath(salon_media_dir());
    $target = $base ? realpath($base . '/' . $rel) : false;
    if (!$base || !$target || !is_dir($target)) return '';
    $prefix = rtrim(wp_normalize_path($base), '/') . '/';
    if (strpos(wp_normalize_path($target), $prefix) !== 0) return '';
    return $rel;
}

function salon_media_parse_name(string $raw) {
    $name = salon_media_clean_name($raw);
    if ($name === '') return new WP_Error('bad_name', 'نام فایل نامعتبر است.', ['status' => 400]);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if (in_array($ext, salon_media_video_ext(), true)) {
        return ['name' => $name, 'kind' => 'video', 'srt' => false];
    }
    if (in_array($ext, salon_media_sub_ext(), true)) {
        if (!preg_match('/^(.+)\.([a-z]{2}\d?)\.(vtt|srt)$/i', $name, $m)) {
            return new WP_Error('bad_sub', 'نام زیرنویس باید به شکل «نام‌فیلم.fa.vtt» باشد.', ['status' => 400]);
        }
        return ['name' => $m[1] . '.' . strtolower($m[2]) . '.vtt', 'kind' => 'sub', 'srt' => strtolower($m[3]) === 'srt'];
    }
    return new WP_Error('bad_ext', 'این نوع فایل مجاز نیست. فقط ویدیو (mp4, mkv, …) و زیرنویس (vtt, srt).', ['status' => 400]);
}

function salon_media_tmp_path(string $final, int $total): string {
    return $final . '.' . $total . '.upload';
}

// اندازه‌ی تکه‌ها را بر اساس محدودیت PHP همین هاست حساب می‌کند (کلاینت در صورت 413 خودش کوچکش می‌کند)
function salon_media_max_chunk(): int {
    $mb = 1024 * 1024;
    $pm = function_exists('wp_convert_hr_to_bytes') ? wp_convert_hr_to_bytes((string) ini_get('post_max_size')) : 8 * $mb;
    $um = function_exists('wp_convert_hr_to_bytes') ? wp_convert_hr_to_bytes((string) ini_get('upload_max_filesize')) : 8 * $mb;
    $lim = min($pm > 0 ? $pm : 8 * $mb, $um > 0 ? $um : 8 * $mb);
    return (int) max(64 * 1024, min(2 * $mb, (int) floor($lim * 0.5)));
}

/* ============================================================
 *  لیست کتابخانه
 * ============================================================ */
function salon_media_lang_labels(): array {
    return [
        'fa' => 'فارسی', 'en' => 'English', 'ar' => 'العربية', 'fr' => 'Français',
        'de' => 'Deutsch', 'es' => 'Español', 'tr' => 'Türkçe', 'ru' => 'Русский',
        'ja' => '日本語', 'ko' => '한국어', 'zh' => '中文', 'hi' => 'हिन्दी',
        'it' => 'Italiano', 'xx' => 'نامشخص',
    ];
}

// فایل‌های نیمه‌کاره‌ی آپلود که بیش از ۲ روز رها شده‌اند پاک می‌شوند
function salon_media_cleanup_stale(string $dir): void {
    foreach (glob(salon_glob_escape($dir) . '/*.upload') ?: [] as $f) {
        if (preg_match('/\.\d+\.upload$/', $f) && is_file($f) && (time() - (int) filemtime($f)) > 2 * DAY_IN_SECONDS) {
            @unlink($f);
        }
    }
}

function salon_media_library(): array {
    $dir = salon_media_dir();
    if (!is_dir($dir)) return [];
    salon_media_cleanup_stale($dir);

    $base_dir = untrailingslashit(wp_normalize_path($dir));
    $labels = salon_media_lang_labels();
    $out = [];
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    } catch (Exception $e) {
        return [];
    }
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $path = $f->getPathname();
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, salon_media_video_ext(), true)) continue;

        $playable = in_array($ext, salon_media_playable_ext(), true);
        $base = preg_replace('/\.[^.\/]+$/', '', $path);
        $converted = false;
        if (!$playable) {
            foreach (salon_media_playable_ext() as $pe) {
                if (is_file($base . '.' . $pe)) { $converted = true; break; }
            }
        }

        $subs = [];
        foreach (glob(salon_glob_escape($base) . '.*.vtt') ?: [] as $v) {
            if (!preg_match('/\.([a-z]{2}\d?)\.vtt$/i', $v, $m)) continue;
            $code = strtolower($m[1]);
            $short = preg_replace('/\d$/', '', $code);
            $subs[] = ['file' => basename($v), 'lang' => $code, 'label' => $labels[$short] ?? $short];
        }

        $rel_id = ltrim(substr(wp_normalize_path($path), strlen($base_dir)), '/');
        $out[] = [
            'id'        => $rel_id,
            'url'       => $playable ? (salon_local_url($path) ?: salon_local_stream_url($rel_id)) : '',
            'name'      => wp_basename($path),
            'base'      => wp_basename($base),
            'ext'       => $ext,
            'size'      => (int) $f->getSize(),
            'mtime'     => (int) $f->getMTime(),
            'ready'     => $playable,
            'converted' => $converted,
            'subs'      => $subs,
        ];
    }
    usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

/* ============================================================
 *  ffmpeg: اجرا و آماده‌سازی (پورت salon-prep.php)
 * ============================================================ */
function salon_media_run(array $cmd): array {
    if (!function_exists('proc_open')) return [127, '', 'proc_open غیرفعال است'];
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) return [127, '', 'اجرای برنامه ممکن نشد (مسیر ffmpeg را در تنظیمات بررسی کن)'];
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return [$code, (string) $out, (string) $err];
}

function salon_media_probe(string $file): ?array {
    [$code, $out] = salon_media_run([salon_media_bin('ffprobe'), '-v', 'error', '-print_format', 'json',
        '-show_format', '-show_streams', $file]);
    if ($code !== 0 || $out === '') return null;
    $data = json_decode($out, true);
    return is_array($data) ? $data : null;
}

function salon_media_human_size(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    return $bytes . ' B';
}

function salon_media_lang_short(string $code): string {
    static $map = [
        'per' => 'fa', 'fas' => 'fa', 'fa' => 'fa', 'eng' => 'en', 'en' => 'en', 'ara' => 'ar', 'ar' => 'ar',
        'fre' => 'fr', 'fra' => 'fr', 'fr' => 'fr', 'ger' => 'de', 'deu' => 'de', 'de' => 'de',
        'spa' => 'es', 'es' => 'es', 'tur' => 'tr', 'tr' => 'tr', 'rus' => 'ru', 'ru' => 'ru',
        'jpn' => 'ja', 'ja' => 'ja', 'kor' => 'ko', 'ko' => 'ko', 'chi' => 'zh', 'zho' => 'zh', 'zh' => 'zh',
        'hin' => 'hi', 'hi' => 'hi', 'ita' => 'it', 'it' => 'it',
    ];
    $code = strtolower($code);
    return $map[$code] ?? ($code !== '' && $code !== 'und' ? substr($code, 0, 2) : 'xx');
}

/**
 * یک فیلم را آماده می‌کند. خروجی: ['ok' => bool, 'log' => string[]]
 * - زیرنویس‌های متنی داخل فایل → کنار فیلم به‌صورت .xx.vtt
 * - اگر کانتینر مرورگرپسند نیست و ویدیو H.264 ۸ بیتی است → mp4 بدون انکود مجدد (صدا در صورت نیاز AAC)
 */
function salon_media_prepare(string $file): array {
    $log = [];
    $ok = true;

    if (!function_exists('proc_open')) {
        return ['ok' => false, 'log' => ['✗ تابع proc_open روی این هاست غیرفعال است؛ آماده‌سازی خودکار ممکن نیست. فایل را روی کامپیوتر خودت به mp4 (H.264) تبدیل کن.']];
    }

    $video_ok = ['h264'];
    $audio_ok = ['aac', 'mp3'];
    $container_ok = ['mp4', 'm4v', 'webm'];
    $image_subs = ['hdmv_pgs_subtitle', 'dvd_subtitle', 'dvb_subtitle', 'xsub'];
    $labels = salon_media_lang_labels();

    $ffmpeg = salon_media_bin('ffmpeg');
    $base = preg_replace('/\.[^.\/]+$/', '', $file);
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    $info = salon_media_probe($file);
    if (!$info) {
        // اول مطمئن شو خود برنامه اجرا می‌شود؛ بعد بگو مشکل از فایل است
        [$pc] = salon_media_run([salon_media_bin('ffprobe'), '-version']);
        if ($pc !== 0) return ['ok' => false, 'log' => [salon_media_bin_problem('ffprobe')]];
        return ['ok' => false, 'log' => ['✗ ffprobe اجرا شد ولی نتوانست این فایل را بخواند (فایل ناقص یا خراب است؟).']];
    }

    $vcodec = null; $acodec = null; $subs = []; $pixfmt = '';
    foreach (($info['streams'] ?? []) as $s) {
        $type = $s['codec_type'] ?? '';
        if ($type === 'video' && !$vcodec) {
            // کاور/پوستر داخل mkv هم استریم ویدیو حساب می‌شود؛ آن‌ها را رد می‌کنیم
            if (!empty($s['disposition']['attached_pic'])) continue;
            $vcodec = $s['codec_name'] ?? '?';
            $pixfmt = $s['pix_fmt'] ?? '';
        }
        if ($type === 'audio' && !$acodec) $acodec = $s['codec_name'] ?? '?';
        if ($type === 'subtitle') $subs[] = $s;
    }
    $duration = (int) round((float) ($info['format']['duration'] ?? 0));

    $log[] = 'ویدیو: ' . ($vcodec ?? '?') . '   صدا: ' . ($acodec ?? '?') . '   مدت: ' . intdiv($duration, 60) . ' دقیقه';
    $log[] = '';

    // ---------- زیرنویس‌ها ----------
    $used = [];
    if (!$subs) $log[] = '(زیرنویس داخلی ندارد)';
    foreach ($subs as $s) {
        $idx = $s['index'];
        $scodec = $s['codec_name'] ?? '';
        if (in_array($scodec, $image_subs, true)) {
            $log[] = "⊘ ترک $idx ($scodec) تصویری است — بدون OCR قابل استخراج نیست";
            continue;
        }
        $slang = $s['tags']['language'] ?? '';
        $stitle = $s['tags']['title'] ?? '';
        $short = salon_media_lang_short($slang);
        $suffix = $short; $n = 2;
        while (in_array($suffix, $used, true)) { $suffix = $short . $n; $n++; }
        $used[] = $suffix;

        $out = $base . '.' . $suffix . '.vtt';
        $label = ($labels[$short] ?? $short) . ($stitle && $stitle !== 'und' ? " — $stitle" : '');

        if (file_exists($out)) {
            $log[] = "✓ زیرنویس $suffix از قبل هست";
        } else {
            [$code, , $err] = salon_media_run([$ffmpeg, '-v', 'error', '-y', '-i', $file, '-map', "0:$idx", '-c:s', 'webvtt', $out]);
            if ($code === 0 && is_file($out) && filesize($out) > 0) {
                $log[] = '✓ ' . basename($out) . "  [$label]  " . salon_media_human_size((int) filesize($out));
            } else {
                $log[] = "✗ استخراج ترک $idx ناموفق: " . trim($err);
                @unlink($out);
            }
        }
    }

    // ---------- کانتینر / کدک ----------
    $log[] = '';
    $webm_ok = $ext === 'webm' && in_array($vcodec, ['vp8', 'vp9', 'av1'], true);   // webm با این کدک‌ها مستقیم پخش می‌شود
    $needs_mp4 = !in_array($ext, $container_ok, true) || (!in_array($vcodec, $video_ok, true) && !$webm_ok);
    $ten_bit = $pixfmt !== '' && strpos($pixfmt, '10') !== false;   // مثلاً yuv420p10le

    if (!$needs_mp4 && !$ten_bit) {
        $log[] = '✓ کانتینر و کدک برای مرورگر مناسب است';
    } elseif (!in_array($vcodec, $video_ok, true) && !$webm_ok) {
        $log[] = "⚠ ویدیو با کدک $vcodec است — مرورگرها آن را پخش نمی‌کنند و انکود مجدد واقعی می‌خواهد (کند)؛ این ابزار فقط کپی بدون انکود انجام می‌دهد و اینجا کاری نمی‌کند.";
        $log[] = '  راه‌حل: روی کامپیوتر خودت با HandBrake یا ffmpeg به H.264 (۸ بیت) تبدیلش کن، بعد mp4 را از همین صفحه آپلود کن.';
        $ok = false;
    } elseif ($ten_bit) {
        $log[] = "⚠ ویدیو ۱۰ بیتی است ($pixfmt) — اغلب مرورگرها پخشش نمی‌کنند. کپی بدون انکود کمکی نمی‌کند؛ باید به ۸ بیت انکود شود.";
        $ok = false;
    } else {
        $out_mp4 = $base . '.mp4';
        $part = $out_mp4 . '.part';
        @unlink($part); // بقایای یک اجرای نیمه‌کاره

        // اگر mp4 از قبل هست مطمئن شو سالم است؛ ناقص/خراب دوباره ساخته می‌شود
        $mp4ok = false;
        if (is_file($out_mp4) && $out_mp4 !== $file) {
            $chk = salon_media_probe($out_mp4);
            $d2 = (int) round((float) ($chk['format']['duration'] ?? 0));
            $mp4ok = $chk && $duration > 0 && abs($d2 - $duration) <= 3;
            if (!$mp4ok) {
                $log[] = "⚠ mp4 موجود ناقص یا خراب است (مدت $d2 ثانیه از $duration) — دوباره ساخته می‌شود";
                @unlink($out_mp4);
            }
        }

        if ($mp4ok) {
            $log[] = '✓ نسخه‌ی mp4 از قبل هست و سالم است';
        } else {
            $copy_audio = in_array($acodec, $audio_ok, true);
            $aopt = $copy_audio ? ['-c:a', 'copy'] : ['-c:a', 'aac', '-b:a', '192k', '-ac', '2'];
            // اول در .part می‌نویسیم و فقط بعد از تأیید مدت، اسمش را mp4 می‌کنیم
            $amap = $acodec ? ['-map', '0:a:0'] : [];
            [$code, , $err] = salon_media_run(array_merge(
                [$ffmpeg, '-v', 'error', '-y', '-i', $file, '-map', '0:v:0'],
                $amap,
                ['-c:v', 'copy'],
                $aopt,
                ['-sn', '-dn', '-movflags', '+faststart', '-f', 'mp4', $part]
            ));
            $good = false;
            if ($code === 0 && is_file($part) && filesize($part) > 0) {
                $chk = salon_media_probe($part);
                $d2 = (int) round((float) ($chk['format']['duration'] ?? 0));
                $good = $chk && abs($d2 - $duration) <= 3;
                if (!$good) $err .= "\nمدت mp4 ساخته‌شده ($d2 ثانیه) با اصل ($duration ثانیه) نمی‌خواند.";
            }
            if ($good && @rename($part, $out_mp4)) {
                $log[] = '✓ ' . basename($out_mp4) . '  ' . salon_media_human_size((int) filesize($out_mp4)) .
                         ($copy_audio ? '' : '  (صدا به aac تبدیل شد)');
                $log[] = '  فیلم حالا در سالن فعال است.';
            } else {
                $log[] = '✗ تبدیل ناموفق: ' . trim($err);
                @unlink($part);
                $ok = false;
            }
        }
    }

    return ['ok' => $ok, 'log' => $log];
}

/* ============================================================
 *  زیرنویس: نرمال‌سازی (UTF-8، حذف BOM، srt → vtt)
 * ============================================================ */
// تبدیل Windows-1256 به UTF-8 بدون وابستگی به mbstring/iconv
function salon_media_cp1256_to_utf8(string $in): string {
    static $hi = null;
    if ($hi === null) {
        $cps = [
        0x20AC, 0x067E, 0x201A, 0x0192, 0x201E, 0x2026, 0x2020, 0x2021, 0x02C6, 0x2030, 0x0679,
        0x2039, 0x0152, 0x0686, 0x0698, 0x0688, 0x06AF, 0x2018, 0x2019, 0x201C, 0x201D, 0x2022,
        0x2013, 0x2014, 0x06A9, 0x2122, 0x0691, 0x203A, 0x0153, 0x200C, 0x200D, 0x06BA, 0x00A0,
        0x060C, 0x00A2, 0x00A3, 0x00A4, 0x00A5, 0x00A6, 0x00A7, 0x00A8, 0x00A9, 0x06BE, 0x00AB,
        0x00AC, 0x00AD, 0x00AE, 0x00AF, 0x00B0, 0x00B1, 0x00B2, 0x00B3, 0x00B4, 0x00B5, 0x00B6,
        0x00B7, 0x00B8, 0x00B9, 0x061B, 0x00BB, 0x00BC, 0x00BD, 0x00BE, 0x061F, 0x06C1, 0x0621,
        0x0622, 0x0623, 0x0624, 0x0625, 0x0626, 0x0627, 0x0628, 0x0629, 0x062A, 0x062B, 0x062C,
        0x062D, 0x062E, 0x062F, 0x0630, 0x0631, 0x0632, 0x0633, 0x0634, 0x0635, 0x0636, 0x00D7,
        0x0637, 0x0638, 0x0639, 0x063A, 0x0640, 0x0641, 0x0642, 0x0643, 0x00E0, 0x0644, 0x00E2,
        0x0645, 0x0646, 0x0647, 0x0648, 0x00E7, 0x00E8, 0x00E9, 0x00EA, 0x00EB, 0x0649, 0x064A,
        0x00EE, 0x00EF, 0x064B, 0x064C, 0x064D, 0x064E, 0x00F4, 0x064F, 0x0650, 0x00F7, 0x0651,
        0x00F9, 0x0652, 0x00FB, 0x00FC, 0x200E, 0x200F, 0x06D2,
        ];
        $hi = [];
        foreach ($cps as $i => $cp) $hi[chr(0x80 + $i)] = $cp === 0xFFFD ? '?' : html_entity_decode('&#' . $cp . ';', ENT_QUOTES, 'UTF-8');
    }
    return strtr($in, $hi);
}

function salon_media_normalize_sub(string $raw, bool $is_srt): string {
    $s = $raw;
    if (substr($s, 0, 3) === "\xEF\xBB\xBF") $s = substr($s, 3);
    if (!preg_match('//u', $s)) {
        // زیرنویس‌های فارسی قدیمی اغلب Windows-1256 هستند (mbstring این کدگذاری را ندارد، پس جدول خودمان)
        $s = salon_media_cp1256_to_utf8($s);
    }
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    if ($is_srt || stripos(ltrim($s), 'WEBVTT') !== 0) {
        $s = preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $s);
        $s = "WEBVTT\n\n" . trim($s) . "\n";
    }
    return $s;
}

/* ============================================================
 *  REST API (فضای نام جدا از API عمومی سالن)
 * ============================================================ */
add_action('rest_api_init', function () {
    $ns = 'salon-media/v1';
    $up = 'salon_media_can_upload';
    $mg = 'salon_media_can_manage';

    register_rest_route($ns, '/library', ['methods' => 'GET', 'permission_callback' => $up,
        'callback' => fn() => rest_ensure_response(salon_media_library())]);
    register_rest_route($ns, '/status', ['methods' => 'GET', 'permission_callback' => $up, 'callback' => 'salon_media_rest_status']);
    register_rest_route($ns, '/chunk', ['methods' => 'POST', 'permission_callback' => $up, 'callback' => 'salon_media_rest_chunk']);
    register_rest_route($ns, '/prep', ['methods' => 'POST', 'permission_callback' => $up, 'callback' => 'salon_media_rest_prep']);
    register_rest_route($ns, '/delete', ['methods' => 'POST', 'permission_callback' => $mg, 'callback' => 'salon_media_rest_delete']);
    register_rest_route($ns, '/ffmpeg', ['methods' => 'GET', 'permission_callback' => $mg, 'callback' => 'salon_media_rest_ffmpeg']);
});

add_filter('rest_post_dispatch', function ($result, $server, $request) {
    if (strpos($request->get_route(), '/salon-media/v1') === 0 && $result instanceof WP_REST_Response) {
        $result->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
    return $result;
}, 10, 3);

function salon_media_rest_status(WP_REST_Request $r) {
    $p = salon_media_parse_name((string) $r->get_param('name'));
    if (is_wp_error($p)) return $p;
    $total = (int) $r->get_param('size');
    if ($total <= 0) return new WP_Error('empty', 'فایل خالی است.', ['status' => 400]);
    if (!salon_media_ensure_dir()) {
        return new WP_Error('no_dir', 'پوشه‌ی فیلم‌ها ساخته نشد یا قابل نوشتن نیست. مسیر را در تنظیمات بررسی کن.', ['status' => 500]);
    }

    $sub_dir = ($p['kind'] === 'sub') ? salon_media_resolve_subdir((string) $r->get_param('dir')) : '';
    $dir = salon_media_dir() . ($sub_dir !== '' ? '/' . $sub_dir : '');
    $final = $dir . '/' . $p['name'];
    if (file_exists($final)) {
        return new WP_Error('exists', 'فایلی با این نام از قبل هست: ' . $p['name'], ['status' => 409]);
    }
    $tmp = salon_media_tmp_path($final, $total);
    clearstatcache(true, $tmp);
    $offset = is_file($tmp) ? (int) filesize($tmp) : 0;
    if ($offset >= $total) { @unlink($tmp); $offset = 0; }

    $free = @disk_free_space($dir);
    if ($free !== false && ($total - $offset) > $free) {
        return new WP_Error('space', 'فضای خالی هاست برای این فایل کافی نیست.', ['status' => 507]);
    }
    return rest_ensure_response(['offset' => $offset, 'chunk' => salon_media_max_chunk(), 'name' => $p['name'], 'kind' => $p['kind']]);
}

function salon_media_rest_chunk(WP_REST_Request $r) {
    $p = salon_media_parse_name((string) $r->get_param('name'));
    if (is_wp_error($p)) return $p;
    $total = (int) $r->get_param('total');
    $offset = (int) $r->get_param('offset');
    $files = $r->get_file_params();
    if (!empty($files['chunk']['tmp_name']) && is_uploaded_file($files['chunk']['tmp_name'])) {
        $body = (string) file_get_contents($files['chunk']['tmp_name']);
    } else {
        $body = $r->get_body();
    }
    $len = strlen($body);
    if ($total <= 0 || $offset < 0 || $len === 0 || $offset + $len > $total) {
        return new WP_Error('bad_chunk', 'تکه‌ی آپلود نامعتبر است.', ['status' => 400]);
    }
    if (!salon_media_ensure_dir()) {
        return new WP_Error('no_dir', 'پوشه‌ی فیلم‌ها قابل نوشتن نیست.', ['status' => 500]);
    }

    $sub_dir = ($p['kind'] === 'sub') ? salon_media_resolve_subdir((string) $r->get_param('dir')) : '';
    $final = salon_media_dir() . ($sub_dir !== '' ? '/' . $sub_dir : '') . '/' . $p['name'];
    if (file_exists($final)) {
        return new WP_Error('exists', 'فایلی با این نام از قبل هست: ' . $p['name'], ['status' => 409]);
    }
    $tmp = salon_media_tmp_path($final, $total);
    clearstatcache(true, $tmp);
    $actual = is_file($tmp) ? (int) filesize($tmp) : 0;
    if ($offset === 0 && $actual > 0) { @unlink($tmp); $actual = 0; }
    if ($actual !== $offset) {
        return new WP_Error('offset', 'ترتیب تکه‌ها به‌هم خورد؛ از جای درست ادامه می‌دهم.', ['status' => 409, 'offset' => $actual]);
    }

    $fh = @fopen($tmp, 'ab');
    if (!$fh) return new WP_Error('write', 'نوشتن روی دیسک ممکن نشد.', ['status' => 500]);
    flock($fh, LOCK_EX);
    $written = fwrite($fh, $body);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    if ($written !== $len) {
        return new WP_Error('write', 'نوشتن روی دیسک کامل نشد (فضای هاست پر است؟).', ['status' => 507, 'offset' => $actual]);
    }

    $new_offset = $offset + $len;
    $done = $new_offset === $total;
    if ($done) {
        clearstatcache(true, $tmp);
        if ((int) filesize($tmp) !== $total) {
            return new WP_Error('size', 'اندازه‌ی فایل نهایی با اصل نمی‌خواند.', ['status' => 500]);
        }
        if ($p['kind'] === 'sub') {
            $norm = salon_media_normalize_sub((string) file_get_contents($tmp), $p['srt']);
            $ok = file_put_contents($final, $norm) !== false;
            @unlink($tmp);
            if (!$ok) return new WP_Error('write', 'ذخیره‌ی زیرنویس ناموفق بود.', ['status' => 500]);
        } elseif (!@rename($tmp, $final)) {
            return new WP_Error('rename', 'ذخیره‌ی فایل نهایی ناموفق بود.', ['status' => 500]);
        }
        @chmod($final, 0644);
    }
    return rest_ensure_response(['ok' => true, 'offset' => $new_offset, 'done' => $done, 'name' => $p['name'], 'kind' => $p['kind']]);
}

function salon_media_rest_prep(WP_REST_Request $r) {
    $file = salon_resolve_local((string) $r->get_param('file'));
    if (!$file || !in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), salon_media_video_ext(), true)) {
        return new WP_Error('not_found', 'فایل پیدا نشد.', ['status' => 404]);
    }
    $lock = 'salon_prep_' . md5($file);
    if (get_transient($lock)) {
        return new WP_Error('busy', 'این فایل همین حالا در حال پردازش است.', ['status' => 409]);
    }
    set_transient($lock, 1, 3 * HOUR_IN_SECONDS);
    @set_time_limit(0);
    ignore_user_abort(true);
    try {
        $res = salon_media_prepare($file);
    } finally {
        delete_transient($lock);
    }
    return rest_ensure_response($res);
}

function salon_media_rest_delete(WP_REST_Request $r) {
    $file = salon_resolve_local((string) $r->get_param('file'));
    if (!$file) return new WP_Error('not_found', 'فایل پیدا نشد.', ['status' => 404]);
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $is_video = in_array($ext, salon_media_video_ext(), true);
    if (!$is_video && $ext !== 'vtt') return new WP_Error('bad_ext', 'این نوع فایل از اینجا حذف نمی‌شود.', ['status' => 400]);

    if (!@unlink($file)) return new WP_Error('unlink', 'حذف فایل ناموفق بود (دسترسی نوشتن؟).', ['status' => 500]);
    $deleted = [basename($file)];

    // اگر دیگر هیچ ویدیویی با همین نام پایه نمانده، زیرنویس‌هایش هم پاک می‌شوند
    if ($is_video) {
        $base = preg_replace('/\.[^.\/]+$/', '', $file);
        $others = false;
        foreach (salon_media_video_ext() as $e) {
            if (is_file($base . '.' . $e)) { $others = true; break; }
        }
        if (!$others) {
            foreach (glob(salon_glob_escape($base) . '.*.vtt') ?: [] as $v) {
                if (@unlink($v)) $deleted[] = basename($v);
            }
        }
    }
    return rest_ensure_response(['ok' => true, 'deleted' => $deleted]);
}

function salon_media_ffmpeg_status(): array {
    $res = ['proc_open' => function_exists('proc_open'), 'ffmpeg' => null, 'ffprobe' => null];
    if (!$res['proc_open']) return $res;
    foreach (['ffmpeg', 'ffprobe'] as $b) {
        [$code, $out] = salon_media_run([salon_media_bin($b), '-version']);
        if ($code === 0 && $out !== '') {
            $res[$b] = trim(strtok($out, "\n"));
        }
    }
    return $res;
}

function salon_media_rest_ffmpeg() {
    return rest_ensure_response(salon_media_ffmpeg_status());
}

/* ============================================================
 *  صفحه‌های پیشخوان
 * ============================================================ */
add_action('admin_menu', function () {
    if (!salon_media_can_upload()) return;
    add_menu_page('سالن سینما', 'سالن سینما', 'read', 'salon-cinema', 'salon_media_render_admin_page', 'dashicons-video-alt3', 58);
    add_submenu_page('salon-cinema', 'مدیریت فیلم‌ها', 'فیلم‌ها', 'read', 'salon-cinema', 'salon_media_render_admin_page');
    if (salon_media_can_manage()) {
        add_submenu_page('salon-cinema', 'تنظیمات سالن سینما', 'تنظیمات', 'manage_options', 'salon-cinema-settings', 'salon_media_render_settings_page');
    }
});

function salon_media_enqueue(string $mode): void {
    // فونت‌های سالن (Vazirmatn / Lalezar) از همان استایل اصلی پلاگین می‌آیند
    if ($mode === 'front') wp_enqueue_style('salon-cinema');
    wp_enqueue_style('salon-media', SALON_URL . 'assets/media.css', [], SALON_VERSION);
    wp_enqueue_script('salon-media', SALON_URL . 'assets/media.js', [], SALON_VERSION, true);
    wp_localize_script('salon-media', 'SalonMedia', [
        'api'       => esc_url_raw(rest_url('salon-media/v1')),
        'nonce'     => wp_create_nonce('wp_rest'),
        'mode'      => $mode,
        'canDelete' => salon_media_can_manage(),
        'langs'     => salon_media_lang_labels(),
    ]);
}

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook === 'toplevel_page_salon-cinema') salon_media_enqueue('admin');
});

function salon_media_dev_footer(): void {
    echo '<p class="description" style="margin-top:24px">توسعه: <strong>' . esc_html(SALON_DEV_NAME) . '</strong> — ' .
        '<a href="' . esc_url('https://' . SALON_DEV_WEBSITE) . '" target="_blank" rel="noopener">' . esc_html(SALON_DEV_WEBSITE) . '</a> — ' .
        '<a href="mailto:' . esc_attr(SALON_DEV_EMAIL) . '">' . esc_html(SALON_DEV_EMAIL) . '</a></p>';
}

// آدرس عمومی یک فیلم را از سمت سرور امتحان می‌کند (برای پیدا کردن علت خطای 404 پخش)
function salon_media_url_check(): array {
    foreach (salon_media_library() as $f) {
        if ($f['ready'] && $f['url'] !== '') {
            $r = wp_remote_head($f['url'], ['timeout' => 8, 'redirection' => 3]);
            return ['url' => $f['url'], 'code' => is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r),
                    'err' => is_wp_error($r) ? $r->get_error_message() : ''];
        }
    }
    return [];
}

function salon_media_render_admin_page() {
    if (!salon_media_can_upload()) return;
    salon_media_ensure_dir();
    ?>
    <div class="wrap">
      <h1>مدیریت فیلم‌های سالن</h1>
      <p class="description">پوشه‌ی فیلم‌ها: <code dir="ltr"><?= esc_html(salon_media_dir()) ?></code></p>
      <div id="salon-media-app" class="salon-media" dir="rtl" data-mode="admin">در حال بارگذاری…</div>
      <?php salon_media_dev_footer(); ?>
    </div>
    <?php
}

function salon_media_render_settings_page() {
    if (!salon_media_can_manage()) return;

    $notice = '';
    if (isset($_POST['salon_save']) && check_admin_referer('salon_settings')) {
        update_option('salon_films_dir', rtrim(sanitize_text_field(wp_unslash($_POST['films_dir'] ?? '')), '/\\'));
        update_option('salon_ffmpeg_dir', rtrim(sanitize_text_field(wp_unslash($_POST['ffmpeg_dir'] ?? '')), '/\\'));
        $valid = array_keys(wp_roles()->get_names());
        $picked = isset($_POST['upload_roles']) && is_array($_POST['upload_roles'])
            ? array_values(array_intersect($valid, array_map('sanitize_key', wp_unslash($_POST['upload_roles']))))
            : [];
        update_option('salon_upload_roles', array_values(array_diff($picked, ['administrator'])));
        $notice = salon_media_ensure_dir()
            ? '<div class="updated"><p>ذخیره شد.</p></div>'
            : '<div class="notice notice-warning"><p>ذخیره شد، ولی پوشه‌ی فیلم‌ها ساخته نشد یا قابل نوشتن نیست؛ مسیر را بررسی کن.</p></div>';
    }

    $dir = salon_media_dir();
    $real = realpath($dir);
    $inside = $real && strpos(wp_normalize_path($real), wp_normalize_path((string) realpath(ABSPATH))) === 0;
    $writable = is_dir($dir) && is_writable($dir);
    $ff = salon_media_ffmpeg_status();
    $roles = wp_roles()->get_names();
    $sel = salon_media_upload_roles();
    $uc = salon_media_url_check();
    ?>
    <div class="wrap">
      <h1>تنظیمات سالن سینما</h1>
      <?= $notice // phpcs:ignore WordPress.Security.EscapeOutput ?>
      <form method="post">
        <?php wp_nonce_field('salon_settings'); ?>
        <table class="form-table">
          <tr>
            <th><label for="films_dir">پوشه‌ی فیلم‌ها</label></th>
            <td>
              <input type="text" id="films_dir" name="films_dir" value="<?= esc_attr(get_option('salon_films_dir', $dir)) ?>" class="regular-text" style="direction:ltr;text-align:left">
              <p class="description">
                با نصب پلاگین خودکار ساخته می‌شود. باید داخل سایت باشد تا فیلم‌ها از طریق آدرس قابل پخش باشند.
                <?= $inside ? '<span style="color:green">✓ داخل سایت است.</span>' : '<span style="color:#a05a00">⚠ این پوشه داخل ریشه‌ی سایت پیدا نشد؛ فیلم‌های محلی قابل پخش نخواهند بود.</span>' ?>
                <?= $writable ? '<span style="color:green">✓ قابل نوشتن است.</span>' : '<span style="color:#b32d2e">✗ قابل نوشتن نیست.</span>' ?>
              </p>
            </td>
          </tr>
          <tr>
            <th><label for="ffmpeg_dir">پوشه‌ی ffmpeg</label></th>
            <td>
              <input type="text" id="ffmpeg_dir" name="ffmpeg_dir" value="<?= esc_attr(get_option('salon_ffmpeg_dir', '')) ?>" class="regular-text" style="direction:ltr;text-align:left" placeholder="/home/USER/bin">
              <p class="description">
                پوشه‌ای که فایل‌های <code>ffmpeg</code> و <code>ffprobe</code> داخلش است. اگر خالی بماند از ffmpeg خود سرور استفاده می‌شود.<br>
                وضعیت:
                <?php if (!$ff['proc_open']): ?>
                  <span style="color:#b32d2e">✗ proc_open روی این هاست غیرفعال است؛ آماده‌سازی خودکار کار نمی‌کند.</span>
                <?php elseif ($ff['ffmpeg'] && $ff['ffprobe']): ?>
                  <span style="color:green">✓ <?= esc_html($ff['ffmpeg']) ?></span>
                <?php else: ?>
                  <span style="color:#a05a00">⚠ ffmpeg/ffprobe پیدا نشد؛ آپلود کار می‌کند ولی آماده‌سازی (زیرنویس و تبدیل mkv) نه.</span>
                <?php endif; ?>
              </p>
            </td>
          </tr>
          <tr>
            <th>بررسی آدرس پخش</th>
            <td>
              <?php if (!$uc): ?>
                <span class="description">هنوز فیلم آماده‌ای (mp4) در پوشه نیست.</span>
              <?php else: ?>
                <code dir="ltr" style="word-break:break-all"><?= esc_html($uc['url']) ?></code><br>
                <?php if ($uc['code'] === 200 || $uc['code'] === 206): ?>
                  <span style="color:green">✓ سرور این آدرس را باز کرد (HTTP <?= (int) $uc['code'] ?>).</span>
                <?php elseif ($uc['code'] === 0): ?>
                  <span style="color:#a05a00">⚠ از داخل سرور نتوانستم آدرس را امتحان کنم (<?= esc_html($uc['err']) ?>). همین آدرس را در یک تب جدید باز کن.</span>
                <?php else: ?>
                  <span style="color:#b32d2e">✗ HTTP <?= (int) $uc['code'] ?> — آدرس فیلم باز نمی‌شود؛ پخش در سالن هم خطا می‌دهد.</span>
                <?php endif; ?>
              <?php endif; ?>
              <p class="description">آدرسی که سالن برای پخش اولین فیلم آماده استفاده می‌کند.</p>
            </td>
          </tr>
          <tr>
            <th>چه نقش‌هایی می‌توانند آپلود کنند</th>
            <td>
              <label style="display:block;margin-bottom:4px"><input type="checkbox" checked disabled> مدیر سایت (همیشه مجاز)</label>
              <?php foreach ($roles as $slug => $label): if ($slug === 'administrator') continue; ?>
                <label style="display:block;margin-bottom:4px">
                  <input type="checkbox" name="upload_roles[]" value="<?= esc_attr($slug) ?>" <?= in_array($slug, $sel, true) ? 'checked' : '' ?>>
                  <?= esc_html(translate_user_role($label)) ?>
                </label>
              <?php endforeach; ?>
              <p class="description">فقط به نقش‌هایی که به آن‌ها اعتماد داری اجازه بده. این کاربران می‌توانند فایل ویدیو/زیرنویس آپلود و آماده‌سازی کنند ولی فایل حذف نمی‌کنند.</p>
            </td>
          </tr>
        </table>
        <?php submit_button('ذخیره', 'primary', 'salon_save'); ?>
      </form>
      <hr>
      <p>نمایش سالن در یک صفحه: شورت‌کد <code>[salon]</code></p>
      <p>صفحه‌ی آپلود برای کاربران در فرانت: شورت‌کد <code>[salon_upload]</code> (فقط کاربران واردشده‌ی مجاز می‌بینند)</p>
      <p class="description">اگر از افزونه‌ی کش استفاده می‌کنی، صفحه‌ی <code>[salon_upload]</code> را از کش مستثنی کن.</p>
      <?php salon_media_dev_footer(); ?>
    </div>
    <?php
}

/* ============================================================
 *  شورت‌کد آپلود در فرانت
 * ============================================================ */
add_shortcode('salon_upload', function () {
    if (!is_user_logged_in()) {
        return '<div class="salon-media-note" dir="rtl">برای آپلود فیلم باید وارد حساب کاربری شوید. <a href="' .
            esc_url(wp_login_url(get_permalink() ?: home_url('/'))) . '">ورود</a></div>';
    }
    if (!salon_media_can_upload()) {
        return '<div class="salon-media-note" dir="rtl">شما اجازه‌ی آپلود فیلم ندارید.</div>';
    }
    salon_media_ensure_dir();
    salon_media_enqueue('front');
    return '<div id="salon-media-app" class="salon-media salon-media--front" dir="rtl" data-mode="front">در حال بارگذاری…</div>';
});
