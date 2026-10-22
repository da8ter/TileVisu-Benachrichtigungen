<?php

declare(strict_types=1);

/**
 * Reine Bausteine der Benachrichtigungs-Kachel, ohne Symcon: Nachricht prüfen und
 * normalisieren, Liste pflegen, öffentliche Sicht für die Kachel, HTML-Dokument für
 * den abgeschotteten Rahmen.
 *
 * Fehler sind InvalidArgumentException mit englischem Schlüssel; das Modul übersetzt.
 */
final class NotifyStore
{
    public const LEVELS = ['info', 'success', 'warning', 'alarm'];
    public const SIZES = ['small', 'medium', 'large', 'full'];
    public const MAX_TITLE = 200;
    public const MAX_TEXT = 4000;
    /** HTML-Körper je Nachricht; liegt im Attribut und geht über den Hook (Grenze 1 MiB je Antwort). */
    public const MAX_HTML = 262144;
    /** Eingebettetes Bild (data-URI), dekodiert; der Hook liefert es aus und darf 1 MiB nicht reißen. */
    public const MAX_DATA_IMAGE = 786432;
    public const MAX_DURATION = 86400;
    public const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'];
    /** Gesamtgröße des Speichers (Attribut in der settings.json); älteste Nachrichten fallen zuerst weg. */
    public const MAX_STORE_BYTES = 2097152;

    /**
     * Nachricht aus den Angaben eines Skripts. `$defaults`: overlay (bool), duration (int), size (string).
     * `$newId` wird genommen, wenn die Optionen keine eigene `id` mitbringen.
     */
    public static function Normalize(string $title, string $text, string $options, array $defaults, string $newId): array
    {
        $o = self::Options($options);
        $title = self::Cut(trim($title), self::MAX_TITLE);
        $text = self::Cut(trim(str_replace("\r\n", "\n", $text)), self::MAX_TEXT);

        $id = $newId;
        if (array_key_exists('id', $o)) {
            $id = is_scalar($o['id']) ? trim((string) $o['id']) : '';
            if (!self::ValidId($id)) {
                throw new InvalidArgumentException('Invalid message id');
            }
        }

        $level = array_key_exists('level', $o) ? strtolower(trim((string) (is_scalar($o['level']) ? $o['level'] : ''))) : 'info';
        if (!in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException('Invalid level');
        }

        $html = $o['html'] ?? '';
        if (!is_string($html)) {
            throw new InvalidArgumentException('Invalid HTML');
        }
        if (strlen($html) > self::MAX_HTML) {
            throw new InvalidArgumentException('HTML too large');
        }
        $html = trim($html);

        $image = array_key_exists('image', $o) ? self::Image($o['image']) : null;

        if ($title === '' && $text === '' && $html === '' && $image === null) {
            throw new InvalidArgumentException('Message is empty');
        }

        return [
            'id'       => $id,
            'title'    => $title,
            'text'     => $text,
            'icon'     => array_key_exists('icon', $o) ? self::Icon($o['icon']) : '',
            'image'    => $image,
            'html'     => $html,
            'level'    => $level,
            'overlay'  => array_key_exists('overlay', $o) ? self::Bool($o['overlay']) : (bool) ($defaults['overlay'] ?? true),
            'duration' => array_key_exists('duration', $o) ? self::Duration($o['duration']) : self::Duration($defaults['duration'] ?? 0),
            'size'     => array_key_exists('size', $o) ? self::Size($o['size']) : self::Size($defaults['size'] ?? 'medium'),
            'read'     => false,
        ];
    }

    public static function ValidId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $id) === 1;
    }

    /** Neue Nachricht oben einfügen; eine mit gleicher ID wird ersetzt (und wandert nach oben). */
    public static function Insert(array $list, array $msg, int $seq, int $time): array
    {
        $msg['seq'] = $seq;
        $msg['time'] = $time;
        return array_merge([$msg], self::Remove($list, (string) $msg['id']));
    }

    public static function Remove(array $list, string $id): array
    {
        return array_values(array_filter($list, static fn(array $m): bool => (string) ($m['id'] ?? '') !== $id));
    }

    /** Auf höchstens `$max` Nachrichten und `$maxAgeDays` Tage kürzen (0 = ohne Grenze). */
    public static function Trim(array $list, int $max, int $maxAgeDays, int $now): array
    {
        if ($maxAgeDays > 0) {
            $limit = $now - $maxAgeDays * 86400;
            $list = array_values(array_filter($list, static fn(array $m): bool => (int) ($m['time'] ?? 0) >= $limit));
        }
        return $max > 0 ? array_slice($list, 0, $max) : $list;
    }

    /** Älteste Nachrichten entfernen, bis die Liste kodiert höchstens `$budget` Bytes hat; die neueste bleibt immer. */
    public static function TrimBytes(array $list, int $budget): array
    {
        $total = 0;
        $out = [];
        foreach ($list as $i => $m) {
            $total += strlen((string) json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) + 1;
            if ($i > 0 && $total > $budget) {
                break;
            }
            $out[] = $m;
        }
        return $out;
    }

    /** Als gelesen markieren: eine ID oder alle (`null`). Liefert die Liste und ob sich etwas änderte. */
    public static function MarkRead(array $list, ?string $id): array
    {
        $changed = false;
        foreach ($list as &$m) {
            if (($id === null || (string) $m['id'] === $id) && empty($m['read'])) {
                $m['read'] = true;
                $changed = true;
            }
        }
        unset($m);
        return [$list, $changed];
    }

    public static function Find(array $list, string $id): ?array
    {
        foreach ($list as $m) {
            if ((string) ($m['id'] ?? '') === $id) {
                return $m;
            }
        }
        return null;
    }

    public static function Unread(array $list): int
    {
        return count(array_filter($list, static fn(array $m): bool => empty($m['read'])));
    }

    /**
     * Was die Kachel sieht: ohne HTML-Körper und Bilddaten, dafür mit Adressen am Hook.
     * `$hookBase` ist `/hook/notify/<ID>?t=<Token>` oder leer (dann keine Hook-Inhalte).
     */
    public static function Summary(array $m, string $hookBase): array
    {
        $id = (string) $m['id'];
        $seq = (int) ($m['seq'] ?? 0);
        $image = '';
        $img = $m['image'] ?? null;
        if (is_array($img)) {
            if ($img['kind'] === 'url') {
                $image = (string) $img['value'];
            } elseif ($hookBase !== '') {
                $image = $hookBase . '&k=img&m=' . rawurlencode($id) . '&v=' . $seq;
            }
        }
        return [
            'id'       => $id,
            'seq'      => $seq,
            'time'     => (int) ($m['time'] ?? 0),
            'title'    => (string) $m['title'],
            'text'     => (string) $m['text'],
            'icon'     => (string) $m['icon'],
            'image'    => $image,
            'html'     => ($m['html'] ?? '') !== '' && $hookBase !== '' ? $hookBase . '&k=html&m=' . rawurlencode($id) . '&v=' . $seq : '',
            'level'    => (string) $m['level'],
            'overlay'  => (bool) $m['overlay'],
            'duration' => (int) $m['duration'],
            'size'     => $m['size'],
            'read'     => !empty($m['read']),
        ];
    }

    /**
     * HTML-Körper als eigenes Dokument für den abgeschotteten Rahmen. Am Ende meldet ein kleines
     * Skript seine Höhe an das Elternfenster (das Overlay passt den Rahmen an). `$color` ist die
     * Textfarbe der Visu als sechsstelliger Hexwert oder leer.
     */
    public static function HtmlDocument(string $html, string $msgId, string $color): string
    {
        $color = preg_match('/^[0-9a-fA-F]{6}$/', $color) === 1 ? '#' . $color : 'inherit';
        // Gemessen wird der Inhalt (Unterkante von body samt Rand), nicht scrollHeight des Dokuments:
        // das ist nie kleiner als der Rahmen selbst, der Rahmen könnte also nie schrumpfen.
        $reporter = '<script>(function(){var l=-1;function s(){var d=document.documentElement,b=document.body;'
            . 'var h=b?Math.ceil(b.getBoundingClientRect().bottom+(parseFloat(getComputedStyle(b).marginBottom)||0)+(window.scrollY||0)):d.scrollHeight;if(h!==l){l=h;'
            . 'parent.postMessage({ntfHeight:h,ntfId:' . json_encode($msgId) . '},"*");}}'
            . 'addEventListener("load",s);if(window.ResizeObserver){new ResizeObserver(s).observe(document.documentElement);}s();})();</script>';
        if (stripos($html, '<html') !== false) {
            $pos = strripos($html, '</body>');
            return $pos === false ? $html . $reporter : substr($html, 0, $pos) . $reporter . substr($html, $pos);
        }
        return '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>html,body{margin:0;background:transparent}body{display:flow-root;font:15px/1.45 system-ui,-apple-system,sans-serif;color:'
            . $color . '}img,video{max-width:100%;height:auto}</style></head><body>' . $html . $reporter . '</body></html>';
    }

    /** Bytes und Typ eines data-URI-Bildes; null, wenn es keines ist. */
    public static function ParseDataImage(string $uri): ?array
    {
        if (preg_match('#^data:(image/[a-z0-9.+\-]+);base64,(.*)$#is', $uri, $m) !== 1) {
            return null;
        }
        $mime = strtolower($m[1]);
        $bytes = base64_decode($m[2], true);
        if (!in_array($mime, self::IMAGE_TYPES, true) || $bytes === false || $bytes === '') {
            return null;
        }
        return ['mime' => $mime, 'bytes' => $bytes];
    }

    /** Bildtyp aus den ersten Bytes (Medien ohne Dateiendung); leer, wenn unbekannt. */
    public static function SniffImage(string $bytes): string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => '',
        };
    }

    /** Bildtyp aus der Dateiendung eines Medienobjekts; leer, wenn kein unterstütztes Bild. */
    public static function MimeForFile(string $file): string
    {
        $map = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
        return $map[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? '';
    }

    private static function Options(string $options): array
    {
        if (trim($options) === '') {
            return [];
        }
        $o = json_decode($options, true);
        if (!is_array($o) || array_is_list($o) && $o !== []) {
            throw new InvalidArgumentException('Options must be a JSON object');
        }
        return $o;
    }

    private static function Cut(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
    }

    /** „fa-light fa-bell", „fa-bell" und „bell" ergeben „bell". */
    private static function Icon(mixed $icon): string
    {
        if (!is_string($icon)) {
            throw new InvalidArgumentException('Invalid icon');
        }
        $parts = preg_split('/\s+/', strtolower(trim($icon))) ?: [];
        $name = '';
        foreach ($parts as $p) {
            if (in_array($p, ['fa', 'fal', 'fa-light', 'fa-regular', 'fa-solid', 'fa-thin', 'fa-duotone'], true)) {
                continue;
            }
            $name = str_starts_with($p, 'fa-') ? substr($p, 3) : $p;
        }
        if ($name !== '' && preg_match('/^[a-z0-9-]{1,60}$/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid icon');
        }
        return $name;
    }

    private static function Image(mixed $image): ?array
    {
        if ($image === null || $image === '' || $image === 0) {
            return null;
        }
        if (is_int($image) || is_string($image) && ctype_digit($image)) {
            $id = (int) $image;
            if ($id < 10000 || $id > 59999) {
                throw new InvalidArgumentException('Invalid image');
            }
            return ['kind' => 'media', 'value' => $id];
        }
        if (!is_string($image)) {
            throw new InvalidArgumentException('Invalid image');
        }
        $image = trim($image);
        if (preg_match('#^https?://#i', $image) === 1) {
            if (strlen($image) > 2048 || filter_var($image, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException('Invalid image');
            }
            return ['kind' => 'url', 'value' => $image];
        }
        if (str_starts_with(strtolower($image), 'data:')) {
            $parsed = self::ParseDataImage($image);
            if ($parsed === null) {
                throw new InvalidArgumentException('Invalid image');
            }
            if (strlen($parsed['bytes']) > self::MAX_DATA_IMAGE) {
                throw new InvalidArgumentException('Image too large');
            }
            return ['kind' => 'data', 'value' => $image];
        }
        throw new InvalidArgumentException('Invalid image');
    }

    private static function Bool(mixed $v): bool
    {
        if (is_string($v)) {
            return in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'on'], true);
        }
        return (bool) $v;
    }

    private static function Duration(mixed $v): int
    {
        if (!is_numeric($v) || (float) $v < 0) {
            throw new InvalidArgumentException('Invalid duration');
        }
        return min(self::MAX_DURATION, (int) round((float) $v));
    }

    /** Vorgabe als Name oder `{width, height}` mit px, %, vw, vh. */
    private static function Size(mixed $size): array
    {
        if (is_string($size)) {
            $size = strtolower(trim($size));
            if (in_array($size, self::SIZES, true)) {
                return ['preset' => $size];
            }
            throw new InvalidArgumentException('Invalid size');
        }
        if (!is_array($size)) {
            throw new InvalidArgumentException('Invalid size');
        }
        $out = [];
        foreach (['width', 'height'] as $key) {
            if (!array_key_exists($key, $size) || $size[$key] === '' || $size[$key] === null) {
                continue;
            }
            $value = is_int($size[$key]) ? $size[$key] . 'px' : strtolower(trim((string) $size[$key]));
            if (preg_match('/^\d{1,4}(\.\d{1,2})?(px|%|vw|vh)$/', $value) !== 1) {
                throw new InvalidArgumentException('Invalid size');
            }
            $out[$key] = $value;
        }
        if ($out === []) {
            throw new InvalidArgumentException('Invalid size');
        }
        return $out;
    }
}
