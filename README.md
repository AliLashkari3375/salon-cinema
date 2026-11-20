# Salon Cinema

**English** | [فارسی](#فارسی)

A WordPress plugin for watching films together in sync: shared rooms, chat, synchronized playback and subtitles,
plus a film-library manager in the dashboard (chunked uploads, ffmpeg preparation, subtitles).

## ⚠️ License

This repository is published **for source viewing (portfolio) only** and is **All Rights Reserved**.
Copying, modifying, distributing, selling, or using this plugin on any site without the author's written
permission is **not allowed**. See [`LICENSE`](./LICENSE). Bundled fonts keep their own SIL OFL license.

## Features

- Rooms with a 5-character code, invite link, chat and synced play/pause/seek
- Films from your own library or from a direct link
- Subtitles: embedded tracks extracted to `.vtt`, `.vtt`/`.srt` upload (Windows-1256 handled), custom on-screen subtitle styling
- Dashboard page **Salon Cinema → Films**: drag-and-drop chunked upload (resumable), one-click preparation (mkv/avi/mov/ts → mp4 without re-encoding)
- Shortcodes: `[salon]` (the room) and `[salon_upload]` (front-end upload for roles you allow)
- No hard-coded tokens; all admin actions use WordPress capabilities and nonces

## Installation (authorized users only)

1. Upload the plugin folder to `wp-content/plugins/` (or zip it and use Plugins → Add New → Upload) and activate it.
2. The films folder is created automatically on activation (default `wp-content/uploads/salon-films`).
3. Open **Salon Cinema → Settings** to set the ffmpeg folder and which roles may upload.
4. Put `[salon]` on a page and open it.

See [`CHANGELOG.txt`](./CHANGELOG.txt) for version history.

## Contact for licensing

- Ali Lashkari — alilashkari020@gmail.com — https://alilashkari.ir/

---

<div dir="rtl">

<a name="فارسی"></a>

# سالن سینما (Salon Cinema)

[English](#salon-cinema) | **فارسی**

افزونه‌ی وردپرس برای تماشای هم‌زمان فیلم با دوستان: اتاق، چت، همگام‌سازی پخش و زیرنویس،
به‌اضافه‌ی مدیریت کتابخانه‌ی فیلم در پیشخوان (آپلود تکه‌تکه، آماده‌سازی با ffmpeg، زیرنویس).

## ⚠️ لایسنس

این ریپو فقط برای **نمایش کد (پورتفولیو)** منتشر شده و **All Rights Reserved** است.
کپی، ویرایش، توزیع، فروش یا استفاده از این پلاگین روی هر سایتی بدون اجازه‌ی کتبی نویسنده **مجاز نیست**.
فایل [`LICENSE`](./LICENSE) را ببین. فونت‌های همراه پلاگین لایسنس SIL OFL خودشان را دارند.

## ویژگی‌ها

- اتاق با کد پنج‌حرفی، لینک دعوت، چت و همگام‌سازی پخش/توقف/جلو-عقب
- فیلم از کتابخانه‌ی خودت یا لینک مستقیم
- زیرنویس: استخراج ترک‌های داخلی به `.vtt`، آپلود `.vtt`/`.srt` (کدگذاری Windows-1256 هم درست می‌شود)، تنظیم ظاهر زیرنویس روی صحنه
- صفحه‌ی **سالن سینما ← فیلم‌ها** در پیشخوان: آپلود تکه‌تکه با کشیدن و رها کردن (قابل ادامه)، آماده‌سازی با یک کلیک (mkv/avi/mov/ts به mp4 بدون انکود مجدد)
- شورت‌کدها: `[salon]` (اتاق) و `[salon_upload]` (آپلود از فرانت برای نقش‌های مجاز)
- هیچ توکن ثابتی وجود ندارد؛ همه‌ی اکشن‌های مدیریتی با دسترسی و nonce وردپرس کنترل می‌شوند

## نصب (فقط برای کاربران مجاز)

۱. پوشه‌ی پلاگین را در `wp-content/plugins/` بگذار (یا زیپ کن و از افزونه‌ها ← افزودن ← بارگذاری استفاده کن) و فعال کن.
۲. پوشه‌ی فیلم‌ها موقع فعال‌سازی خودکار ساخته می‌شود (پیش‌فرض `wp-content/uploads/salon-films`).
۳. از **سالن سینما ← تنظیمات** پوشه‌ی ffmpeg و نقش‌های مجاز برای آپلود را تنظیم کن.
۴. شورت‌کد `[salon]` را در یک صفحه بگذار و باز کن.

تاریخچه‌ی نسخه‌ها: [`CHANGELOG.txt`](./CHANGELOG.txt)

## تماس برای مجوز استفاده

- علی لشکری — alilashkari020@gmail.com — https://alilashkari.ir/

</div>
