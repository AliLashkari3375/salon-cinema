(function () {
  'use strict';

  const root = document.getElementById('salon-media-app');
  const C = window.SalonMedia;
  if (!root || !C) return;

  const NEEDS_PREP = ['mkv', 'avi', 'mov', 'ts'];
  const VIDEO_EXT = ['mp4', 'm4v', 'webm', 'mkv', 'avi', 'mov', 'ts'];
  const SUB_EXT = ['vtt', 'srt'];
  const MIN_CHUNK = 64 * 1024;

  /* ---------------- کمکی‌ها ---------------- */
  function el(tag, attrs, ...kids) {
    const n = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([k, v]) => {
      if (v === false || v === null || v === undefined) return;
      if (k === 'class') n.className = v;
      else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
      else n.setAttribute(k, v === true ? '' : v);
    });
    kids.flat().forEach(k => n.append(k instanceof Node ? k : document.createTextNode(String(k))));
    return n;
  }

  function ext(name) {
    const m = /\.([^.]+)$/.exec(name);
    return m ? m[1].toLowerCase() : '';
  }

  function human(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
    return bytes + ' B';
  }

  function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

  function req(path, opts) {
    opts = opts || {};
    const url = new URL(C.api.replace(/\/$/, '') + path);
    Object.entries(opts.query || {}).forEach(([k, v]) => url.searchParams.set(k, v));
    const headers = Object.assign({ 'X-WP-Nonce': C.nonce }, opts.headers || {});
    return fetch(url, { method: opts.method || 'GET', headers, body: opts.body, credentials: 'same-origin' })
      .then(r => r.text().then(t => {
        let j = null;
        try { j = JSON.parse(t); } catch (e) { /* پاسخ غیر JSON (مثلاً صفحه‌ی خطای وب‌سرور) */ }
        if (!r.ok) {
          const err = new Error((j && j.message) || ('خطای سرور (' + r.status + ')'));
          err.status = r.status;
          err.data = j && j.data;
          throw err;
        }
        return j;
      }));
  }

  function postJson(path, obj) {
    return req(path, { method: 'POST', body: JSON.stringify(obj), headers: { 'Content-Type': 'application/json' } });
  }

  /* ---------------- آپلود تکه‌تکه ---------------- */
  async function uploadFile(file, name, onProgress, dir) {
    if (file.size === 0) throw new Error('فایل خالی است.');
    const baseQ = dir ? { name: name, dir: dir } : { name: name };
    const st = await req('/status', { query: Object.assign({ size: file.size }, baseQ) });
    let finalName = st.name || name;
    let offset = st.offset;
    let chunk = st.chunk;
    let fails = 0;
    onProgress(offset / file.size);

    while (offset < file.size) {
      const end = Math.min(offset + chunk, file.size);
      try {
        const fd = new FormData();
        fd.append('chunk', file.slice(offset, end), 'chunk.bin');
        const res = await req('/chunk', {
          method: 'POST',
          query: Object.assign({ offset: offset, total: file.size }, baseQ),
          body: fd,
        });
        if (res.name) finalName = res.name;
        offset = res.offset;
        fails = 0;
        onProgress(offset / file.size);
      } catch (e) {
        // وب‌سرور تکه را بزرگ دانسته → کوچک‌ترش کن
        if (e.status === 413 && chunk > MIN_CHUNK) { chunk = Math.max(MIN_CHUNK, Math.floor(chunk / 2)); continue; }
        // ترتیب به‌هم خورد → از جای واقعی سرور ادامه بده
        if (e.status === 409 && e.data && typeof e.data.offset === 'number' && fails < 5) { offset = e.data.offset; fails++; continue; }
        // قطعی شبکه / خطای موقت → چند بار با تأخیر دوباره
        if ((e instanceof TypeError || e.status >= 500 || e.status === 429) && fails < 5) {
          fails++;
          await sleep(1000 * fails);
          try { offset = (await req('/status', { query: Object.assign({ size: file.size }, baseQ) })).offset; } catch (e2) { /* دوباره تلاش می‌شود */ }
          continue;
        }
        throw e;
      }
    }
    return finalName;
  }

  /* ---------------- ساختار صفحه ---------------- */
  const queueBox = el('div', { class: 'sm-queue' });
  const logBox = el('pre', { class: 'sm-log', hidden: true });
  const libBox = el('div', { class: 'sm-lib' }, 'در حال بارگذاری…');
  const fileInput = el('input', { type: 'file', multiple: true, hidden: true, accept: VIDEO_EXT.concat(SUB_EXT).map(e => '.' + e).join(',') });

  // آیکن فیلم، هم‌شکل صحنه‌ی خالی سالن (فقط در فرانت)
  function filmIcon() {
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.4');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    [['rect', { x: 3, y: 4, width: 18, height: 16, rx: 2 }], ['path', { d: 'M7 4v16M17 4v16M3 9h4M3 15h4M17 9h4M17 15h4' }],
      ['path', { d: 'M11 10l3 2-3 2z' }]].forEach(([t, a]) => {
      const n = document.createElementNS(ns, t);
      Object.entries(a).forEach(([k, v]) => n.setAttribute(k, v));
      svg.append(n);
    });
    return svg;
  }

  const isFront = C.mode === 'front';
  const drop = el('div', { class: 'sm-drop', tabindex: '0', role: 'button' },
    isFront ? filmIcon() : null,
    el('strong', null, 'فیلم را اینجا بکش و رها کن'),
    el('span', null, 'یا برای انتخاب فایل کلیک کن (mp4, mkv, avi, mov, ts, webm و زیرنویس vtt/srt)'));
  drop.addEventListener('click', () => fileInput.click());
  drop.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); } });
  ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', e => addFiles(e.dataTransfer.files));
  fileInput.addEventListener('change', () => { addFiles(fileInput.files); fileInput.value = ''; });

  root.textContent = '';
  if (isFront) {
    root.append(el('div', { class: 'sm-head' },
      el('h2', { class: 'sm-title' }, 'آپلود فیلم'),
      el('p', { class: 'sm-sub' }, 'فیلم‌های کتابخانه‌ی سالن')));
  }
  root.append(drop, fileInput, queueBox, logBox, libBox);

  if (C.mode === 'admin') {
    const test = el('button', { type: 'button', class: 'button', onclick: () => {
      test.disabled = true;
      req('/ffmpeg').then(r => {
        const msg = !r.proc_open ? '✗ proc_open روی این هاست غیرفعال است.'
          : (r.ffmpeg && r.ffprobe ? '✓ ' + r.ffmpeg : '⚠ ffmpeg/ffprobe پیدا نشد (مسیرش را در تنظیمات بگذار).');
        showLog([msg]);
      }).catch(e => showLog(['✗ ' + e.message])).finally(() => { test.disabled = false; });
    } }, 'بررسی ffmpeg');
    root.append(el('p', { class: 'sm-tools' }, test));
  }

  function showLog(lines, append) {
    logBox.hidden = false;
    logBox.textContent = (append && logBox.textContent ? logBox.textContent + '\n' : '') + lines.join('\n');
    logBox.scrollTop = logBox.scrollHeight;
  }

  /* ---------------- صف آپلود (پشت‌سرهم) ---------------- */
  const queue = [];
  let running = false;

  function addFiles(list) {
    Array.from(list).forEach(f => {
      const e = ext(f.name);
      const item = { file: f, name: f.name };
      const bar = el('div', { class: 'sm-bar' }, el('i'));
      const status = el('span', { class: 'sm-status' }, 'در صف');
      item.row = el('div', { class: 'sm-item' }, el('span', { class: 'sm-name' }, f.name, ' ', el('small', null, human(f.size))), bar, status);
      item.bar = bar.firstChild;
      item.status = status;
      if (!VIDEO_EXT.includes(e) && !SUB_EXT.includes(e)) {
        status.textContent = '✗ نوع فایل مجاز نیست';
        item.row.classList.add('err');
        queueBox.append(item.row);
        return;
      }
      queueBox.append(item.row);
      queue.push(item);
    });
    pump();
  }

  async function pump() {
    if (running) return;
    running = true;
    while (queue.length) {
      const it = queue.shift();
      it.row.classList.add('active');
      try {
        it.status.textContent = 'در حال آپلود…';
        const savedName = await uploadFile(it.file, it.name, p => {
          it.bar.style.width = Math.round(p * 100) + '%';
          it.status.textContent = Math.round(p * 100) + '%';
        }, it.dir);
        it.bar.style.width = '100%';
        it.row.classList.remove('active');
        it.row.classList.add('ok');
        it.status.textContent = '✓ آپلود شد';

        // فیلمی که مرورگر پخشش نمی‌کند (mkv و …) بلافاصله آماده‌سازی می‌شود
        if (NEEDS_PREP.includes(ext(savedName))) {
          it.status.textContent = '… در حال آماده‌سازی (ممکن است چند دقیقه طول بکشد)';
          try {
            const res = await postJson('/prep', { file: savedName });
            showLog(['— ' + savedName].concat(res.log), true);
            it.status.textContent = res.ok ? '✓ آپلود و آماده‌سازی شد' : '⚠ آپلود شد، ولی آماده‌سازی کامل نشد (لاگ را ببین)';
          } catch (e) {
            it.status.textContent = '⚠ آپلود شد، آماده‌سازی ناموفق: ' + e.message;
          }
        }
      } catch (e) {
        it.row.classList.remove('active');
        it.row.classList.add('err');
        it.status.textContent = '✗ ' + e.message;
      }
      loadLibrary();
    }
    running = false;
  }

  /* ---------------- کتابخانه ---------------- */
  function loadLibrary() {
    return req('/library').then(renderLibrary).catch(e => {
      libBox.textContent = '';
      libBox.append(el('p', { class: 'sm-err' }, 'خواندن لیست فیلم‌ها ناموفق بود: ' + e.message));
    });
  }

  function badge(film) {
    if (film.ready) return el('span', { class: 'sm-badge ok' }, 'آماده');
    if (film.converted) return el('span', { class: 'sm-badge muted' }, 'اصل (mp4 ساخته شده)');
    return el('span', { class: 'sm-badge warn' }, 'نیاز به آماده‌سازی');
  }

  function renderLibrary(films) {
    libBox.textContent = '';
    if (!films.length) {
      libBox.append(el('p', { class: 'sm-empty' }, 'هنوز فیلمی آپلود نشده.'));
      return;
    }
    const head = el('tr', null, el('th', null, 'فیلم'), el('th', null, 'حجم'), el('th', null, 'زیرنویس'), el('th', null, 'وضعیت'), el('th', null, ''));
    const tbody = el('tbody');
    films.forEach(f => tbody.append(filmRow(f)));
    libBox.append(el('table', { class: 'sm-table' }, el('thead', null, head), tbody));
  }

  function filmRow(f) {
    const subs = el('td', { class: 'sm-subs' });
    f.subs.forEach(s => {
      const chip = el('span', { class: 'sm-chip', title: s.file }, s.label);
      if (C.canDelete) {
        chip.append(' ', el('button', { type: 'button', class: 'sm-x', title: 'حذف این زیرنویس', onclick: () => removeFile(relOf(f, s.file), 'زیرنویس «' + s.label + '» حذف شود؟') }, '×'));
      }
      subs.append(chip);
    });
    subs.append(subForm(f));

    const actions = el('td', { class: 'sm-actions' });
    if (f.ready && f.url && C.mode === 'admin') {
      actions.append(el('a', { class: 'button', href: f.url, target: '_blank', rel: 'noopener', title: 'همین آدرسی که سالن برای پخش استفاده می‌کند' }, 'باز کردن'));
    }
    if (f.ready) actions.append(prepBtn(f, 'استخراج زیرنویس داخلی', false));
    else if (!f.converted) actions.append(prepBtn(f, 'آماده‌سازی', true));
    if (C.canDelete) {
      actions.append(el('button', { type: 'button', class: 'button sm-del', onclick: () => removeFile(f.id, 'فیلم «' + f.name + '» حذف شود؟' + (f.subs.length ? ' (اگر نسخه‌ی دیگری از آن نباشد، زیرنویس‌هایش هم پاک می‌شود)' : '')) }, 'حذف'));
    }
    return el('tr', null, el('td', { class: 'sm-fname' }, f.name), el('td', { class: 'sm-size' }, human(f.size)), subs, el('td', null, badge(f)), actions);
  }

  // مسیر نسبی زیرنویس = پوشه‌ی فیلم + نام زیرنویس
  function relOf(film, fileName) {
    const i = film.id.lastIndexOf('/');
    return (i >= 0 ? film.id.slice(0, i + 1) : '') + fileName;
  }

  function prepBtn(f, label, primary) {
    const b = el('button', { type: 'button', class: 'button' + (primary ? ' button-primary' : '') }, label);
    b.addEventListener('click', () => {
      b.disabled = true;
      b.textContent = 'در حال پردازش…';
      postJson('/prep', { file: f.id })
        .then(res => showLog(['— ' + f.name].concat(res.log)))
        .catch(e => showLog(['✗ ' + f.name + ': ' + e.message]))
        .finally(loadLibrary);
    });
    return b;
  }

  function subForm(f) {
    const sel = el('select', { class: 'sm-lang' });
    Object.entries(C.langs).forEach(([code, label]) => { if (code !== 'xx') sel.append(el('option', { value: code }, label)); });
    const inp = el('input', { type: 'file', accept: '.vtt,.srt', hidden: true });
    const btn = el('button', { type: 'button', class: 'sm-add', title: 'افزودن زیرنویس (vtt یا srt)' }, '＋ زیرنویس');
    const box = el('span', { class: 'sm-subform', hidden: true }, sel, inp);
    btn.addEventListener('click', () => { box.hidden = !box.hidden; if (!box.hidden) inp.click(); });
    inp.addEventListener('change', () => {
      const file = inp.files[0];
      inp.value = '';
      if (!file) return;
      const e = ext(file.name);
      if (!SUB_EXT.includes(e)) { showLog(['✗ فقط فایل vtt یا srt.']); return; }
      // اگر همین زبان قبلاً هست، شماره‌دار می‌شود (fa2, fa3, …)
      let code = sel.value, n = 2;
      const have = f.subs.map(s => s.lang);
      while (have.includes(code)) { code = sel.value + n; n++; }
      const base = f.base;
      const name = base + '.' + code + '.' + e;
      const slash = f.id.lastIndexOf('/');
      const dir = slash >= 0 ? f.id.slice(0, slash) : '';
      const item = { file: file, name: name, dir: dir };
      const bar = el('div', { class: 'sm-bar' }, el('i'));
      const status = el('span', { class: 'sm-status' }, 'در صف');
      item.row = el('div', { class: 'sm-item' }, el('span', { class: 'sm-name' }, name), bar, status);
      item.bar = bar.firstChild; item.status = status;
      queueBox.append(item.row);
      queue.push(item);
      box.hidden = true;
      pump();
    });
    return el('span', { class: 'sm-subwrap' }, btn, box);
  }

  function removeFile(rel, question) {
    if (!window.confirm(question)) return;
    postJson('/delete', { file: rel })
      .then(() => loadLibrary())
      .catch(e => showLog(['✗ ' + e.message]));
  }

  loadLibrary();
})();
