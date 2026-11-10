(function () {
  'use strict';
  var feld = document.getElementById('urls'), status = document.getElementById('status');
  var SCRIPT_ID = 'ntf-agent';

  function melde(text, fehler) {
    status.textContent = text;
    status.className = fehler ? 'fehler' : '';
  }
  function pruefe(zeile) {
    var u;
    try { u = new URL(zeile); } catch (e) { return null; }
    if (u.protocol !== 'http:' && u.protocol !== 'https:') return null;
    if (!/^\/hook\/notify\/\d+$/.test(u.pathname)) return null;
    if (!/^[0-9a-f]{32}$/.test(u.searchParams.get('t') || '') || u.searchParams.get('k') !== 'agent.js') return null;
    return u;
  }

  chrome.storage.local.get('agents', function (r) { feld.value = (r.agents || []).join('\n'); });

  document.getElementById('save').addEventListener('click', function () {
    var zeilen = feld.value.split('\n').map(function (z) { return z.trim(); }).filter(Boolean);
    var urls = [], falsch = [];
    zeilen.forEach(function (z) { var u = pruefe(z); if (u) urls.push(u); else falsch.push(z); });
    if (falsch.length) { melde('Keine gültige Adresse:\n' + falsch.join('\n'), true); return; }
    var muster = urls.map(function (u) { return u.origin + '/*'; }).filter(function (m, i, a) { return a.indexOf(m) === i; });

    function fertig() {
      chrome.storage.local.set({ agents: urls.map(function (u) { return u.href; }) }, function () {
        chrome.scripting.unregisterContentScripts({ ids: [SCRIPT_ID] }).catch(function () { /* noch keins */ }).then(function () {
          if (!muster.length) return;
          return chrome.scripting.registerContentScripts([{ id: SCRIPT_ID, matches: muster, js: ['inject.js'], runAt: 'document_idle' }]);
        }).then(function () {
          // Zugriff auf Adressen, die nicht mehr eingetragen sind, zurückgeben.
          chrome.permissions.getAll(function (p) {
            var alt = (p.origins || []).filter(function (o) { return muster.indexOf(o) < 0; });
            if (alt.length) chrome.permissions.remove({ origins: alt });
          });
          melde(muster.length ? 'Gespeichert. Die Visualisierung neu laden.' : 'Gespeichert. Keine Adresse eingetragen.', false);
        }).catch(function (e) { melde('Fehler: ' + (e && e.message || e), true); });
      });
    }
    if (!muster.length) { fertig(); return; }
    // Muss direkt im Klick passieren: Chrome fragt den Zugriff auf genau diese Adressen ab.
    chrome.permissions.request({ origins: muster }, function (ok) {
      if (ok) fertig(); else melde('Ohne die Freigabe für die Adresse kann die Erweiterung nichts einblenden.', true);
    });
  });
})();
