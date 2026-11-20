(function () {
  'use strict';

  const API = SalonConfig.apiBase;
  const root = document.getElementById('salon-app');
  if (!root) return;

  /* ---------------- state ---------------- */
  let viewerId = localStorage.getItem('salon_viewer_id') || '';
  let viewerName = localStorage.getItem('salon_viewer_name') || '';
  let room = (location.hash || '').replace('#', '').toUpperCase();
  let clientToken = '';
  try {
    clientToken = localStorage.getItem('salon_client_token') || '';
    if (!clientToken) {
      const a = new Uint8Array(12);
      crypto.getRandomValues(a);
      clientToken = Array.from(a, b => b.toString(16).padStart(2, '0')).join('');
      localStorage.setItem('salon_client_token', clientToken);
    }
  } catch (e) { /* حالت خصوصی مرورگر */ }
  let rejoining = false;
  let lastPeopleSig = '';
  let lastChatId = 0;
  let pollTimer = null;
  let applyingRemote = false;      // برای جلوگیری از حلقه‌ی seek/ctl
  let applyingRemoteTimer = null;
  let pendingSeekTime = null;
  let lastLocalAction = 0;
  let currentSubUrl = null;
  let currentSubDelay = null;
  let currentSubRawVtt = null;
  let currentSubBlob = null;
  let currentSourceKey = null;
  let currentSource = null;
  let playFallbackUrl = null;
  let usedFallback = false;
  let localOnlySub = false; // زیرنویس فایل روی دستگاه؛ تا وقتی فعاله وضعیت اتاق نباید پاکش کند

  function randRoom() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    let s = '';
    for (let i = 0; i < 5; i++) s += chars[Math.floor(Math.random() * chars.length)];
    return s;
  }

  function api(path, params) {
    const url = new URL(API + path);
    Object.entries(params || {}).forEach(([k, v]) => v !== undefined && v !== null && url.searchParams.set(k, v));
    return fetch(url, { method: 'POST' }).then(r => r.json().then(j => ({ ok: r.ok, body: j })));
  }
  function apiGet(path, params) {
    const url = new URL(API + path);
    Object.entries(params || {}).forEach(([k, v]) => v !== undefined && v !== null && url.searchParams.set(k, v));
    return fetch(url).then(r => r.json().then(j => ({ ok: r.ok, body: j })));
  }

  /* ---------------- UI شل ---------------- */
  root.innerHTML = `
    <div class="salon">
      <header class="salon-top">
        <div class="salon-brand">سالن سینما</div>
        <div class="salon-room-info">
          <div class="salon-ticket" title="کد اتاق">
            <span class="salon-ticket-label">اتاق</span>
            <span class="salon-code" id="salon-code">—</span>
          </div>
          <button id="salon-invite" class="salon-btn">دعوت دوستان</button>
          <button id="salon-newcode" class="salon-btn ghost" hidden title="کد اتاق عوض می‌شود و لینک قبلی از کار می‌افتد">کد جدید</button>
        </div>
        <div class="salon-people" id="salon-people"></div>
      </header>

      <div class="salon-main">
        <div class="salon-stage">
          <video id="salon-video" playsinline controls controlsList="nofullscreen" preload="metadata"></video>
          <div class="salon-empty" id="salon-empty">
            <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <rect x="5" y="9" width="38" height="30" rx="4"/>
              <path d="M5 17h38M5 31h38M13 9v8M13 31v8M35 9v8M35 31v8"/>
              <path d="M21 21.5v5l4.5-2.5z" fill="currentColor"/>
            </svg>
            <p>هنوز فیلمی روی پرده نیست</p>
            <p>از تب «فیلم» یکی از فیلم‌های کتابخانه را انتخاب کن یا لینک مستقیمش را بده.</p>
          </div>
          <div class="salon-sub-overlay" id="salon-sub-overlay" hidden><span class="salon-sub-cue" id="salon-sub-cue" dir="auto"></span></div>
          <div class="salon-msg" id="salon-msg" hidden></div>
          <div class="salon-controls-bar" id="salon-controls-bar">
            <button id="salon-cc" class="salon-cc" title="زیرنویس (C)">CC</button>
            <button id="salon-fs" class="salon-cc" title="تمام‌صفحه با زیرنویس سفارشی">⛶</button>
          </div>
        </div>

        <aside class="salon-panel">
          <div class="salon-panel-in">
            <div class="salon-tabs" role="tablist">
              <button class="salon-tab active" data-tab="film" role="tab" aria-selected="true">فیلم</button>
              <button class="salon-tab" data-tab="sub" role="tab" aria-selected="false">زیرنویس</button>
              <button class="salon-tab" data-tab="chat" role="tab" aria-selected="false" id="salon-tab-chat">چت<span class="salon-dot" hidden></span></button>
            </div>

            <div class="salon-panes">
              <div class="salon-tabpane" data-pane="film">
                <div class="salon-field">
                  <label for="salon-local-list">از کتابخانه انتخاب کن</label>
                  <select id="salon-local-list"><option>در حال بارگذاری…</option></select>
                  <p id="salon-local-hint" class="salon-hint" hidden></p>
                </div>
                <div class="salon-or"><span>یا</span></div>
                <div class="salon-field">
                  <label for="salon-url">لینک مستقیم فیلم</label>
                  <div class="salon-row">
                    <input id="salon-url" type="text" placeholder="https://…">
                    <button id="salon-url-go" class="salon-btn">اضافه کن</button>
                  </div>
                  <p class="salon-hint" id="salon-url-status"></p>
                </div>
                <label class="salon-check">
                  <input type="checkbox" id="salon-lock">
                  <span class="salon-switch" aria-hidden="true"></span>
                  <span>کنترل پخش فقط با میزبان</span>
                </label>
              </div>

              <div class="salon-tabpane" data-pane="sub" hidden>
                <div class="salon-field" id="salon-local-subs"></div>
                <div class="salon-field">
                  <label for="salon-sub-url">لینک مستقیم زیرنویس (srt / vtt / ass)</label>
                  <div class="salon-row">
                    <input id="salon-sub-url" type="text" placeholder="https://…">
                    <button id="salon-sub-go" class="salon-btn">اضافه کن</button>
                  </div>
                </div>
                <div class="salon-field">
                  <label for="salon-sub-file">یا فایل روی دستگاه خودت (فقط برای خودت دیده می‌شود)</label>
                  <input type="file" id="salon-sub-file" accept=".srt,.vtt,.ass,.ssa">
                </div>
                <div class="salon-field" id="salon-sub-active" hidden>
                  <label>زیرنویس فعال: <span id="salon-sub-name"></span></label>
                  <div class="salon-row salon-stepper">
                    <button class="salon-btn small ghost" data-delay="-0.5">−۰٫۵</button>
                    <button class="salon-btn small ghost" data-delay="-0.1">−۰٫۱</button>
                    <span id="salon-sub-delay">۰</span>
                    <button class="salon-btn small ghost" data-delay="0.1">+۰٫۱</button>
                    <button class="salon-btn small ghost" data-delay="0.5">+۰٫۵</button>
                    <button class="salon-btn small ghost danger" id="salon-sub-clear">حذف زیرنویس</button>
                  </div>
                </div>
                <div class="salon-sub-style" id="salon-sub-style">
                  <div class="salon-sub-style-head">
                    <span>ظاهر زیرنویس</span>
                    <button type="button" class="salon-btn small ghost" id="salon-sub-reset">پیش‌فرض</button>
                  </div>
                  <div class="salon-style-grid">
                    <div class="salon-field">
                      <label for="salon-sub-pos">جایگاه روی فیلم (<span id="salon-sub-pos-val">۸٪</span>)</label>
                      <input type="range" id="salon-sub-pos" min="4" max="82" step="1" value="8">
                    </div>
                    <div class="salon-field">
                      <label for="salon-sub-size">اندازه فونت (<span id="salon-sub-size-val">۲۰px</span>)</label>
                      <input type="range" id="salon-sub-size" min="13" max="38" step="1" value="20">
                    </div>
                    <div class="salon-field">
                      <label for="salon-sub-font">فونت</label>
                      <select id="salon-sub-font">
                        <option value="sans">وزیرمتن (پیش‌فرض)</option>
                        <option value="display">لالزار</option>
                        <option value="tahoma">تاهوما (Tahoma)</option>
                        <option value="system">فونت سیستم</option>
                      </select>
                    </div>
                    <div class="salon-field">
                      <label for="salon-sub-bg">پس‌زمینه</label>
                      <select id="salon-sub-bg">
                        <option value="soft">کادر نیمه‌شفاف</option>
                        <option value="solid">کادر مشکی</option>
                        <option value="shadow">فقط سایه (بدون کادر)</option>
                      </select>
                    </div>
                  </div>
                  <div class="salon-field">
                    <label>رنگ متن</label>
                    <div class="salon-color-row">
                      <button type="button" class="salon-swatch" data-color="#ffffff" style="background:#ffffff" title="سفید"></button>
                      <button type="button" class="salon-swatch" data-color="#ffe066" style="background:#ffe066" title="زرد"></button>
                      <button type="button" class="salon-swatch" data-color="#dcaa4d" style="background:#dcaa4d" title="برنجی"></button>
                      <button type="button" class="salon-swatch" data-color="#7dd3fc" style="background:#7dd3fc" title="آبی روشن"></button>
                      <button type="button" class="salon-swatch" data-color="#86efac" style="background:#86efac" title="سبز روشن"></button>
                      <input type="color" id="salon-sub-color" value="#ffffff" title="انتخاب رنگ دلخواه">
                    </div>
                  </div>
                </div>
              </div>

              <div class="salon-tabpane" data-pane="chat" hidden>
                <div class="salon-chat-log" id="salon-chat-log"></div>
                <div class="salon-row">
                  <input id="salon-chat-input" type="text" placeholder="پیام…" autocomplete="off">
                  <button id="salon-chat-send" class="salon-btn">ارسال</button>
                </div>
              </div>
            </div>
          </div>
        </aside>
      </div>
    </div>
  `;

  const $ = (sel) => root.querySelector(sel);
  const video = $('#salon-video');
  let youAreHost = false;
  let locked = false;
  let localFilms = [];

  /* ---------------- تب‌ها ---------------- */
  root.querySelectorAll('.salon-tab').forEach(btn => {
    btn.addEventListener('click', () => {
      root.querySelectorAll('.salon-tab').forEach(b => {
        b.classList.toggle('active', b === btn);
        b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
      });
      root.querySelectorAll('.salon-tabpane').forEach(p => p.hidden = p.dataset.pane !== btn.dataset.tab);
      if (btn.dataset.tab === 'chat') {
        $('#salon-tab-chat .salon-dot').hidden = true;
        const log = $('#salon-chat-log');
        log.scrollTop = log.scrollHeight;
      }
    });
  });

  /* ---------------- نام و اتاق ---------------- */
  function ensureName() {
    if (viewerName) return Promise.resolve();
    let n = '';
    while (!n) n = (prompt('اسمت چیه؟') || '').trim();
    viewerName = n.slice(0, 28);
    localStorage.setItem('salon_viewer_name', viewerName);
    return Promise.resolve();
  }

  function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

  function handleKicked(text) {
    stopPolling();
    video.pause();
    video.removeAttribute('src');
    video.load();
    currentSourceKey = null;
    $('#salon-empty').hidden = false;
    $('#salon-empty').innerHTML = '<p></p>';
    $('#salon-empty p').textContent = text || 'میزبان تو را از این اتاق حذف کرد.';
    showMsg('');
    const panel = root.querySelector('.salon-panel');
    panel.style.pointerEvents = 'none';
    panel.style.opacity = '.5';
  }

  function switchRoom(code) {
    if (!code || code === room) return;
    room = code;
    try { history.replaceState(null, '', '#' + code); } catch (e) { location.hash = code; }
    $('#salon-code').textContent = code;
    lastPeopleSig = '';
  }

  function join(depth) {
    depth = depth || 0;
    if (!room) { room = randRoom(); location.hash = room; }
    return ensureName().then(() => api('/join', { room, name: viewerName, viewer: viewerId, token: clientToken })).then(({ ok, body }) => {
      if (!ok) {
        const msg = (body && body.message) || 'اتاق پیدا نشد یا خطایی رخ داد';
        if (body && (body.code === 'kicked' || body.code === 'room_closed')) handleKicked(msg);
        else alert(msg);
        return;
      }
      if (body.closed && body.moved_to) { // کد اتاق عوض شده و ما جزو حاضران بودیم
        if (depth < 3) { switchRoom(body.moved_to); return join(depth + 1); }
        return;
      }
      viewerId = body.you.id;
      localStorage.setItem('salon_viewer_id', viewerId);
      $('#salon-code').textContent = room;
      applyState(body);
      startPolling();
    });
  }

  let lastRejoinAt = 0;
  function rejoin() {
    if (rejoining || Date.now() - lastRejoinAt < 2000) return; // جلوی حلقه‌ی تند اگر سرور مدام «حاضر نیستی» بگوید
    lastRejoinAt = Date.now();
    rejoining = true;
    api('/join', { room, name: viewerName, viewer: viewerId, token: clientToken }).then(({ ok, body }) => {
      rejoining = false;
      if (ok && body.you) { viewerId = body.you.id; localStorage.setItem('salon_viewer_id', viewerId); applyState(body); }
      else if (body && body.code === 'kicked') handleKicked(body.message);
      else if (body && body.closed && body.moved_to) switchRoom(body.moved_to);
      else if (body && body.code === 'room_closed') handleKicked(body.message);
    }).catch(() => { rejoining = false; });
  }

  $('#salon-invite').addEventListener('click', () => {
    const btn = $('#salon-invite');
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(location.href).then(() => {
        btn.textContent = 'کپی شد!';
        setTimeout(() => btn.textContent = 'دعوت دوستان', 1500);
      }).catch(() => { prompt('لینک دعوت را کپی کن:', location.href); });
    } else {
      prompt('لینک دعوت را کپی کن:', location.href);
    }
  });

  /* ---------------- پولینگ وضعیت ---------------- */
  function startPolling() {
    poll();
    pollTimer = setInterval(poll, 1200);
  }
  function poll() {
    apiGet('/state', { room, viewer: viewerId, chat_after: lastChatId }).then(({ ok, body }) => {
      if (!ok) return;
      if (body.closed) { // کد اتاق عوض شده
        if (body.moved_to) switchRoom(body.moved_to);
        else handleKicked('این اتاق بسته شده یا کدش عوض شده. لینک جدید را از میزبان بگیر.');
        return;
      }
      applyState(body);
    }).catch(() => {});
  }

  function applyState(s) {
    if (!s.you.present) { // از اتاق افتاده‌ایم (کیک شده یا مدتی غیرفعال بوده‌ایم)
      if (s.you.kicked) handleKicked(); else rejoin();
      return;
    }
    youAreHost = s.you.host;
    $('#salon-newcode').hidden = !youAreHost;
    locked = s.lock;
    $('#salon-lock').checked = locked;
    $('#salon-lock').disabled = !youAreHost;

    renderPeople(s.people);
    renderChat(s.chat);
    applySource(s.source, s.play, s.play_fallback);
    applySub(s.sub);
    applyPlayback(s.paused, s.time);

    root.querySelectorAll('[data-tab]').forEach(() => {});
  }

  function hueOf(name) {
    let h = 0;
    for (const c of String(name)) h = (h * 31 + c.codePointAt(0)) >>> 0;
    return h % 360;
  }

  function renderPeople(people) {
    const sig = JSON.stringify(people) + '|' + youAreHost;
    if (sig === lastPeopleSig) return; // بدون تغییر: دوباره نساز تا کلیک روی دکمه‌ها گم نشود
    lastPeopleSig = sig;
    $('#salon-people').innerHTML = people.map(p => {
      const initial = Array.from(String(p.name).trim())[0] || '?';
      const kick = (youAreHost && !p.me && p.id)
        ? `<button class="salon-kick" data-id="${escapeAttr(p.id)}" data-name="${escapeAttr(p.name)}" title="حذف از اتاق" aria-label="حذف ${escapeAttr(p.name)} از اتاق">×</button>`
        : '';
      return `<span class="salon-person${p.host ? ' host' : ''}">` +
        `<i class="salon-avatar" style="--h:${hueOf(p.name)}">${escapeHtml(initial)}</i>` +
        `<span>${escapeHtml(p.name)}</span>` +
        (p.host ? '<i class="salon-crown" title="میزبان">★</i>' : '') +
        kick + `</span>`;
    }).join('');
  }

  $('#salon-people').addEventListener('click', (e) => {
    const btn = e.target.closest('.salon-kick');
    if (!btn) return;
    if (!confirm('«' + btn.dataset.name + '» از اتاق حذف شود؟\n(با رفرش هم نمی‌تواند برگردد؛ برای اطمینان کامل کد اتاق را هم عوض کن.)')) return;
    api('/kick', { room, viewer: viewerId, target: btn.dataset.id }).then(({ ok, body }) => {
      if (!ok) showMsg((body && body.message) || 'حذف کاربر ناموفق بود');
      else poll();
    }).catch(() => showMsg('ارتباط با سرور برقرار نشد'));
  });

  $('#salon-newcode').addEventListener('click', () => {
    if (!confirm('کد اتاق عوض شود؟\nلینک قبلی از کار می‌افتد؛ فقط کسانی که الان داخل اتاق هستند خودکار به کد جدید می‌روند.')) return;
    api('/newcode', { room, viewer: viewerId }).then(({ ok, body }) => {
      if (!ok) { showMsg((body && body.message) || 'عوض کردن کد ناموفق بود'); return; }
      switchRoom(body.room);
      const btn = $('#salon-invite');
      const done = () => { btn.textContent = 'کد جدید — لینک کپی شد'; setTimeout(() => btn.textContent = 'دعوت دوستان', 3000); };
      navigator.clipboard.writeText(location.href).then(done).catch(() => {
        btn.textContent = 'کد جدید: ' + body.room; setTimeout(() => btn.textContent = 'دعوت دوستان', 3000);
      });
    }).catch(() => showMsg('ارتباط با سرور برقرار نشد'));
  });

  let chatReady = false;
  const seenChatIds = new Set();
  function renderChat(newMsgs) {
    const firstRun = !chatReady;
    chatReady = true;
    if (!newMsgs.length) return;
    const log = $('#salon-chat-log');
    let unread = false;
    newMsgs.forEach(m => {
      if (seenChatIds.has(m.id)) return;
      seenChatIds.add(m.id);
      lastChatId = Math.max(lastChatId, m.id);
      const row = document.createElement('div');
      row.className = 'salon-chat-msg' + (m.me ? ' me' : '');
      row.innerHTML = (m.me ? '' : `<b class="salon-chat-name">${escapeHtml(m.name)}</b>`) + `<span>${escapeHtml(m.text)}</span>`;
      log.appendChild(row);
      if (!m.me) unread = true;
    });
    log.scrollTop = log.scrollHeight;
    if (unread && !firstRun && $('[data-pane="chat"]').hidden) $('#salon-tab-chat .salon-dot').hidden = false;
  }

  function showMsg(text) {
    const el = $('#salon-msg');
    el.textContent = text || '';
    el.hidden = !text;
  }

  function setApplyingRemote() {
    applyingRemote = true;
    clearTimeout(applyingRemoteTimer);
    applyingRemoteTimer = setTimeout(() => { applyingRemote = false; }, 900);
  }

  function applySource(source, playUrl, playFallback) {
    if (!source || !playUrl) {
      $('#salon-empty').hidden = false;
      if (currentSourceKey !== null) { video.removeAttribute('src'); video.load(); }
      currentSourceKey = null;
      currentSource = null;
      playFallbackUrl = null;
      usedFallback = false;
      pendingSeekTime = null;
      renderLocalSubsFor(null);
      return;
    }
    const key = source.type + '|' + (source.id || source.url); // آدرس پخش بیرونی هر بار امضای تازه دارد؛ مقایسه روی خود منبع
    if (key === currentSourceKey) {
      playFallbackUrl = playFallback || playFallbackUrl;
      return;
    }
    currentSourceKey = key;
    currentSource = source;
    playFallbackUrl = playFallback || null;
    usedFallback = false;
    pendingSeekTime = null;
    $('#salon-empty').hidden = true;
    showMsg('');
    video.src = playUrl;
    video.load();
    renderLocalSubsFor(source);
  }

  video.addEventListener('loadedmetadata', () => {
    if (pendingSeekTime !== null) {
      setApplyingRemote();
      video.currentTime = pendingSeekTime;
      pendingSeekTime = null;
    }
  });
  video.addEventListener('loadeddata', () => showMsg(''));
  video.addEventListener('error', () => {
    const rawSrc = video.getAttribute('src');
    if (!rawSrc) return;
    const src = video.currentSrc || rawSrc;
    // اگر آدرس مستقیم فایل محلی خطا داد، خودکار از مسیر استریم امن داخلی استفاده کن
    if (!usedFallback && playFallbackUrl && src !== playFallbackUrl) {
      usedFallback = true;
      showMsg('');
      video.src = playFallbackUrl;
      video.load();
      return;
    }
    const code = video.error ? video.error.code : 0;
    fetch(src, { method: 'HEAD' }).then(r => {
      if (!r.ok) {
        let where = '';
        try { where = decodeURIComponent(new URL(src, location.href).pathname); } catch (e) { /* آدرس نامعتبر */ }
        showMsg('فایل روی سرور باز نشد (HTTP ' + r.status + '). آدرس/مسیر فیلم را بررسی کن.' + (where ? ' [' + where + ']' : ''));
      }
      else if (code === 3 || code === 4) showMsg('مرورگر این ویدیو را پخش نمی‌کند. باید H.264 (۸ بیت) + صدای AAC داخل mp4 باشد.');
      else showMsg('خطا در پخش ویدیو (کد ' + code + ')');
    }).catch(() => showMsg('خطا در پخش ویدیو (کد ' + code + ')'));
  });

  function applyPlayback(paused, time) {
    if (!video.getAttribute('src')) return;
    // تا ۲٫۵ ثانیه بعد از کار خودمان، پاسخ poll (که ممکن است قدیمی‌تر باشد) را اعمال نکن
    if (Date.now() - lastLocalAction < 2500) return;
    let changed = false;
    if (video.readyState < 1) {
      pendingSeekTime = time;
    } else {
      const drift = Math.abs(video.currentTime - time);
      if (drift > 1.2) { video.currentTime = time; changed = true; }
    }
    if (paused && !video.paused) { video.pause(); changed = true; }
    if (!paused && video.paused) { video.play().catch(() => {}); changed = true; }
    if (changed) setApplyingRemote();
  }

  function sendCtl(a) {
    if (locked && !youAreHost) return;
    lastLocalAction = Date.now();
    api('/ctl', { room, viewer: viewerId, a, time: video.currentTime });
  }
  video.addEventListener('play', () => { if (!applyingRemote) sendCtl('play'); });
  video.addEventListener('pause', () => { if (!applyingRemote) sendCtl('pause'); });
  video.addEventListener('seeked', () => { if (!applyingRemote) sendCtl('seek'); });

  /* ---------------- انتخاب فیلم: لینک بیرونی ---------------- */
  function probeAndSetSource(url) {
    const status = $('#salon-url-status');
    status.textContent = 'در حال بررسی لینک…';
    const probeQs = new URLSearchParams({
      salon_probe: '1',
      _wpnonce: SalonConfig.probeNonce || '',
      room: room || '',
      viewer: viewerId || '',
      u: url,
    });
    fetch((SalonConfig.homeUrl || (location.origin + '/')) + '?' + probeQs.toString())
      .then(r => r.json())
      .then(info => {
        if (!info.ok) {
          const msgs = {
            auth: 'نشست صفحه منقضی شده؛ یک بار صفحه را رفرش کن.',
            'bad-url': 'آدرس نامعتبر است',
            unreachable: 'به این آدرس نمی‌رسیم',
            forbidden: 'سرور مقصد اجازه‌ی دسترسی به این لینک را نمی‌دهد (۴۰۳)',
            gone: 'فایل پیدا نشد (۴۰۴)',
            'bad-status': 'سرور جواب درست نداد',
            'not-a-file': 'این آدرس یک صفحه‌ی وب است، نه فایل ویدیو',
          };
          status.textContent = msgs[info.code] || 'خطای نامشخص';
          return;
        }
        status.textContent = info.wideSupport ? 'آماده است ✓' :
          'این فایل ' + info.container + ' است؛ ممکن است در همه‌ی مرورگرها پخش نشود.';
        let label = 'ویدیو';
        try { label = decodeURIComponent(url.split('/').pop().split('?')[0] || 'ویدیو').slice(0, 90); } catch (e) {}
        api('/source', { room, viewer: viewerId, type: 'external', url, label }).then(({ ok, body }) => {
          if (!ok) status.textContent = (body && body.message) || 'تنظیم فیلم ناموفق بود';
          else poll();
        }).catch(() => { status.textContent = 'ارتباط با سرور برقرار نشد'; });
      })
      .catch(() => status.textContent = 'خطا در بررسی لینک');
  }
  $('#salon-url-go').addEventListener('click', () => {
    const url = $('#salon-url').value.trim();
    if (url) probeAndSetSource(url);
  });

  /* ---------------- انتخاب فیلم: محلی ---------------- */
  function loadLocalFilms() {
    apiGet('/films', {}).then(({ ok, body }) => {
      if (!ok || !Array.isArray(body)) return;
      localFilms = body;
      const sel = $('#salon-local-list');
      const pending = body.filter(f => f.ready === false).length;
      sel.innerHTML = (body.length
        ? '<option value="">— انتخاب کن —</option>'
        : '<option value="">هیچ فیلمی در پوشه‌ی فیلم‌ها پیدا نشد</option>') +
        body.map(f => f.ready === false
          ? `<option value="" disabled>${escapeHtml(f.label)} (${escapeHtml(f.ext)} — نیاز به آماده‌سازی)</option>`
          : `<option value="${escapeAttr(f.id)}">${escapeHtml(f.label)}</option>`).join('');
      const hint = $('#salon-local-hint');
      if (hint) {
        hint.hidden = !pending;
        hint.textContent = pending ? pending + ' فیلم mkv/avi هنوز mp4 ندارد؛ از صفحه‌ی «سالن سینما ← فیلم‌ها» آماده‌اش کن تا در لیست فعال شود.' : '';
      }
      renderLocalSubsFor(currentSource);
    }).catch(() => {
      $('#salon-local-list').innerHTML = '<option value="">خطا در خواندن لیست فیلم‌ها</option>';
    });
  }
  $('#salon-local-list').addEventListener('change', (e) => {
    const id = e.target.value;
    if (!id) return;
    const film = localFilms.find(f => f.id === id);
    if (!film) return;
    showMsg('');
    lastLocalAction = 0;
    api('/source', { room, viewer: viewerId, type: 'local', id, label: film.label }).then(({ ok, body }) => {
      if (!ok) { showMsg((body && body.message) || 'انتخاب فیلم ناموفق بود'); return; }
      poll(); // بدون انتظار برای poll بعدی
    }).catch(() => showMsg('ارتباط با سرور برقرار نشد'));
    renderLocalSubs(film);
  });

  function renderLocalSubsFor(source) {
    const box = $('#salon-local-subs');
    if (!source) { box.innerHTML = ''; return; }
    if (source.type === 'local') {
      const film = localFilms.find(f => (source.id && f.id === source.id) || (source.url && f.url === source.url));
      if (film) renderLocalSubs(film);
      return;
    }
    box.innerHTML = '<label>زیرنویس داخلی این لینک</label>' +
      '<div class="salon-row"><button type="button" class="salon-btn small ghost" id="salon-ext-extract">جستجو و استخراج زیرنویس از لینک</button></div>' +
      '<p class="salon-hint" id="salon-ext-sub-msg"></p>' +
      '<div class="salon-row" id="salon-ext-sub-list"></div>';
    const btn = $('#salon-ext-extract');
    const msg = $('#salon-ext-sub-msg');
    const list = $('#salon-ext-sub-list');
    btn.addEventListener('click', () => {
      btn.disabled = true;
      msg.textContent = 'در حال بررسی و استخراج زیرنویس از لینک… (ممکن است چند ثانیه طول بکشد)';
      list.innerHTML = '';
      api('/extract-sub', { room, viewer: viewerId }).then(({ ok, body }) => {
        btn.disabled = false;
        if (!ok) { msg.textContent = (body && body.message) || 'استخراج زیرنویس ناموفق بود'; return; }
        const subs = body.subs || [];
        if (!subs.length) { msg.textContent = body.message || 'زیرنویس متنی داخلی پیدا نشد.'; return; }
        msg.textContent = 'روی زبان موردنظر کلیک کن:';
        list.innerHTML = subs.map(s => `<button type="button" class="salon-btn small ghost salon-ext-pick" data-file="${escapeAttr(s.file)}" data-lang="${escapeAttr(s.lang)}" data-label="${escapeAttr(s.label)}">${escapeHtml(s.label)}</button>`).join(' ');
        list.querySelectorAll('.salon-ext-pick').forEach(b => {
          b.addEventListener('click', () => {
            api('/sub', {
              room, viewer: viewerId, ext_sub_file: b.dataset.file,
              lang: b.dataset.lang, label: b.dataset.label,
            }).then(({ ok: ok2, body: b2 }) => {
              if (!ok2) showMsg((b2 && b2.message) || 'اعمال زیرنویس ناموفق بود');
              else poll();
            }).catch(() => {});
          });
        });
      }).catch(() => {
        btn.disabled = false;
        msg.textContent = 'ارتباط با سرور برقرار نشد';
      });
    });
  }

  function renderLocalSubs(film) {
    const box = $('#salon-local-subs');
    if (!film || !film.subs.length) { box.innerHTML = '<p class="salon-hint">این فیلم زیرنویس آماده ندارد.</p>'; return; }
    box.innerHTML = '<label>زیرنویس‌های آماده‌ی این فیلم</label>' +
      film.subs.map(s => `<button class="salon-btn small ghost salon-sub-pick" data-film="${escapeAttr(film.id)}" data-file="${escapeAttr(s.file)}" data-lang="${escapeAttr(s.lang)}" data-label="${escapeAttr(s.label)}">${escapeHtml(s.label)}</button>`).join(' ');
    box.querySelectorAll('.salon-sub-pick').forEach(b => {
      b.addEventListener('click', () => {
        api('/sub', {
          room, viewer: viewerId, film_id: b.dataset.film, sub_file: b.dataset.file,
          lang: b.dataset.lang, label: b.dataset.label,
        }).then(({ ok, body }) => {
          if (!ok) showMsg((body && body.message) || 'زیرنویس اعمال نشد');
          else poll();
        }).catch(() => {});
      });
    });
  }

  /* ---------------- زیرنویس: لینک بیرونی و فایل محلی ---------------- */
  $('#salon-sub-go').addEventListener('click', () => {
    const url = $('#salon-sub-url').value.trim();
    if (!url) return;
    let label = 'زیرنویس';
    try { label = decodeURIComponent(url.split('/').pop().split('?')[0] || 'زیرنویس').slice(0, 90); } catch (e) {}
    api('/sub', { room, viewer: viewerId, url, label, lang: 'xx' }).then(({ ok, body }) => {
      if (!ok) showMsg((body && body.message) || 'اعمال زیرنویس ناموفق بود');
      else poll();
    }).catch(() => showMsg('ارتباط با سرور برقرار نشد'));
  });

  $('#salon-sub-file').addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (!file) return;
    readSubtitleFile(file).then(vttText => {
      lastLocalVttRaw = vttText;
      applyLocalVttBlob(vttText, file.name);
    });
  });

  $('#salon-sub-clear').addEventListener('click', () => {
    if (localOnlySub) {
      localOnlySub = false;
      removeTracks();
      $('#salon-sub-active').hidden = true;
      return;
    }
    api('/sub', { room, viewer: viewerId, clear: 1 }).then(({ ok, body }) => {
      if (!ok) showMsg((body && body.message) || 'حذف زیرنویس ناموفق بود');
      else poll();
    }).catch(() => showMsg('ارتباط با سرور برقرار نشد'));
  });

  root.querySelectorAll('[data-delay]').forEach(btn => {
    btn.addEventListener('click', () => {
      const cur = parseFloat($('#salon-sub-delay').dataset.val || '0');
      const next = Math.round((cur + parseFloat(btn.dataset.delay)) * 10) / 10;
      if (localOnlySub) {
        // فقط محلی: خودمان دوباره رسم می‌کنیم، سرور خبر ندارد
        $('#salon-sub-delay').dataset.val = next;
        loadLocalOnlyDelay(next);
        return;
      }
      api('/sub-delay', { room, viewer: viewerId, delay: next }).then(({ ok, body }) => {
        if (!ok) showMsg((body && body.message) || 'تغییر تأخیر ناموفق بود');
        else poll();
      }).catch(() => showMsg('ارتباط با سرور برقرار نشد'));
    });
  });

  let lastLocalVttRaw = null;
  let subVisible = true;
  const DEFAULT_SUB_STYLE = { pos: 8, size: 20, font: 'sans', bg: 'soft', color: '#ffffff' };
  const SUB_FONTS = {
    sans: "'Salon Sans', Vazirmatn, Tahoma, 'Segoe UI', sans-serif",
    display: "'Salon Display', 'Salon Sans', Vazirmatn, sans-serif",
    tahoma: "Tahoma, Arial, sans-serif",
    system: "system-ui, -apple-system, 'Segoe UI', sans-serif",
  };
  let subStyle = Object.assign({}, DEFAULT_SUB_STYLE);
  try {
    const saved = JSON.parse(localStorage.getItem('salon_sub_style') || 'null');
    if (saved && typeof saved === 'object') subStyle = Object.assign({}, DEFAULT_SUB_STYLE, saved);
  } catch (e) {}

  function toFaDigits(n) { return String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]); }

  function applySubStyle() {
    const ov = $('#salon-sub-overlay');
    const cue = $('#salon-sub-cue');
    if (!ov || !cue) return;
    ov.style.bottom = subStyle.pos + '%';
    ov.style.setProperty('--sub-size', subStyle.size + 'px');
    cue.style.fontFamily = SUB_FONTS[subStyle.font] || SUB_FONTS.sans;
    cue.style.color = subStyle.color || '#ffffff';
    if (subStyle.bg === 'solid') {
      cue.style.background = 'rgba(0, 0, 0, 0.92)';
      cue.style.textShadow = 'none';
    } else if (subStyle.bg === 'shadow') {
      cue.style.background = 'transparent';
      cue.style.textShadow = '0 2px 4px rgba(0,0,0,.95), 0 0 8px rgba(0,0,0,.9), 1px 1px 0 #000, -1px -1px 0 #000';
    } else {
      cue.style.background = 'rgba(10, 5, 8, 0.72)';
      cue.style.textShadow = '0 1px 3px rgba(0,0,0,.9)';
    }
    let cueStyle = document.getElementById('salon-cue-css');
    if (!cueStyle) {
      cueStyle = document.createElement('style');
      cueStyle.id = 'salon-cue-css';
      document.head.appendChild(cueStyle);
    }
    const bgCss = subStyle.bg === 'solid' ? 'rgba(0,0,0,0.92)' : (subStyle.bg === 'shadow' ? 'transparent' : 'rgba(10,5,8,0.72)');
    cueStyle.textContent = `#salon-video::cue { color: ${cue.style.color}; font-family: ${cue.style.fontFamily}; background-color: ${bgCss}; }`;
    $('#salon-sub-pos').value = subStyle.pos;
    $('#salon-sub-pos-val').textContent = toFaDigits(subStyle.pos) + '٪';
    $('#salon-sub-size').value = subStyle.size;
    $('#salon-sub-size-val').textContent = toFaDigits(subStyle.size) + 'px';
    $('#salon-sub-font').value = subStyle.font;
    $('#salon-sub-bg').value = subStyle.bg;
    $('#salon-sub-color').value = subStyle.color;
    root.querySelectorAll('.salon-swatch').forEach(b => {
      b.classList.toggle('active', b.dataset.color.toLowerCase() === String(subStyle.color).toLowerCase());
    });
  }

  function saveSubStyle() {
    applySubStyle();
    try { localStorage.setItem('salon_sub_style', JSON.stringify(subStyle)); } catch (e) {}
  }

  function renderActiveCues() {
    const ov = $('#salon-sub-overlay');
    const cue = $('#salon-sub-cue');
    const t = video.textTracks[0];
    if (!ov || !cue || !t || !subVisible || document.fullscreenElement === video) {
      if (ov) ov.hidden = true;
      return;
    }
    const cues = t.activeCues;
    if (!cues || !cues.length) {
      ov.hidden = true;
      cue.textContent = '';
      return;
    }
    const lines = [];
    for (let i = 0; i < cues.length; i++) {
      const txt = String(cues[i].text || '').replace(/<[^>]+>/g, '').trim();
      if (txt) lines.push(txt);
    }
    if (!lines.length) {
      ov.hidden = true;
      cue.textContent = '';
      return;
    }
    cue.textContent = lines.join('\n');
    ov.hidden = false;
  }

  function syncTrackMode() {
    const t = video.textTracks[0];
    if (!t) { $('#salon-sub-overlay').hidden = true; return; }
    if (!subVisible) {
      t.mode = 'disabled';
      $('#salon-sub-overlay').hidden = true;
    } else if (document.fullscreenElement === video) {
      t.mode = 'showing';
      $('#salon-sub-overlay').hidden = true;
    } else {
      t.mode = 'hidden';
      renderActiveCues();
    }
    $('#salon-cc').classList.toggle('active', subVisible);
  }

  function attachTrack(vttText) {
    removeTracks();
    const blob = new Blob([vttText], { type: 'text/vtt' });
    currentSubBlob = URL.createObjectURL(blob);
    const track = document.createElement('track');
    track.kind = 'subtitles';
    track.src = currentSubBlob;
    track.default = true;
    track.addEventListener('load', syncTrackMode);
    video.appendChild(track);
    if (track.track) {
      track.track.addEventListener('cuechange', renderActiveCues);
    }
    setTimeout(() => {
      if (video.textTracks[0]) {
        video.textTracks[0].oncuechange = renderActiveCues;
        syncTrackMode();
      }
    }, 40);
  }

  $('#salon-sub-pos').addEventListener('input', (e) => { subStyle.pos = Number(e.target.value); saveSubStyle(); });
  $('#salon-sub-size').addEventListener('input', (e) => { subStyle.size = Number(e.target.value); saveSubStyle(); });
  $('#salon-sub-font').addEventListener('change', (e) => { subStyle.font = e.target.value; saveSubStyle(); });
  $('#salon-sub-bg').addEventListener('change', (e) => { subStyle.bg = e.target.value; saveSubStyle(); });
  $('#salon-sub-color').addEventListener('input', (e) => { subStyle.color = e.target.value; saveSubStyle(); });
  root.querySelectorAll('.salon-swatch').forEach(b => {
    b.addEventListener('click', () => { subStyle.color = b.dataset.color; saveSubStyle(); });
  });
  $('#salon-sub-reset').addEventListener('click', () => {
    subStyle = Object.assign({}, DEFAULT_SUB_STYLE);
    saveSubStyle();
  });
  document.addEventListener('fullscreenchange', () => {
    if (document.fullscreenElement === video) {
      const st = root.querySelector('.salon-stage');
      if (st && st.requestFullscreen) {
        st.requestFullscreen().catch(() => {
          document.exitFullscreen().then(() => st.requestFullscreen()).catch(() => {});
        });
      }
    }
    syncTrackMode();
  });
  $('#salon-fs').addEventListener('click', () => {
    const st = root.querySelector('.salon-stage');
    if (!st) return;
    if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
    else if (st.requestFullscreen) st.requestFullscreen().catch(() => {});
  });
  applySubStyle();

  function loadLocalOnlyDelay(delay) {
    if (!lastLocalVttRaw) return;
    const shifted = shiftVtt(lastLocalVttRaw, delay);
    attachTrack(shifted);
    $('#salon-sub-delay').textContent = fmtDelay(delay);
  }

  /* ---------------- اعمال زیرنویس روی <video> ---------------- */
  // نمایش تأخیر با رقم فارسی: +۰٫۵ / −۱٫۲ / ۰
  function fmtDelay(d) {
    d = Math.round((Number(d) || 0) * 10) / 10;
    const txt = (d > 0 ? '+' : d < 0 ? '−' : '') + String(Math.abs(d)).replace('.', '٫');
    return txt.replace(/\d/g, x => '۰۱۲۳۴۵۶۷۸۹'[x]);
  }

  function applySub(sub) {
    if (localOnlySub) return; // زیرنویس محلی کاربر را تا کنسل‌شدن دستی نگه دار
    $('#salon-sub-active').hidden = !sub;
    if (!sub) {
      removeTracks();
      currentSubUrl = null;
      currentSubDelay = null;
      currentSubRawVtt = null;
      return;
    }
    $('#salon-sub-name').textContent = sub.label;
    $('#salon-sub-delay').textContent = fmtDelay(sub.delay);
    $('#salon-sub-delay').dataset.val = sub.delay;

    const d = Number(sub.delay) || 0;
    if (sub.url === currentSubUrl) {
      if (currentSubRawVtt !== null && d !== currentSubDelay) {
        currentSubDelay = d;
        applyLocalVttBlob(currentSubRawVtt, null, d);
      }
      return;
    }
    currentSubUrl = sub.url;
    currentSubDelay = d;
    currentSubRawVtt = null;
    loadAndApplyVtt(sub.url, d, sub.fallback);
  }

  function removeTracks() {
    root.querySelectorAll('#salon-video track').forEach(t => t.remove());
    if (currentSubBlob) { URL.revokeObjectURL(currentSubBlob); currentSubBlob = null; }
    const ov = $('#salon-sub-overlay');
    if (ov) ov.hidden = true;
  }

  function loadAndApplyVtt(url, delay, fallbackUrl) {
    const fetchBuf = (u) => fetch(u).then(r => {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.arrayBuffer();
    });
    fetchBuf(url).catch(err => {
      if (fallbackUrl && fallbackUrl !== url) return fetchBuf(fallbackUrl);
      throw err;
    }).then(buf => {
      showMsg('');
      const text = decodeSubtitleBytes(buf);
      const vtt = toVtt(text);
      currentSubRawVtt = vtt;
      applyLocalVttBlob(vtt, null, currentSubDelay !== null ? currentSubDelay : delay);
    }).catch(() => { showMsg('بارگذاری فایل زیرنویس ناموفق بود'); });
  }

  function applyLocalVttBlob(vttText, labelIfNew, delay) {
    const shifted = shiftVtt(vttText, delay || 0);
    attachTrack(shifted);

    if (labelIfNew) {
      // زیرنویس محلی (فایل روی دستگاه) فقط برای خود همین کاربر اعمال می‌شود؛
      // به بقیه‌ی اتاق فرستاده نمی‌شود چون فایل روی سرور نیست.
      localOnlySub = true;
      $('#salon-sub-active').hidden = false;
      $('#salon-sub-name').textContent = labelIfNew.slice(0, 90) + ' (فقط برای خودم)';
      $('#salon-sub-delay').textContent = '۰';
      $('#salon-sub-delay').dataset.val = '0';
    }
  }

  $('#salon-cc').addEventListener('click', () => {
    subVisible = !subVisible;
    syncTrackMode();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'c' || e.key === 'C') {
      if (document.activeElement.tagName === 'INPUT') return;
      $('#salon-cc').click();
    }
  });

  /* ---------------- قفل میزبان ---------------- */
  $('#salon-lock').addEventListener('change', (e) => {
    api('/lock', { room, viewer: viewerId, on: e.target.checked ? 1 : 0 }).then(({ ok, body }) => {
      if (!ok) { e.target.checked = !e.target.checked; showMsg((body && body.message) || 'تغییر قفل ناموفق بود'); }
    }).catch(() => { e.target.checked = !e.target.checked; });
  });

  /* ---------------- چت ---------------- */
  function sendChat() {
    const input = $('#salon-chat-input');
    const text = input.value.trim();
    if (!text) return;
    input.value = '';
    api('/chat', { room, viewer: viewerId, text }).then(({ ok, body }) => {
      if (!ok) showMsg((body && body.message) || 'ارسال پیام ناموفق بود');
      else poll();
    }).catch(() => showMsg('ارتباط با سرور برقرار نشد'));
  }
  $('#salon-chat-send').addEventListener('click', sendChat);
  $('#salon-chat-input').addEventListener('keydown', (e) => { if (e.key === 'Enter') sendChat(); });

  /* ---------------- کمکی‌های زیرنویس: تشخیص انکودینگ و تبدیل srt/ass ---------------- */
  function decodeSubtitleBytes(buf) {
    const bytes = new Uint8Array(buf);
    // BOM UTF-8
    if (bytes[0] === 0xEF && bytes[1] === 0xBB && bytes[2] === 0xBF) {
      return new TextDecoder('utf-8').decode(bytes.slice(3));
    }
    try {
      const utf8 = new TextDecoder('utf-8', { fatal: true }).decode(bytes);
      return utf8;
    } catch (e) {
      // احتمالاً ویندوز-۱۲۵۶ (فارسی/عربی)
      let text = new TextDecoder('windows-1256').decode(bytes);
      text = text.replace(/\u064A/g, '\u06CC').replace(/\u0643/g, '\u06A9'); // ي/ك عربی → ی/ک فارسی
      return text;
    }
  }

  function toVtt(text) {
    text = text.replace(/\r/g, '');
    if (/^WEBVTT/.test(text.trim())) return text;
    if (/^\uFEFF?\d+\s*\n\d{2}:\d{2}:\d{2},\d{3}/.test(text.trim())) {
      // srt → vtt: کاما را به نقطه تبدیل کن و هدر بگذار
      return 'WEBVTT\n\n' + text.replace(/(\d{2}:\d{2}:\d{2}),(\d{3})/g, '$1.$2');
    }
    if (/^\[Script Info\]/im.test(text)) return assToVtt(text);
    return 'WEBVTT\n\n' + text; // تلاش آخر
  }

  function assToVtt(text) {
    const lines = text.split('\n');
    let out = 'WEBVTT\n\n';
    const fmtMatch = text.match(/^Format:\s*(.+)$/im);
    const fields = fmtMatch ? fmtMatch[1].split(',').map(s => s.trim()) : [];
    const idxStart = fields.indexOf('Start');
    const idxEnd = fields.indexOf('End');
    const idxText = fields.indexOf('Text');
    const assTime = (t) => {
      const m = t.trim().match(/(\d+):(\d{2}):(\d{2})[.:](\d{2})/);
      if (!m) return '00:00:00.000';
      return `${m[1].padStart(2, '0')}:${m[2]}:${m[3]}.${m[4]}0`;
    };
    lines.forEach(line => {
      if (!line.startsWith('Dialogue:')) return;
      const parts = line.slice(9).split(',');
      if (idxStart < 0 || idxEnd < 0 || idxText < 0) return;
      const start = assTime(parts[idxStart] || '0:00:00.00');
      const end = assTime(parts[idxEnd] || '0:00:00.00');
      const rawText = parts.slice(idxText).join(',')
        .replace(/\{[^}]*\}/g, '')
        .replace(/\\N/g, '\n');
      out += `${start} --> ${end}\n${rawText.trim()}\n\n`;
    });
    return out;
  }

  function shiftVtt(vtt, delaySeconds) {
    if (!delaySeconds) return vtt;
    return vtt.replace(/(\d{2}):(\d{2}):(\d{2})\.(\d{3})/g, (m, h, mi, s, ms) => {
      let total = (+h) * 3600 + (+mi) * 60 + (+s) + (+ms) / 1000 + delaySeconds;
      total = Math.max(0, total);
      const hh = String(Math.floor(total / 3600)).padStart(2, '0');
      const mm = String(Math.floor(total % 3600 / 60)).padStart(2, '0');
      const ss = String(Math.floor(total % 60)).padStart(2, '0');
      const msRem = String(Math.round((total % 1) * 1000)).padStart(3, '0');
      return `${hh}:${mm}:${ss}.${msRem}`;
    });
  }

  function readSubtitleFile(file) {
    return file.arrayBuffer().then(buf => toVtt(decodeSubtitleBytes(buf)));
  }

  /* ---------------- کمکی ---------------- */
  function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
  function escapeAttr(s) { return escapeHtml(s); }

  /* ---------------- شروع ---------------- */
  loadLocalFilms();
  join();
})();
