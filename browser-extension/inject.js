/* Läuft auf den eingerichteten Symcon-Adressen und hängt dort das Start-Skript des Moduls an
 * (Hook k=agent.js). Der gesamte Overlay-Code kommt vom Modul, hier wird nichts davon gepflegt. */
(function () {
  'use strict';
  if (window.top !== window) return;
  if (/^\/(console|hook)(\/|$)/.test(location.pathname)) return;
  chrome.storage.local.get('agents', function (r) {
    (r.agents || []).forEach(function (url) {
      var u;
      try { u = new URL(url); } catch (e) { return; }
      if (u.origin !== location.origin) return;
      var s = document.createElement('script');
      s.src = u.href;
      s.onload = s.onerror = function () { s.remove(); };
      (document.head || document.documentElement).appendChild(s);
    });
  });
})();
