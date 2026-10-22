/* Benachrichtigungs-Kachel: Overlay über der ganzen Visualisierung.
 *
 * Läuft im Fenster, das dieses Skript lädt: im Visu-Hauptfenster (die Kachel hängt es dort als
 * <script> an, Code und Objekte gehören dann dem Hauptfenster und überleben das Entladen der Kachel)
 * oder ohne erreichbares Hauptfenster in der Kachel selbst. Schnittstelle: window.__symconNotify.
 * Alle Aufrufe bekommen JSON-Text, nie Objekte der Kachel — ein Objekt aus der Kachel hielte deren
 * Dokument im Hauptfenster fest.
 *
 *   show(json)        {owner, msg, theme, labels, icon, force, hook}  Nachricht zeigen (Schlange); mit hook
 *                     (Hook-Basis) gibt es den Mülleimer, der per POST k=delete löscht
 *   closeOwner(owner) Overlays einer Kachel schließen (Modus „nur Seite der Kachel")
 *   agent(json)       {owner, pollUrl, hook, intervalMs, seenKey, theme, labels}  Empfänger für jede Seite
 *   pollNow(owner)    Empfänger sofort fragen lassen
 *   icon(name, svg)   SVG eines Icons für den Empfänger merken
 *   play(url)         Ton einer neuen Nachricht ohne Overlay abspielen
 *
 * Töne spielen nur bei NEUEN Nachrichten (nicht beim Öffnen aus der Liste) und im Hauptfenster: das hat
 * nach dem ersten Klick in der Visu die Nutzer-Aktivierung, die Browser für Audio verlangen.
 *
 * HTML-Inhalte laufen in einem iframe mit sandbox="allow-scripts allow-popups allow-forms" (ohne
 * allow-same-origin): eigener, undurchsichtiger Ursprung, kein Zugriff auf die Visu.
 */
(function () {
  'use strict';
  var VERSION = 4; // 2: Ton (play), 3: Löschen im Overlay, 4: veraltetes Stylesheet ersetzen
  var w = window, d = document;
  var vorher = w.__symconNotify;
  if (vorher && vorher.version >= VERSION) return;
  if (vorher && typeof vorher.dispose === 'function') {
    try { vorher.dispose(); } catch (e) { /* alte Fassung */ }
  }

  var LEVEL = { info: '#3498fa', success: '#34c759', warning: '#ff9500', alarm: '#ff3b30' };
  var LEVEL_SVG = {
    info: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9.5"/><path d="M12 11v6M12 7.5v.5" stroke-linecap="round"/></svg>',
    success: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9.5"/><path d="M7.5 12.5l3 3 6-6.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    warning: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 3.5l9.5 16.5h-19z" stroke-linejoin="round"/><path d="M12 10v4.5M12 17.2v.3" stroke-linecap="round"/></svg>',
    alarm: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 17V11a6 6 0 0112 0v6M4 17h16M10 20h4M12 2.5v2M3.5 6l1.5 1.2M20.5 6l-1.5 1.2" stroke-linecap="round"/></svg>'
  };
  var TRASH_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6.5h16M9.5 6.5V4.5h5v2M6.5 6.5l1 13h9l1-13M10 10.5v6M14 10.5v6"/></svg>';
  var SIZE = {
    small: { width: 'min(360px, 94vw)' },
    medium: { width: 'min(560px, 94vw)' },
    large: { width: 'min(860px, 94vw)' },
    full: { width: '95vw', height: '90vh' }
  };
  var CSS = [
    '.ntf-layer{position:fixed;inset:0;z-index:2147483646;display:flex;align-items:center;justify-content:center;',
    'background:rgba(0,0,0,.45);-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px);',
    'font:15px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif;animation:ntf-in .18s ease-out}',
    '@keyframes ntf-in{from{opacity:0}to{opacity:1}}',
    '.ntf-card{box-sizing:border-box;max-width:95vw;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;',
    'border-radius:18px;box-shadow:0 24px 70px rgba(0,0,0,.35);position:relative}',
    '.ntf-head{display:flex;align-items:center;gap:12px;padding:18px 18px 8px 20px}',
    '.ntf-icon{flex:0 0 40px;width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center}',
    '.ntf-icon svg{width:22px;height:22px;fill:currentColor}',
    '.ntf-icon svg[fill="none"]{fill:none}',
    '.ntf-title{flex:1;min-width:0;font-size:18px;font-weight:650;overflow-wrap:anywhere}',
    '.ntf-x{flex:0 0 auto;border:0;background:transparent;color:inherit;opacity:.6;font-size:22px;line-height:1;cursor:pointer;padding:6px 8px;border-radius:10px}',
    '.ntf-x:hover{opacity:1}',
    '.ntf-body{flex:1 1 auto;min-height:0;overflow:auto;padding:4px 20px 12px;display:flex;flex-direction:column;gap:12px}',
    '.ntf-text{white-space:pre-wrap;overflow-wrap:anywhere}',
    '.ntf-img{display:block;max-width:100%;max-height:55vh;object-fit:contain;border-radius:12px;align-self:center}',
    '.ntf-frame{display:block;width:100%;border:0;min-height:24px;height:60px;background:transparent}',
    '.ntf-card.ntf-fixed .ntf-frame{flex:1 1 auto;height:auto;min-height:120px}',
    '.ntf-foot{display:flex;align-items:center;justify-content:flex-end;gap:12px;padding:8px 18px 16px}',
    '.ntf-more{flex:1;opacity:.6;font-size:13px}',
    '.ntf-btn{font:inherit;font-weight:700;border:0;border-radius:12px;padding:10px 18px;cursor:pointer;color:#fff}',
    '.ntf-del{border:0;border-radius:12px;width:42px;height:42px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:inherit}',
    '.ntf-del svg{width:20px;height:20px}',
    '.ntf-bar{position:absolute;left:0;bottom:0;height:3px;width:100%;transform-origin:left;animation-name:ntf-bar;animation-timing-function:linear;animation-fill-mode:forwards}',
    '.ntf-card:hover .ntf-bar{animation-play-state:paused}',
    '@keyframes ntf-bar{from{transform:scaleX(1)}to{transform:scaleX(0)}}'
  ].join('');

  var queue = [];        // [{owner, msg, theme, labels, icon}]
  var layer = null;      // sichtbares Overlay
  var frame = null;      // iframe der sichtbaren Nachricht
  var agents = {};       // owner → {cfg, timer}
  var icons = {};        // Icon-Name → SVG (von der Kachel geliefert)
  var ton = null;        // zuletzt gestarteter Ton

  function play(url) {
    if (typeof url !== 'string' || url.indexOf('/hook/') !== 0) return;
    try {
      if (ton) ton.pause();
      ton = new w.Audio(url);
      var p = ton.play();
      if (p && typeof p.catch === 'function') p.catch(function () { /* Browser verweigert ohne Bedienung */ });
    } catch (e) { /* kein Audio */ }
  }

  function parse(json) {
    try { return typeof json === 'string' ? JSON.parse(json) : null; } catch (e) { return null; }
  }

  // Ein Stylesheet einer älteren Fassung (Visu-Fenster läuft seit Stunden) wird ersetzt, nicht übernommen.
  function style() {
    var s = d.getElementById('ntf-style');
    if (s && s.getAttribute('data-v') === String(VERSION)) return;
    if (!s) {
      s = d.createElement('style');
      s.id = 'ntf-style';
      (d.head || d.documentElement).appendChild(s);
    }
    s.setAttribute('data-v', String(VERSION));
    s.textContent = CSS;
  }

  function hex(color, fallback) {
    var c = String(color || '').trim();
    if (/^#[0-9a-f]{6}$/i.test(c)) return c;
    var m = c.match(/^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/i);
    if (m) return '#' + [m[1], m[2], m[3]].map(function (v) { return ('0' + Math.min(255, +v).toString(16)).slice(-2); }).join('');
    return fallback;
  }

  function iconMarkup(e) {
    if (e.icon) return e.icon;
    var name = e.msg.icon || '';
    if (name && icons[name]) return icons[name];
    return LEVEL_SVG[e.msg.level] || LEVEL_SVG.info;
  }

  /* SVG-Text als Knoten, ohne innerHTML: geparst als SVG, Skripte, foreignObject, Ereignis-Attribute
     und javascript:-Verweise entfernt. Null, wenn es kein SVG ist. */
  function svgNode(markup) {
    if (typeof markup !== 'string' || markup.indexOf('<svg') !== 0) return null;
    var doc;
    try { doc = new w.DOMParser().parseFromString(markup, 'image/svg+xml'); } catch (e) { return null; }
    var root = doc && doc.documentElement;
    if (!root || root.nodeName.toLowerCase() !== 'svg' || doc.getElementsByTagName('parsererror').length) return null;
    var alle = [root].concat(Array.prototype.slice.call(root.getElementsByTagName('*')));
    alle.forEach(function (el) {
      var n = el.nodeName.toLowerCase();
      if (n === 'script' || n === 'foreignobject') { el.parentNode && el.parentNode.removeChild(el); return; }
      Array.prototype.slice.call(el.attributes).forEach(function (a) {
        var an = a.name.toLowerCase();
        if (an.indexOf('on') === 0 || /^\s*javascript:/i.test(a.value) && /href$/.test(an)) el.removeAttribute(a.name);
      });
    });
    return d.importNode(root, true);
  }

  function render() {
    if (layer) { layer.remove(); layer = null; frame = null; }
    var e = queue[0];
    if (!e) {
      d.removeEventListener('keydown', onKey, true);
      return;
    }
    style();
    var m = e.msg, t = e.theme || {}, l = e.labels || {};
    if (e.playSound && m.sound && !e.played) { e.played = true; play(m.sound); }
    var card = hex(t.card, '#ffffff'), content = hex(t.content, '#1d1d1f'), accent = hex(t.accent, '#00cdab');
    var level = LEVEL[m.level] || accent;

    layer = d.createElement('div');
    layer.className = 'ntf-layer';
    layer.setAttribute('role', 'dialog');
    layer.setAttribute('aria-modal', 'true');
    var box = d.createElement('div');
    box.className = 'ntf-card';
    box.style.background = card;
    box.style.color = content;
    var size = m.size && m.size.preset ? SIZE[m.size.preset] || SIZE.medium : (m.size || SIZE.medium);
    if (size.width) box.style.width = size.width;
    if (size.height) { box.style.height = size.height; box.classList.add('ntf-fixed'); }

    var head = d.createElement('div');
    head.className = 'ntf-head';
    var ic = d.createElement('div');
    ic.className = 'ntf-icon';
    ic.style.color = level;
    ic.style.background = level + '22';
    var svg = svgNode(iconMarkup(e)) || svgNode(LEVEL_SVG[m.level] || LEVEL_SVG.info);
    if (svg) ic.appendChild(svg);
    var title = d.createElement('div');
    title.className = 'ntf-title';
    title.textContent = m.title || '';
    var x = d.createElement('button');
    x.className = 'ntf-x';
    x.type = 'button';
    x.setAttribute('aria-label', l.close || 'Close');
    x.textContent = '×';
    x.addEventListener('click', next);
    head.appendChild(ic); head.appendChild(title); head.appendChild(x);
    box.appendChild(head);

    var body = d.createElement('div');
    body.className = 'ntf-body';
    if (m.text) {
      var p = d.createElement('div');
      p.className = 'ntf-text';
      p.textContent = m.text;
      body.appendChild(p);
    }
    if (m.image) {
      var img = d.createElement('img');
      img.className = 'ntf-img';
      img.alt = '';
      img.src = m.image;
      body.appendChild(img);
    }
    if (m.html) {
      frame = d.createElement('iframe');
      frame.className = 'ntf-frame';
      frame.setAttribute('sandbox', 'allow-scripts allow-popups allow-forms');
      frame.setAttribute('referrerpolicy', 'no-referrer');
      frame.src = m.html + '&c=' + content.slice(1);
      body.appendChild(frame);
    }
    box.appendChild(body);

    var foot = d.createElement('div');
    foot.className = 'ntf-foot';
    var more = d.createElement('div');
    more.className = 'ntf-more';
    if (queue.length > 1) more.textContent = String(l.more || '+%1 more').replace('%1', String(queue.length - 1));
    var btn = d.createElement('button');
    btn.className = 'ntf-btn';
    btn.type = 'button';
    btn.style.background = accent;
    btn.textContent = queue.length > 1 ? (l.next || 'Next') : (l.close || 'Close');
    btn.addEventListener('click', next);
    foot.appendChild(more);
    if (typeof e.hook === 'string' && e.hook.indexOf('/hook/') === 0) {
      var del = d.createElement('button');
      del.className = 'ntf-del';
      del.type = 'button';
      del.title = l.delete || 'Delete';
      del.setAttribute('aria-label', l.delete || 'Delete');
      del.style.background = content + '14';
      var trash = svgNode(TRASH_SVG);
      if (trash) del.appendChild(trash);
      del.addEventListener('click', function () { loeschen(e); });
      foot.appendChild(del);
    }
    foot.appendChild(btn);
    box.appendChild(foot);

    if (m.duration > 0) {
      var bar = d.createElement('div');
      bar.className = 'ntf-bar';
      bar.style.background = level;
      bar.style.animationDuration = m.duration + 's';
      bar.addEventListener('animationend', next);
      box.appendChild(bar);
    }

    layer.appendChild(box);
    layer.addEventListener('click', function (ev) { if (ev.target === layer) next(); });
    d.addEventListener('keydown', onKey, true);
    (d.body || d.documentElement).appendChild(layer);
  }

  function onKey(ev) {
    if (ev.key === 'Escape' && layer) { ev.stopPropagation(); next(); }
  }

  function next() {
    queue.shift();
    render();
  }

  // Löschen über den Hook (POST); die Liste der Kachel folgt über den Push des Moduls.
  function loeschen(e) {
    try {
      fetch(e.hook + '&k=delete&m=' + encodeURIComponent(e.msg.id), { method: 'POST', credentials: 'same-origin', cache: 'no-store' })
        .catch(function () { /* nächster Versuch über die Liste */ });
    } catch (x) { /* kein fetch */ }
    queue = queue.filter(function (q) { return !(q.owner === e.owner && q.msg.id === e.msg.id); });
    render();
  }

  // Höhe des HTML-Inhalts: nur von genau diesem iframe annehmen.
  function onMessage(ev) {
    if (!frame || ev.source !== frame.contentWindow) return;
    var h = ev.data && typeof ev.data.ntfHeight === 'number' ? ev.data.ntfHeight : -1;
    if (h >= 0 && !frame.closest('.ntf-fixed')) frame.style.height = Math.min(h, 20000) + 'px';
  }
  w.addEventListener('message', onMessage);

  function show(json) {
    var e = parse(json);
    if (!e || !e.msg || !e.msg.id) return;
    var entry = { owner: e.owner, msg: e.msg, theme: e.theme, labels: e.labels, icon: typeof e.icon === 'string' ? e.icon : '',
      hook: typeof e.hook === 'string' ? e.hook : '',
      playSound: !e.force && !!e.msg.sound, played: false };
    var idx = -1;
    for (var i = 0; i < queue.length; i++) {
      if (queue[i].owner === entry.owner && queue[i].msg.id === entry.msg.id) { idx = i; break; }
    }
    if (e.force) {
      // Aus der Liste geöffnet: sofort vorne, auch wenn sie schon in der Schlange wartet.
      if (idx >= 0) queue.splice(idx, 1);
      queue.unshift(entry);
      render();
      return;
    }
    if (idx >= 0) {
      queue[idx] = entry;
      if (idx === 0) render();
      return;
    }
    queue.push(entry);
    if (queue.length === 1) render(); else if (layer) render();
  }

  function closeOwner(owner) {
    var vorne = queue[0];
    queue = queue.filter(function (e) { return e.owner !== owner; });
    if (vorne !== queue[0]) render();
  }

  // ── Empfänger für „auf jeder Seite" ─────────────────────────────────────────
  function seenGet(key) {
    try { var v = w.localStorage.getItem(key); return v === null ? null : parseInt(v, 10) || 0; } catch (e) { return null; }
  }
  function seenSet(key, v) {
    try { w.localStorage.setItem(key, String(v)); } catch (e) { /* privat */ }
  }

  function tick(owner) {
    var a = agents[owner];
    if (!a || a.busy || d.hidden) return;
    var seen = seenGet(a.cfg.seenKey);
    a.busy = true;
    fetch(a.cfg.pollUrl + '&since=' + (seen === null ? 0 : seen), { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 404 || r.status === 403) { stopAgent(owner); return null; }
        return r.ok ? r.json() : null;
      })
      .then(function (j) {
        if (!j || !agents[owner]) return;
        var aktuell = seenGet(a.cfg.seenKey);
        if (aktuell === null) { seenSet(a.cfg.seenKey, j.seq || 0); return; } // erster Lauf: Altes nicht zeigen
        var hoechste = aktuell;
        (j.messages || []).forEach(function (m) {
          if (m.seq > aktuell) {
            if (m.overlay) show(JSON.stringify({ owner: owner, msg: m, theme: a.cfg.theme, labels: a.cfg.labels, hook: a.cfg.hook || '' }));
            else if (m.sound) play(m.sound);
            hoechste = Math.max(hoechste, m.seq);
          }
        });
        hoechste = Math.max(hoechste, j.seq || 0);
        if (hoechste > aktuell) seenSet(a.cfg.seenKey, hoechste);
      })
      .catch(function () { /* nächster Takt */ })
      .then(function () { a.busy = false; });
  }

  function agent(json) {
    var cfg = parse(json);
    if (!cfg || !cfg.owner || !cfg.pollUrl) return;
    var a = agents[cfg.owner];
    if (a) {
      a.cfg = cfg;
      return;
    }
    a = agents[cfg.owner] = { cfg: cfg, busy: false, timer: 0 };
    a.timer = w.setInterval(function () { tick(cfg.owner); }, Math.max(1000, cfg.intervalMs || 2000));
    tick(cfg.owner);
  }

  function stopAgent(owner) {
    var a = agents[owner];
    if (a) { w.clearInterval(a.timer); delete agents[owner]; }
  }

  function pollNow(owner) { tick(owner); }

  function icon(name, svg) {
    if (typeof name === 'string' && typeof svg === 'string' && /^[a-z0-9-]{1,60}$/.test(name) && svg.indexOf('<svg') === 0) icons[name] = svg;
  }

  function dispose() {
    Object.keys(agents).forEach(stopAgent);
    queue = [];
    render();
    w.removeEventListener('message', onMessage);
  }

  w.__symconNotify = { version: VERSION, play: play, show: show, closeOwner: closeOwner, agent: agent, stopAgent: stopAgent, pollNow: pollNow, icon: icon, dispose: dispose };
})();
