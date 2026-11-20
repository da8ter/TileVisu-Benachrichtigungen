/* Benachrichtigungs-Kachel: Start des Empfängers ohne geladene Kachel.
 *
 * Die Instanz dockt an das Symcon-Modul „Visu-Erweiterung“ an und liefert ihm diese Datei mit
 * eingesetzter Konfiguration; die Browser-Erweiterung „Visu-Erweiterung für Symcon“ hängt sie ins Fenster
 * der Visualisierung. Dort entsteht derselbe Empfänger, den sonst die Kachel startet: overlay.js laden,
 * dann agent() aufrufen. Lädt später eine Kachel, ersetzt sie die Konfiguration unter demselben owner
 * (mit Farben und Icons aus der Kachel). Nachrichten holt der Empfänger wie die Kachel über den Hook;
 * über den Kanal der Visu-Erweiterung meldet das Modul nur, dass es etwas Neues gibt.
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
  /* Icons über den geteilten Platz window.__symconIcons, denselben, den der Baustein symcon-icons-shared
     der anderen Kacheln benutzt ({v, fa, css, fehler}): Symcons /icons.js läuft einmal im Fenster der
     Visu, ohne dort Elemente zu ersetzen. Legt ihn dieses Skript an, finden ihn später geladene Kacheln
     fertig vor und laden icons.js nicht mehr selbst; hat ihn eine Kachel angelegt, wird er mitbenutzt. */
  function iconSvg(name) {
    try {
      var slot = w.__symconIcons, fa = slot && slot.fa;
      var def = fa && fa.findIconDefinition({ prefix: 'fal', iconName: name });
      return def ? fa.icon(def).html.join('') : '';
    } catch (e) { return ''; }
  }
  function iconsLaden() {
    var slot = w.__symconIcons;
    if (!slot) {
      if (w.FontAwesome || w.FontAwesomeConfig) return; // die Visu bringt selbst etwas mit: nicht anfassen
      slot = w.__symconIcons = { v: 1, fa: null, css: '', fehler: false };
      w.FontAwesomeConfig = { autoReplaceSvg: false, observeMutations: false, autoAddCss: false };
      var s = d.createElement('script');
      s.src = '/icons.js';
      s.onload = function () {
        s.onload = s.onerror = null;
        if (w.FontAwesome && w.FontAwesome.dom) { slot.css = w.FontAwesome.dom.css(); slot.fa = w.FontAwesome; } else slot.fehler = true;
      };
      s.onerror = function () { s.onload = s.onerror = null; slot.fehler = true; };
      (d.head || d.documentElement).appendChild(s);
    }
    var versuche = 0;
    (function warten() {
      if (slot.fa) {
        var api = w.__symconNotify, svg = iconSvg('trash-can');
        if (api && svg) api.icon('trash-can', svg);
      } else if (!slot.fehler && ++versuche <= 100) {
        w.setTimeout(warten, 100);
      }
    })();
  }

  function start() {
    var api = w.__symconNotify;
    if (!api || typeof api.agent !== 'function') { laufend[C.owner] = false; return; }
    var dunkel = false;
    try { dunkel = w.matchMedia('(prefers-color-scheme: dark)').matches; } catch (e) { /* hell */ }
    if (typeof api.iconSource === 'function') api.iconSource(function (name, level) { return iconSvg(name || (C.levelIcons || {})[level] || 'bell'); });
    api.agent(JSON.stringify({
      owner: C.owner, pollUrl: C.hook + '&k=poll', hook: C.hook, intervalMs: C.pollMs, seenKey: C.seenKey, labels: C.labels,
      theme: {
        card: farbe('--card-color', dunkel ? '#1c1c1e' : '#ffffff'),
        content: farbe('--content-color', dunkel ? '#f5f5f7' : '#1d1d1f'),
        accent: farbe('--accent-color', '#00cdab')
      }
    }));
    // Kanal der Visu-Erweiterung: Das Modul meldet eine Änderung, der Empfänger fragt sofort statt im Takt.
    // Die Laufzeit kommt über die Warteliste; ohne sie bleibt es beim Takt.
    (w.visuExtReady = w.visuExtReady || []).push(function (ext) {
      ext.on(C.instance, function (daten) {
        // neue Nachricht mit Overlay: erst aus dem Bildschirmschoner holen, sonst läge sie darunter
        if (daten && daten.wake === true && typeof ext.wake === 'function') ext.wake();
        api.pollNow(C.owner);
      });
    });
  }
  var s = d.createElement('script');
  s.src = C.overlayJs;
  s.onload = s.onerror = function (ev) {
    s.onload = s.onerror = null;
    s.remove();
    if (ev && ev.type === 'load') { start(); iconsLaden(); } else laufend[C.owner] = false;
  };
  (d.head || d.documentElement).appendChild(s);
})();
