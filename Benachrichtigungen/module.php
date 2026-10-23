<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/NotifyStore.php';
require_once __DIR__ . '/../libs/VisuSync.php';

/**
 * Benachrichtigungs-Kachel: Skripte und Module schicken Nachrichten (NTF_Send), die Kachel zeigt sie
 * als Liste und auf Wunsch als Overlay über der ganzen Visualisierung.
 *
 * - Nachrichten liegen im Attribut `Messages` (dauerhaft, begrenzt nach Anzahl, Alter und Bytes).
 * - Die Kachel bekommt nur die öffentliche Sicht (NotifyStore::Summary); HTML-Körper, eingebettete
 *   Bilder und der Overlay-Code kommen über den Hook /hook/notify/<ID> mit Token.
 * - HTML läuft im Overlay in einem abgeschotteten iframe (sandbox ohne allow-same-origin); der Hook
 *   setzt zusätzlich CSP sandbox, damit auch ein direkt geöffneter Körper keinen Zugriff auf die Visu hat.
 */
class NotificationTile extends IPSModuleStrict
{
    private const HOOK = 'notify';
    private const LEVEL_ICONS = ['info' => 'circle-info', 'success' => 'circle-check', 'warning' => 'triangle-exclamation', 'alarm' => 'siren-on'];
    /** Kern-Instanz „Notification Control" (verwaltet die Benachrichtigungen der Kachel-Visu). */
    private const NC_GUID = '{D4B231D6-8141-4B9E-9B32-82DA3AEEAB78}';
    /** Fallback-Abgleich, falls keine Nachricht der Notification Control kommt. */
    private const SYNC_MS = 30000;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('OverlayDefault', true);
        $this->RegisterPropertyInteger('DefaultDuration', 10);
        $this->RegisterPropertyString('DefaultSize', 'medium');
        $this->RegisterPropertyBoolean('OverlayEverywhere', false);
        $this->RegisterPropertyInteger('PollInterval', 2);
        $this->RegisterPropertyInteger('MaxMessages', 50);
        $this->RegisterPropertyInteger('MaxAgeDays', 0);
        // Abgleich mit der Glocke einer Kachel-Visu (Spiegeln) und Weiterleiten als Push.
        $this->RegisterPropertyInteger('MirrorVisuID', 0);
        $this->RegisterPropertyBoolean('MirrorOverlay', true);
        $this->RegisterPropertyInteger('ForwardVisuID', 0);
        $this->RegisterPropertyBoolean('ForwardDefault', false);
        $this->RegisterAttributeInteger('NcMaxImported', 0);
        $this->RegisterAttributeInteger('NcMirrorVisu', 0);
        $this->RegisterAttributeInteger('NcWatched', 0);
        $this->RegisterTimer('VisuSync', 0, 'NTF_SyncVisu($_IPS[\'TARGET\']);');
        // Baukasten: Vorlagen-Tabelle; die eingebauten Vorlagen stehen als Werkszeilen darin.
        $this->RegisterPropertyString('Templates', (string) json_encode(NotifyStore::DefaultTemplateRows()));

        $this->RegisterAttributeString('Messages', '[]');
        $this->RegisterAttributeInteger('Seq', 0);
        $this->RegisterAttributeString('HookToken', '');

        $this->RegisterVariableInteger('Unread', $this->Translate('Unread'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'bell',
        ], 10);

        $this->SetBuffer('Hook', $this->RegisterHook(self::HOOK . '/' . $this->InstanceID) ? '1' : '');
        // Ab Symcon 9.1 auch in der geöffneten (maximierten) Kachel.
        $this->SetVisualizationType(defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') ? INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN : 1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        if ($this->ReadAttributeString('HookToken') === '') {
            $this->WriteAttributeString('HookToken', bin2hex(random_bytes(16)));
        }
        // Neue Grenzen sofort anwenden.
        $this->Mutate(static fn(array $list): array => [$list, null]);
        $this->SetupVisuSync();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message === IM_CHANGEATTRIBUTE && $SenderID === $this->ReadAttributeInteger('NcWatched')) {
            $this->SendDebug('VisuSync', 'IM_CHANGEATTRIBUTE der Notification Control', 0);
            $this->SyncVisu();
        }
    }

    // ── Öffentliche Funktionen ──────────────────────────────────────────────────

    /**
     * Neue Nachricht. `$Options` ist JSON (leer = Vorgaben): icon, image, html, level, overlay,
     * duration, size, id. Liefert die Nachrichten-ID.
     */
    public function Send(string $Title, string $Text, string $Options): string
    {
        try {
            $msg = NotifyStore::Normalize($Title, $Text, $Options, $this->Defaults(), bin2hex(random_bytes(6)), $this->Templates());
        } catch (InvalidArgumentException $e) {
            throw new Exception($this->Translate($e->getMessage()));
        }
        if (is_array($msg['image']) && $msg['image']['kind'] === 'media') {
            if ($this->MediaObject((int) $msg['image']['value'], 1, false) === null) {
                throw new Exception($this->Translate('Image media not found'));
            }
        }
        if ($msg['sound'] > 0 && $this->MediaObject($msg['sound'], 2, false) === null) {
            throw new Exception($this->Translate('Sound media not found'));
        }
        // Weiterleiten in die Glocke einer Visu (Push aufs Handy): Option push, sonst Formular-Vorgabe.
        $o = json_decode($Options, true);
        $forwardVisu = $this->ReadPropertyInteger('ForwardVisuID');
        $push = $forwardVisu > 0 && (is_array($o) && array_key_exists('push', $o) ? (bool) $o['push'] : $this->ReadPropertyBoolean('ForwardDefault'));
        if ($push) {
            // Merker, damit der Abgleich den Eintrag in der Glocke verknüpft statt ihn doppelt zu übernehmen.
            $msg['forwardKey'] = VisuSync::Key($msg['title'], $msg['text']);
            $msg['forwardVisu'] = $forwardVisu;
        }
        $this->Mutate(function (array $list) use ($msg): array {
            $seq = $this->ReadAttributeInteger('Seq') + 1;
            $this->WriteAttributeInteger('Seq', $seq);
            return [NotifyStore::Insert($list, $msg, $seq, time()), null];
        });
        $this->SendDebug('Send', $msg['id'] . ' ' . $msg['title'], 0);
        if ($push) {
            $icon = $msg['icon'] !== '' ? $msg['icon'] : (self::LEVEL_ICONS[$msg['level']] ?? 'bell');
            if (!$this->VisuPost($forwardVisu, $msg['title'], $msg['text'], $icon)) {
                $this->SendDebug('Send', 'Weiterleiten an Visu #' . $forwardVisu . ' gescheitert', 0);
            }
        }
        return $msg['id'];
    }

    public function Delete(string $MessageID): bool
    {
        $nc = (int) (NotifyStore::Find($this->Load(), $MessageID)['nc']['id'] ?? 0);
        $ok = (bool) $this->Mutate(static function (array $list) use ($MessageID): array {
            $rest = NotifyStore::Remove($list, $MessageID);
            return [$rest, count($rest) !== count($list)];
        });
        if ($ok && $nc > 0) {
            $this->NcDelete($nc); // auch aus der Glocke der Visu
        }
        return $ok;
    }

    public function Clear(): void
    {
        $ncIds = $this->NcIds($this->Load(), false);
        $this->Mutate(static fn(array $list): array => [[], null]);
        foreach ($ncIds as $nc) {
            $this->NcDelete($nc);
        }
    }

    public function MarkAllRead(): void
    {
        $ncIds = $this->NcIds($this->Load(), true);
        $this->Mutate(static fn(array $list): array => [NotifyStore::MarkRead($list, null)[0], null]);
        foreach ($ncIds as $nc) {
            $this->NcRead($nc);
        }
    }

    /**
     * Abgleich mit der Glocke der gespiegelten Visu: neue Einträge übernehmen, Gelesen/Gelöscht
     * nachziehen, weitergeleitete eigene Nachrichten verknüpfen (libs/VisuSync.php).
     * Läuft bei jeder Änderung der Notification Control (IM_CHANGEATTRIBUTE) und als Rückfall per Timer.
     */
    public function SyncVisu(): void
    {
        $visu = $this->ReadPropertyInteger('MirrorVisuID');
        $nc = $visu > 0 ? $this->NcList() : null;
        if ($nc === null) {
            return;
        }
        $plan = VisuSync::Plan($this->Load(), $nc, $visu, $this->ReadAttributeInteger('NcMaxImported'));
        if (!$plan['imports'] && !$plan['links'] && !$plan['reads'] && !$plan['removes']) {
            if ($plan['max'] !== $this->ReadAttributeInteger('NcMaxImported')) {
                $this->WriteAttributeInteger('NcMaxImported', $plan['max']);
            }
            // Auch ein leerer erster Abgleich ist der erste: sonst gälte die nächste neue
            // Benachrichtigung noch als Altbestand und käme ohne Overlay (gesehen 22.10.2026).
            $this->SetBuffer('NcFirst', '');
            return;
        }
        $first = $this->GetBuffer('NcFirst') === '1';
        $overlay = $this->ReadPropertyBoolean('MirrorOverlay');
        $templates = $this->Templates();
        $defaults = $this->Defaults();
        $this->Mutate(function (array $list) use ($nc, $visu, $first, $overlay, $templates, $defaults): array {
            // Im Semaphor neu planen: zwischen Lesen und Sperren kann sich die Liste geändert haben.
            $plan = VisuSync::Plan($list, $nc, $visu, $this->ReadAttributeInteger('NcMaxImported'));
            foreach ($list as &$m) {
                if (isset($plan['links'][$m['id']])) {
                    $m['nc'] = ['id' => $plan['links'][$m['id']], 'visu' => $visu, 'origin' => 'tile'];
                    unset($m['forwardKey'], $m['forwardVisu']);
                }
                if (in_array($m['id'], $plan['reads'], true)) {
                    $m['read'] = true;
                }
            }
            unset($m);
            foreach ($plan['removes'] as $id) {
                $list = NotifyStore::Remove($list, $id);
            }
            $jetzt = time();
            foreach ($plan['imports'] as $n) {
                $neu = $overlay && !$first && (int) ($n['SentTime'] ?? 0) >= $jetzt - 120;
                // Stichwort einer Vorlage im Titel: die ganze Vorlage gilt (Aussehen, Stufe, Icon, Farbe, Ton …).
                $vorlage = NotifyStore::TemplateForTitle((string) ($n['Title'] ?? ''), $templates);
                if ($vorlage !== '') {
                    $options = ['template' => $vorlage, 'overlay' => $neu && ($templates[$vorlage]['overlay'] ?? true)];
                } else {
                    $map = VisuSync::MapIcon((string) ($n['Icon'] ?? ''));
                    $options = ['level' => $map['level'], 'overlay' => $neu];
                    if ($map['icon'] !== '') {
                        $options['icon'] = $map['icon'];
                    }
                }
                try {
                    $msg = NotifyStore::Normalize((string) ($n['Title'] ?? ''), (string) ($n['Body'] ?? ''), (string) json_encode($options),
                        $defaults, 'nc-' . (int) $n['NotificationID'], $templates);
                } catch (InvalidArgumentException $e) {
                    continue; // leerer Eintrag
                }
                $msg['read'] = !empty($n['IsRead']);
                $msg['nc'] = ['id' => (int) $n['NotificationID'], 'visu' => $visu, 'origin' => 'visu', 'target' => (int) ($n['TargetID'] ?? 0)];
                $seq = $this->ReadAttributeInteger('Seq') + 1;
                $this->WriteAttributeInteger('Seq', $seq);
                $list = NotifyStore::Insert($list, $msg, $seq, (int) ($n['SentTime'] ?? $jetzt) ?: $jetzt);
            }
            // Neueste oben, auch wenn ältere Einträge der Glocke nachträglich hinzukamen.
            usort($list, static fn(array $a, array $b): int => ((int) $b['time'] <=> (int) $a['time']) ?: ((int) $b['seq'] <=> (int) $a['seq']));
            $this->WriteAttributeInteger('NcMaxImported', $plan['max']);
            return [$list, null];
        });
        $this->SetBuffer('NcFirst', '');
        $this->SendDebug('VisuSync', sprintf('übernommen %d, verknüpft %d, gelesen %d, gelöscht %d',
            count($plan['imports']), count($plan['links']), count($plan['reads']), count($plan['removes'])), 0);
    }

    /** Alle Nachrichten mit HTML und Bildangabe, neueste zuerst. */
    public function GetNotifications(): string
    {
        return (string) json_encode($this->Load(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Namen aller Vorlagen (eingebaute und eigene), sortiert, als JSON-Liste. */
    public function GetTemplates(): string
    {
        $names = array_keys($this->Templates());
        sort($names);
        return (string) json_encode($names);
    }

    /** Knopf „Testnachricht mit Vorlage" im Formular. */
    public function SendTemplateTest(string $Template): string
    {
        return $this->Send(
            sprintf($this->Translate('Template "%s"'), $Template),
            $this->Translate('This is how a notification with this template looks.'),
            (string) json_encode(['template' => $Template])
        );
    }

    /** Formular mit den aktuellen Vorlagen in der Test-Auswahl. */
    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $options = [];
        foreach (json_decode($this->GetTemplates(), true) as $name) {
            $options[] = ['caption' => $name, 'value' => $name];
        }
        // Vorschaubilder der Darstellungen (assets/layouts/*.svg) mit Beschriftung in den Bereich „Vorlagen".
        foreach ($form['elements'] as &$el) {
            if (($el['type'] ?? '') === 'ExpansionPanel' && ($el['caption'] ?? '') === 'Templates') {
                array_splice($el['items'], 1, 0, [$this->LayoutPreviews()]);
            }
        }
        unset($el);
        $form['actions'] = array_map(static function (array $el) use ($options): array {
            if (($el['type'] ?? '') === 'RowLayout') {
                foreach ($el['items'] as &$item) {
                    if (($item['name'] ?? '') === 'TestTemplate') {
                        $item['options'] = $options;
                        $item['value'] = $options[0]['value'] ?? '';
                    }
                }
                unset($item);
            }
            return $el;
        }, $form['actions']);
        return (string) json_encode($form);
    }

    /** Bildreihe der sechs Darstellungen für das Formular: je Spalte Bild und Beschriftung mit `layout`-Wert. */
    private function LayoutPreviews(): array
    {
        $namen = ['card' => 'Card', 'banner' => 'Banner', 'hero' => 'Big icon', 'toast' => 'Small, top', 'photo' => 'Image on top', 'fullscreen' => 'Fullscreen message'];
        $spalten = [];
        foreach ($namen as $layout => $name) {
            $svg = @file_get_contents(__DIR__ . '/assets/layouts/' . $layout . '.svg');
            if (!is_string($svg)) {
                continue;
            }
            $spalten[] = ['type' => 'ColumnLayout', 'items' => [
                ['type' => 'Image', 'image' => 'data:image/svg+xml;base64,' . base64_encode($svg), 'width' => '120px'],
                ['type' => 'Label', 'caption' => $this->Translate($name) . ' (' . $layout . ')'],
            ]];
        }
        return ['type' => 'RowLayout', 'items' => $spalten];
    }

    /** Knopf im Formular. */
    public function SendTest(): string
    {
        return $this->Send($this->Translate('Test message'), $this->Translate('This is how a notification looks.'), (string) json_encode([
            'level' => 'success',
            'html'  => '<p>' . $this->Translate('HTML content with a script:') . ' <b id="t"></b></p>'
                . '<script>document.getElementById("t").textContent = new Date().toLocaleTimeString();</script>',
        ]));
    }

    // ── Kachel ─────────────────────────────────────────────────────────────────

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'GetState':
                $this->Push($Value);
                return;
            case 'Read':
                $id = $this->MessageId($Value);
                $changed = $this->Mutate(static function (array $list) use ($id): array {
                    [$list, $changed] = NotifyStore::MarkRead($list, $id);
                    return [$list, $changed];
                });
                $nc = (int) (NotifyStore::Find($this->Load(), $id)['nc']['id'] ?? 0);
                if ($changed && $nc > 0) {
                    $this->NcRead($nc); // auch in der Glocke der Visu
                }
                return;
            case 'ReadAll':
                $this->MarkAllRead();
                return;
            case 'Delete':
                $this->Delete($this->MessageId($Value));
                return;
            case 'Clear':
                $this->Clear();
                return;
            default:
                throw new Exception($this->Translate('Invalid Ident'));
        }
    }

    public function GetVisualizationTile(): string
    {
        $html = (string) @file_get_contents(__DIR__ . '/module.html');
        $init = [
            'state'  => $this->State(),
            'labels' => $this->Labels(),
            'instance' => $this->InstanceID,
        ];
        // JSON_HEX_TAG: Die Nutzlast steht in einem <script>-Block; ein „</script>" in einem Titel beendete ihn sonst.
        $json = (string) json_encode($init, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return str_replace('/*NTF_INIT*/null', $json, $html);
    }

    // ── Hook ───────────────────────────────────────────────────────────────────

    /**
     * /hook/notify/<ID>?t=<Token>&k=…
     *   k=html&m=<id>[&c=<hex>]  HTML-Körper als Dokument (CSP sandbox)
     *   k=img&m=<id>             Bild aus Medienobjekt oder data-URI
     *   k=snd&m=<id>             Ton aus Sound-Medienobjekt (mit Range für Safari)
     *   k=overlay.js[&v=]        Overlay-Code für das Hauptfenster
     *   k=poll&since=<seq>       neue Overlay-Nachrichten (nur mit „Auf jeder Seite")
     *   k=delete&m=<id>  (POST)  Nachricht löschen (Mülleimer im Overlay; die Kachel kann schon entladen sein)
     */
    protected function ProcessHookData(): void
    {
        $token = $this->ReadAttributeString('HookToken');
        $given = isset($_GET['t']) && is_string($_GET['t']) ? $_GET['t'] : '';
        if ($token === '' || !hash_equals($token, $given)) {
            $this->SendStatus(403);
            return;
        }
        $this->SendHeader('X-Content-Type-Options: nosniff');
        $k = isset($_GET['k']) && is_string($_GET['k']) ? $_GET['k'] : '';
        switch ($k) {
            case 'overlay.js':
                $code = (string) @file_get_contents(__DIR__ . '/../libs/overlay.js');
                $current = isset($_GET['v']) && $_GET['v'] === $this->OverlayVersion();
                $this->SendHeader('Content-Type: application/javascript; charset=utf-8');
                $this->SendHeader('Cache-Control: ' . ($current ? 'private, max-age=31536000, immutable' : 'no-cache'));
                echo $code;
                return;
            case 'poll':
                $since = isset($_GET['since']) && is_numeric($_GET['since']) ? (int) $_GET['since'] : 0;
                if (!$this->ReadPropertyBoolean('OverlayEverywhere')) {
                    $this->SendStatus(404);
                    return;
                }
                $base = $this->HookBase();
                $neu = [];
                foreach ($this->Load() as $m) {
                    if ((int) $m['seq'] > $since && ($m['overlay'] || (int) ($m['sound'] ?? 0) > 0)) {
                        $neu[] = NotifyStore::Summary($m, $base);
                    }
                }
                $this->SendHeader('Content-Type: application/json; charset=utf-8');
                $this->SendHeader('Cache-Control: no-store');
                echo json_encode(['seq' => $this->ReadAttributeInteger('Seq'), 'messages' => array_reverse($neu)],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                return;
            case 'html':
                $m = $this->HookMessage();
                if ($m === null || $m['html'] === '') {
                    $this->SendStatus(404);
                    return;
                }
                $color = isset($_GET['c']) && is_string($_GET['c']) ? $_GET['c'] : '';
                $this->SendHeader('Content-Type: text/html; charset=utf-8');
                $this->SendHeader('Cache-Control: no-store');
                // Auch direkt geöffnet ohne Zugriff auf die Visu: eigener, undurchsichtiger Ursprung.
                $this->SendHeader('Content-Security-Policy: sandbox allow-scripts allow-popups allow-forms');
                echo NotifyStore::HtmlDocument($m['html'], (string) $m['id'], $color);
                return;
            case 'img':
                $m = $this->HookMessage();
                $image = $m === null ? null : $this->ImageBytes($m);
                if ($image === null) {
                    $this->SendStatus(404);
                    return;
                }
                $etag = '"' . (int) $m['seq'] . '"';
                $this->SendHeader('Content-Type: ' . $image['mime']);
                $this->SendHeader('Cache-Control: private, max-age=86400');
                $this->SendHeader('ETag: ' . $etag);
                // Ein SVG ist ein Dokument: direkt geöffnet darf es nichts ausführen.
                $this->SendHeader("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
                if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
                    $this->SendStatus(304);
                    return;
                }
                echo $image['bytes'];
                return;
            case 'delete':
                if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
                    $this->SendHeader('Allow: POST');
                    $this->SendStatus(405);
                    return;
                }
                $id = isset($_GET['m']) && is_string($_GET['m']) ? $_GET['m'] : '';
                if (!NotifyStore::ValidId($id)) {
                    $this->SendStatus(400);
                    return;
                }
                $this->SendHeader('Cache-Control: no-store');
                $this->SendStatus($this->Delete($id) ? 204 : 404);
                return;
            case 'snd':
                $m = $this->HookMessage();
                $sound = $m === null ? null : $this->SoundBytes($m);
                if ($sound === null) {
                    $this->SendStatus(404);
                    return;
                }
                $etag = '"s' . (int) $m['seq'] . '"';
                $this->SendHeader('Content-Type: ' . $sound['mime']);
                $this->SendHeader('Cache-Control: private, max-age=86400');
                $this->SendHeader('ETag: ' . $etag);
                $this->SendHeader('Accept-Ranges: bytes');
                if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
                    $this->SendStatus(304);
                    return;
                }
                $bytes = $sound['bytes'];
                $total = strlen($bytes);
                // Safari lädt Audio nur mit Teil-Antworten (206).
                if (preg_match('/^bytes=(\d*)-(\d*)$/', trim((string) ($_SERVER['HTTP_RANGE'] ?? '')), $r) === 1 && ($r[1] !== '' || $r[2] !== '')) {
                    $start = $r[1] === '' ? max(0, $total - (int) $r[2]) : (int) $r[1];
                    $end = $r[1] === '' || $r[2] === '' ? $total - 1 : min((int) $r[2], $total - 1);
                    if ($start > $end || $start >= $total) {
                        $this->SendHeader('Content-Range: bytes */' . $total);
                        $this->SendStatus(416);
                        return;
                    }
                    $this->SendStatus(206);
                    $this->SendHeader('Content-Range: bytes ' . $start . '-' . $end . '/' . $total);
                    echo substr($bytes, $start, $end - $start + 1);
                    return;
                }
                echo $bytes;
                return;
            default:
                $this->SendStatus(404);
        }
    }

    protected function SendHeader(string $header): void
    {
        header($header);
    }

    protected function SendStatus(int $code): void
    {
        http_response_code($code);
    }

    // ── Intern ─────────────────────────────────────────────────────────────────

    private function Defaults(): array
    {
        $size = $this->ReadPropertyString('DefaultSize');
        return [
            'overlay'  => $this->ReadPropertyBoolean('OverlayDefault'),
            'duration' => max(0, $this->ReadPropertyInteger('DefaultDuration')),
            'size'     => in_array($size, NotifyStore::SIZES, true) ? $size : 'medium',
        ];
    }

    /**
     * Abgleich einrichten: Nachricht der Notification Control abonnieren, Rückfall-Timer, und bei
     * einer neu gewählten Visu deren vorhandene Einträge einmal ohne Overlay übernehmen.
     */
    private function SetupVisuSync(): void
    {
        $alt = $this->ReadAttributeInteger('NcWatched');
        if ($alt > 0 && @IPS_ObjectExists($alt)) {
            $this->UnregisterMessage($alt, IM_CHANGEATTRIBUTE);
        }
        $this->WriteAttributeInteger('NcWatched', 0);
        $visu = $this->ReadPropertyInteger('MirrorVisuID');
        $nc = $this->NcInstance();
        if ($visu <= 0 || $nc <= 0) {
            $this->SetTimerInterval('VisuSync', 0);
            return;
        }
        if ($this->ReadAttributeInteger('NcMirrorVisu') !== $visu) {
            $this->WriteAttributeInteger('NcMirrorVisu', $visu);
            $this->WriteAttributeInteger('NcMaxImported', 0);
            $this->SetBuffer('NcFirst', '1');
        }
        $this->RegisterMessage($nc, IM_CHANGEATTRIBUTE);
        $this->WriteAttributeInteger('NcWatched', $nc);
        $this->SetTimerInterval('VisuSync', self::SYNC_MS);
        $this->SyncVisu();
    }

    /** IDs der verknüpften Glocken-Einträge; mit `$nurUngelesen` nur die noch ungelesenen. */
    private function NcIds(array $list, bool $nurUngelesen): array
    {
        $ids = [];
        foreach ($list as $m) {
            $id = (int) ($m['nc']['id'] ?? 0);
            if ($id > 0 && (!$nurUngelesen || empty($m['read']))) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    protected function NcInstance(): int
    {
        $list = @IPS_GetInstanceListByModuleID(self::NC_GUID);
        return is_array($list) && $list !== [] ? (int) $list[0] : 0;
    }

    /** Alle Visu-Benachrichtigungen; null, wenn die Notification Control nicht antwortet (NC_* nicht dokumentiert). */
    protected function NcList(): ?array
    {
        $nc = $this->NcInstance();
        if ($nc <= 0 || !function_exists('NC_GetNotifications')) {
            return null;
        }
        try {
            $r = @NC_GetNotifications($nc);
        } catch (\Throwable $e) {
            $this->SendDebug('VisuSync', $e->getMessage(), 0);
            return null;
        }
        $r = is_string($r) ? json_decode($r, true) : $r;
        return is_array($r) ? $r : null;
    }

    protected function NcRead(int $id): void
    {
        $nc = $this->NcInstance();
        if ($nc > 0 && function_exists('NC_ReadNotification')) {
            try {
                @NC_ReadNotification($nc, $id);
            } catch (\Throwable $e) {
                $this->SendDebug('VisuSync', 'Gelesen #' . $id . ': ' . $e->getMessage(), 0);
            }
        }
    }

    protected function NcDelete(int $id): void
    {
        $nc = $this->NcInstance();
        if ($nc > 0 && function_exists('NC_DeleteNotification')) {
            try {
                @NC_DeleteNotification($nc, $id);
            } catch (\Throwable $e) {
                $this->SendDebug('VisuSync', 'Löschen #' . $id . ': ' . $e->getMessage(), 0);
            }
        }
    }

    /** In die Glocke einer Visu (und als Push an deren Geräte) senden — dokumentierte Visu-Funktion. */
    protected function VisuPost(int $visu, string $title, string $text, string $icon): bool
    {
        if (!function_exists('VISU_PostNotificationEx') || !@IPS_InstanceExists($visu)) {
            return false;
        }
        try {
            return @VISU_PostNotificationEx($visu, $title, $text, $icon, '', 0) !== false;
        } catch (\Throwable $e) {
            $this->SendDebug('Send', $e->getMessage(), 0);
            return false;
        }
    }

    /** Eingebaute Vorlagen, ergänzt und ersetzt durch die Zeilen der Tabelle. */
    private function Templates(): array
    {
        $rows = json_decode($this->ReadPropertyString('Templates'), true);
        return NotifyStore::TemplatesFromRows(is_array($rows) ? $rows : []);
    }

    private function Load(): array
    {
        $list = json_decode($this->ReadAttributeString('Messages'), true);
        return is_array($list) ? $list : [];
    }

    /**
     * Liste ändern unter Semaphor (Skripte können gleichzeitig senden), danach kürzen, speichern,
     * Ungelesen-Zähler setzen und die Kachel benachrichtigen. `$change` liefert [Liste, Ergebnis].
     */
    private function Mutate(callable $change): mixed
    {
        $sem = 'NTF_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 5000)) {
            throw new Exception($this->Translate('Notification store is busy'));
        }
        try {
            [$list, $result] = $change($this->Load());
            $list = NotifyStore::Trim($list, max(1, $this->ReadPropertyInteger('MaxMessages')), max(0, $this->ReadPropertyInteger('MaxAgeDays')), time());
            $list = NotifyStore::TrimBytes($list, NotifyStore::MAX_STORE_BYTES);
            $this->WriteAttributeString('Messages', (string) json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        } finally {
            IPS_SemaphoreLeave($sem);
        }
        $this->SetValue('Unread', NotifyStore::Unread($list));
        $this->Push();
        return $result;
    }

    /** Stand für die Kachel; mit `{"hash": …}` der Kachel nur, wenn er sich geändert hat. */
    private function Push(mixed $known = null): void
    {
        $state = $this->State();
        if (is_string($known) && $known !== '' && $known[0] === '{') {
            $k = json_decode($known, true);
            if (is_array($k) && is_string($k['hash'] ?? null) && hash_equals($state['hash'], $k['hash'])) {
                return;
            }
        }
        $this->UpdateVisualizationValue((string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function State(): array
    {
        $base = $this->HookBase();
        $list = $this->Load();
        $state = [
            'type'     => 'state',
            'messages' => array_map(static fn(array $m): array => NotifyStore::Summary($m, $base), $list),
            'unread'   => NotifyStore::Unread($list),
            'seq'      => $this->ReadAttributeInteger('Seq'),
            'config'   => [
                'everywhere' => $this->ReadPropertyBoolean('OverlayEverywhere') && $base !== '',
                'pollMs'     => min(60, max(1, $this->ReadPropertyInteger('PollInterval'))) * 1000,
                'hookBase'   => $base,
                'overlayJs'  => $base === '' ? '' : $base . '&k=overlay.js&v=' . $this->OverlayVersion(),
                'levelIcons' => self::LEVEL_ICONS,
            ],
        ];
        $state['hash'] = substr(md5((string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)), 0, 16);
        return $state;
    }

    private function Labels(): array
    {
        $t = fn(string $s): string => $this->Translate($s);
        return [
            'empty' => $t('No messages'), 'clear' => $t('Delete all'), 'close' => $t('Close'),
            'delete' => $t('Delete'), 'more' => $t('+%1 more'), 'now' => $t('just now'),
            'min' => $t('%1 min ago'), 'hour' => $t('%1 h ago'), 'confirmClear' => $t('Delete all messages?'),
            'next' => $t('Next'), 'unread' => $t('%1 unread'), 'total' => $t('%1 messages'), 'one' => $t('1 message'),
        ];
    }

    private function HookBase(): string
    {
        $token = $this->ReadAttributeString('HookToken');
        return $this->GetBuffer('Hook') === '' || $token === '' ? '' : '/hook/' . self::HOOK . '/' . $this->InstanceID . '?t=' . $token;
    }

    private function OverlayVersion(): string
    {
        return substr(md5((string) @file_get_contents(__DIR__ . '/../libs/overlay.js')), 0, 12);
    }

    private function HookMessage(): ?array
    {
        $id = isset($_GET['m']) && is_string($_GET['m']) ? $_GET['m'] : '';
        return NotifyStore::ValidId($id) ? NotifyStore::Find($this->Load(), $id) : null;
    }

    /** Bild einer Nachricht als Bytes; null für URL-Bilder, fehlende Medien und über der Hook-Grenze. */
    private function ImageBytes(array $m): ?array
    {
        $img = $m['image'] ?? null;
        if (!is_array($img)) {
            return null;
        }
        if ($img['kind'] === 'data') {
            $parsed = NotifyStore::ParseDataImage((string) $img['value']);
        } elseif ($img['kind'] === 'media') {
            $media = $this->MediaObject((int) $img['value'], 1, true);
            $bytes = $media === null ? false : base64_decode($media['content'], true);
            $mime = $media === null ? '' : NotifyStore::MimeForFile($media['file']);
            if ($bytes !== false && $mime === '') {
                $mime = NotifyStore::SniffImage($bytes);
            }
            $parsed = $mime === '' || $bytes === false ? null : ['mime' => $mime, 'bytes' => $bytes];
        } else {
            return null;
        }
        // Ausgabegrenze einer Hook-Antwort (Werkswert 1 MiB): lieber kein Bild als eine kaputte Datei.
        return $parsed !== null && strlen($parsed['bytes']) <= 1000000 ? $parsed : null;
    }

    /** Ton einer Nachricht als Bytes mit Typ; null ohne Ton, bei fehlendem Medium und über der Hook-Grenze. */
    private function SoundBytes(array $m): ?array
    {
        $id = (int) ($m['sound'] ?? 0);
        $media = $id > 0 ? $this->MediaObject($id, 2, true) : null;
        $bytes = $media === null ? false : base64_decode($media['content'], true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > 1000000) {
            return null;
        }
        $mime = NotifyStore::AudioMime($media['file'], $bytes);
        return $mime === '' ? null : ['mime' => $mime, 'bytes' => $bytes];
    }

    /**
     * Medienobjekt eines Typs (1 = Bild, 2 = Ton): Datei und, auf Wunsch, Inhalt als Base64;
     * null, wenn es fehlt oder einen anderen Typ hat.
     */
    protected function MediaObject(int $id, int $type, bool $withContent): ?array
    {
        if (!IPS_MediaExists($id)) {
            return null;
        }
        $media = IPS_GetMedia($id);
        if ((int) ($media['MediaType'] ?? -1) !== $type) {
            return null;
        }
        return ['file' => (string) ($media['MediaFile'] ?? ''), 'content' => $withContent ? (string) IPS_GetMediaContent($id) : ''];
    }

    private function MessageId(mixed $value): string
    {
        $id = is_string($value) ? trim($value) : '';
        if (!NotifyStore::ValidId($id)) {
            throw new Exception($this->Translate('Invalid message id'));
        }
        return $id;
    }
}
