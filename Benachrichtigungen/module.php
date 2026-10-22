<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/NotifyStore.php';

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
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
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
            $msg = NotifyStore::Normalize($Title, $Text, $Options, $this->Defaults(), bin2hex(random_bytes(6)));
        } catch (InvalidArgumentException $e) {
            throw new Exception($this->Translate($e->getMessage()));
        }
        if (is_array($msg['image']) && $msg['image']['kind'] === 'media') {
            if ($this->MediaImage((int) $msg['image']['value'], false) === null) {
                throw new Exception($this->Translate('Image media not found'));
            }
        }
        $this->Mutate(function (array $list) use ($msg): array {
            $seq = $this->ReadAttributeInteger('Seq') + 1;
            $this->WriteAttributeInteger('Seq', $seq);
            return [NotifyStore::Insert($list, $msg, $seq, time()), null];
        });
        $this->SendDebug('Send', $msg['id'] . ' ' . $msg['title'], 0);
        return $msg['id'];
    }

    public function Delete(string $MessageID): bool
    {
        return (bool) $this->Mutate(static function (array $list) use ($MessageID): array {
            $rest = NotifyStore::Remove($list, $MessageID);
            return [$rest, count($rest) !== count($list)];
        });
    }

    public function Clear(): void
    {
        $this->Mutate(static fn(array $list): array => [[], null]);
    }

    public function MarkAllRead(): void
    {
        $this->Mutate(static fn(array $list): array => [NotifyStore::MarkRead($list, null)[0], null]);
    }

    /** Alle Nachrichten mit HTML und Bildangabe, neueste zuerst. */
    public function GetNotifications(): string
    {
        return (string) json_encode($this->Load(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
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
                $this->Mutate(static function (array $list) use ($id): array {
                    [$list, $changed] = NotifyStore::MarkRead($list, $id);
                    return [$list, $changed];
                });
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
     *   k=overlay.js[&v=]        Overlay-Code für das Hauptfenster
     *   k=poll&since=<seq>       neue Overlay-Nachrichten (nur mit „Auf jeder Seite")
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
                    if ((int) $m['seq'] > $since && $m['overlay']) {
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
            $media = $this->MediaImage((int) $img['value'], true);
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

    /** Bild-Medienobjekt (Typ 1): Datei und, auf Wunsch, Inhalt als Base64; null, wenn es keines ist. */
    protected function MediaImage(int $id, bool $withContent): ?array
    {
        if (!IPS_MediaExists($id)) {
            return null;
        }
        $media = IPS_GetMedia($id);
        if ((int) ($media['MediaType'] ?? -1) !== 1) {
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
