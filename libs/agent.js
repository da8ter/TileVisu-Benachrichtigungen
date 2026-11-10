/* Benachrichtigungs-Kachel: Start des Empfängers ohne geladene Kachel.
 *
 * Der Hook liefert diese Datei als k=agent.js mit eingesetzter Konfiguration. Wer sie im Fenster der
 * Visualisierung lädt (die Browser-Erweiterung aus browser-extension/), bekommt denselben Empfänger,
 * den sonst die Kachel startet: overlay.js laden, dann agent() aufrufen. Lädt später eine Kachel,
 * ersetzt sie die Konfiguration unter demselben owner (mit Farben und Icons aus der Kachel).
 */
(function () {
  'use strict';
  var C = /*NTF_AGENT*/null;
  var w = window, d = document;
  if (!C || w.top !== w) return;
  var laufend = w.__symconNotifyAgents || (w.__symconNotifyAgents = {});
  if (laufend[C.owner]) return;
  laufend[C.owner] = true;

  function farbe(name, rueckfall) {
    try { return getComputedStyle(d.documentElement).getPropertyValue(name).trim() || rueckfall; } catch (e) { return rueckfall; }
  }
  function start() {
    var api = w.__symconNotify;
    if (!api || typeof api.agent !== 'function') { laufend[C.owner] = false; return; }
    var dunkel = false;
    try { dunkel = w.matchMedia('(prefers-color-scheme: dark)').matches; } catch (e) { /* hell */ }
    api.agent(JSON.stringify({
      owner: C.owner, pollUrl: C.hook + '&k=poll', hook: C.hook, intervalMs: C.pollMs, seenKey: C.seenKey, labels: C.labels,
      theme: {
        card: farbe('--card-color', dunkel ? '#1c1c1e' : '#ffffff'),
        content: farbe('--content-color', dunkel ? '#f5f5f7' : '#1d1d1f'),
        accent: farbe('--accent-color', '#00cdab')
      }
    }));
  }
  var s = d.createElement('script');
  s.src = C.overlayJs;
  s.onload = s.onerror = function (ev) {
    s.onload = s.onerror = null;
    s.remove();
    if (ev && ev.type === 'load') start(); else laufend[C.owner] = false;
  };
  (d.head || d.documentElement).appendChild(s);
})();
