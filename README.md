# Benachrichtigungs-Kachel für Symcon

Eine Kachel für die Kachel-Visualisierung, an die Skripte, Module und Ereignisse Nachrichten schicken. Die Kachel zeigt die Nachrichten als Liste. Neue Nachrichten erscheinen auf Wunsch als Overlay über der ganzen Visualisierung, nicht nur innerhalb der Kachel.

## Funktionen

- **Nachrichten** mit Titel, Text, Icon (Font Awesome, Light), Bild (URL, Medienobjekt oder data-URI), Ton (Sound-Medienobjekt) und optional HTML.
- **Vorlagen** für typische Fälle (Alarm, Warnung, Info, Erledigt, Türklingel, Erinnerung) mit eigenem Aussehen.
- **Stufen** `info`, `success`, `warning`, `alarm` mit eigener Farbe und eigenem Icon.
- **Liste** mit den neuesten Nachrichten oben. Ungelesene sind markiert, jede Nachricht lässt sich einzeln oder alle zusammen löschen. Ein Tipp öffnet die Nachricht.
- **Overlay** bleibt offen oder schließt sich nach einer festen Zeit. Es gibt die Größen klein, mittel, groß und Vollbild oder eine eigene Breite und Höhe. Mehrere Nachrichten stellen sich in eine Schlange.
- **HTML** samt Skripten läuft in einem abgeschotteten Rahmen ohne Zugriff auf die Visualisierung.
- **Aktion** „Benachrichtigung senden“ für Ereignisse und Automationen, ohne Skript.
- Variable **Ungelesen** für eigene Auswertungen.

## Installation

Im Module Control die Adresse `https://github.com/da8ter/TileVisu-Benachrichtigungen` hinzufügen. Danach die Instanz „Benachrichtigungs-Kachel“ anlegen und in der Kachel-Visualisierung platzieren.

## Vorlagen

Eine Vorlage bestimmt Aussehen, Stufe, Icon, Größe und Dauer. Was die Nachricht selbst angibt, gilt vor der Vorlage.

```php
NTF_Send(12345, 'Wasseralarm', 'Keller: Sensor Waschküche meldet Wasser', '{"template":"alarm"}');
NTF_Send(12345, 'Waschmaschine fertig', 'Bitte ausräumen', '{"template":"success","icon":"washing-machine"}');
NTF_Send(12345, 'Es hat geklingelt', 'Haustür', json_encode(['template' => 'doorbell', 'image' => 23456, 'sound' => 34567]));
```

| Vorlage | Für | Aussehen | Vorgaben |
|---|---|---|---|
| `alarm` | Wasser, Rauch, Einbruch | roter Banner, großes Icon, großer Titel | groß, bleibt offen |
| `warning` | Fenster offen, Batterie leer | oranger Banner | mittel, bleibt offen |
| `info` | Hinweise | kleine Einblendung oben, Visu bleibt bedienbar | 8 s |
| `success` | Erledigtes (Waschmaschine fertig) | kleine grüne Einblendung oben | 8 s |
| `doorbell` | Türklingel, Besucher | Bild randlos oben (z. B. Kamerabild) | groß, 30 s |
| `reminder` | Müll, Termine | großes rundes Icon mittig, großer Titel | mittel, bleibt offen |

Die Darstellung lässt sich auch ohne Vorlage wählen: `layout` = `card` (Vorgabe), `banner`, `hero`, `toast`, `photo`.

## Nachrichten senden

```php
NTF_Send(12345, 'Waschmaschine', 'Fertig – bitte ausräumen', '');

NTF_Send(12345, 'Haustür', 'Seit 10 Minuten offen', json_encode([
    'level'    => 'warning',
    'icon'     => 'door-open',
    'duration' => 0,          // bleibt offen, bis jemand schließt
    'size'     => 'small',
    'id'       => 'haustuer', // gleiche ID ersetzt die alte Nachricht
]));

NTF_Send(12345, 'Kamera', '', json_encode(['image' => 23456, 'size' => 'large'])); // Medienobjekt (Bild)

NTF_Send(12345, 'Klingel', 'Es hat geklingelt', json_encode(['sound' => 34567])); // Medienobjekt (Ton)

NTF_Send(12345, 'Wetter', '', json_encode([
    'html' => '<h3>Heute</h3><p>Sonnig, 21 °C</p>',
    'size' => ['width' => '700px', 'height' => '60vh'],
]));
```

| Option | Werte | Vorgabe |
|---|---|---|
| `template` | `alarm`, `warning`, `info`, `success`, `doorbell`, `reminder` | – |
| `layout` | `card`, `banner`, `hero`, `toast`, `photo` | aus der Vorlage, sonst `card` |
| `level` | `info`, `success`, `warning`, `alarm` | `info` |
| `icon` | Font-Awesome-Name, z. B. `bell` oder `fa-light fa-bell` | Icon der Stufe |
| `image` | `https://…`, ID eines Bild-Medienobjekts oder `data:image/…;base64,…` (bis 768 kB) | – |
| `sound` | ID eines Sound-Medienobjekts (mp3, wav, ogg, m4a …, bis knapp 1 MB) | – |
| `html` | HTML, auch mit Skripten (bis 256 kB) | – |
| `overlay` | `true` / `false` | aus dem Formular |
| `duration` | Sekunden, `0` = bleibt offen | aus dem Formular |
| `size` | `small`, `medium`, `large`, `full` oder `{"width": "600px", "height": "70vh"}` (px, %, vw, vh) | aus dem Formular |
| `id` | eigene Kennung; gleiche ID ersetzt die Nachricht | zufällig |

Weitere Funktionen: `NTF_Delete($id, $MessageID)`, `NTF_Clear($id)`, `NTF_MarkAllRead($id)`, `NTF_GetNotifications($id)` (JSON).

## Overlay

- **Standard:** Das Overlay erscheint, solange die Seite mit der Kachel offen ist. Beim Wechsel auf eine andere Seite schließt es sich.
- **Auf jeder Seite** (Schalter im Formular): Die Kachel hinterlegt beim ersten Laden einen kleinen Empfänger in der Browser-Visualisierung. Er fragt alle paar Sekunden nach neuen Nachrichten und zeigt sie auf jeder Seite. Dafür muss die Kachel in dieser Sitzung einmal geladen gewesen sein, am besten liegt sie auf der Startseite.
- **Symcon-App:** Die App gibt Kacheln keinen Zugriff auf die übrige Visualisierung. Dort erscheint das Overlay innerhalb der Kachel.
- Jede Nachricht erscheint in jedem Browser nur einmal als Overlay. Nach dem Neuladen springen nur Nachrichten der letzten zwei Minuten auf.

Das Overlay nutzt den Zugriff einer Kachel auf das Fenster der Visualisierung. Das ist keine zugesicherte Schnittstelle von Symcon. Ändert Symcon das, zeigt die Kachel das Overlay in sich selbst.

## Ton

- Der Ton spielt einmal, wenn die Nachricht neu ankommt: mit ihrem Overlay, oder ohne Overlay allein. Beim späteren Öffnen aus der Liste spielt er nicht noch einmal.
- Es gelten dieselben Regeln wie fürs Overlay: im Standard nur, solange die Seite mit der Kachel offen ist, mit „auf jeder Seite“ überall.
- Browser spielen Töne erst, nachdem die Seite einmal bedient wurde, also nach einem Klick oder Tipp. Eine frisch geöffnete Visu, in die noch niemand getippt hat, bleibt stumm.

## Sicherheit

- Titel und Text werden immer als Text dargestellt, nie als HTML.
- HTML läuft in einem eigenen, abgeschotteten Rahmen (`sandbox` ohne `allow-same-origin`, dazu eine Content-Security-Policy). Skripte darin laufen, kommen aber nicht an die Visualisierung, ihre Daten oder den Zugriffsschlüssel. Fenster öffnen und Formulare sind erlaubt.
- Bilder und HTML kommen über einen Hook mit zufälligem Schlüssel je Instanz.

## Grenzen

- Gespeichert werden höchstens die eingestellte Anzahl Nachrichten (Vorgabe 50), insgesamt höchstens 2 MB. Die ältesten fallen zuerst weg.
- Bilder und Töne aus Medienobjekten werden bis knapp 1 MB ausgeliefert.

## Voraussetzungen

Symcon 8.2 oder neuer, Kachel-Visualisierung.
