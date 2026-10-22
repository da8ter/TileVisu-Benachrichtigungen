<?php

declare(strict_types=1);

/**
 * Abgleich mit den Benachrichtigungen der Kachel-Visualisierung (Glocke), ohne Symcon.
 *
 * Quelle ist die Kern-Instanz „Notification Control" (NC_GetNotifications, nicht im
 * öffentlichen Funktionsindex): je Eintrag NotificationID, VisualizationID, Title, Body,
 * Icon, Sound, IsRead, SentTime, TargetID.
 *
 * - Neue Einträge der gewählten Visu werden als Nachricht übernommen (ID `nc-<NotificationID>`).
 * - Gelesen in der Glocke → gelesen hier; gelöscht in der Glocke → gelöscht hier (nur Übernommene).
 * - Eigene Nachrichten, die an dieselbe Visu weitergeleitet wurden, werden verknüpft statt
 *   doppelt übernommen (Merker `forwardKey`, bis die Glocke den Eintrag hat).
 */
final class VisuSync
{
    /** Alte Symcon-Icon-Namen → Font Awesome; unbekannte → Icon der Stufe. */
    private const ICONS = [
        'door' => 'door-open', 'window' => 'window-frame-open', 'motion' => 'person-walking', 'lock' => 'lock',
        'light' => 'lightbulb', 'temperature' => 'temperature-half', 'battery' => 'battery-quarter', 'flame' => 'fire',
        'drops' => 'droplet', 'mail' => 'envelope', 'bell' => 'bell', 'clock' => 'clock', 'car' => 'car',
        'information' => 'circle-info', 'info' => 'circle-info', 'warning' => 'triangle-exclamation',
        'alert' => 'siren-on', 'alarm' => 'siren-on', 'ok' => 'circle-check', 'success' => 'circle-check',
    ];
    private const LEVELS = [
        'alert' => 'alarm', 'alarm' => 'alarm', 'error' => 'alarm', 'flame' => 'alarm', 'drops' => 'alarm',
        'warning' => 'warning', 'warn' => 'warning', 'battery' => 'warning',
        'ok' => 'success', 'success' => 'success',
    ];

    /** Stufe und Icon aus dem Icon-Namen einer Visu-Benachrichtigung. */
    public static function MapIcon(string $icon): array
    {
        $key = strtolower(trim($icon));
        $level = self::LEVELS[$key] ?? 'info';
        if (isset(self::ICONS[$key])) {
            return ['level' => $level, 'icon' => self::ICONS[$key]];
        }
        // Neuere Visu-Benachrichtigungen können schon Font-Awesome-Namen tragen.
        $fa = preg_replace('/^(fa-light|fa-regular|fa-solid|fal|fa)\s+/', '', $key);
        $fa = str_starts_with((string) $fa, 'fa-') ? substr((string) $fa, 3) : (string) $fa;
        return ['level' => $level, 'icon' => preg_match('/^[a-z0-9]+(-[a-z0-9]+)+$/', $fa) === 1 ? $fa : ''];
    }

    /** Schlüssel für die Verknüpfung weitergeleiteter Nachrichten. */
    public static function Key(string $title, string $text): string
    {
        return md5(trim($title) . "\n" . trim($text));
    }

    /**
     * Was zu tun ist. `$ours` = eigene Nachrichten, `$nc` = NC_GetNotifications, `$visu` = gespiegelte Visu,
     * `$maxImported` = höchste schon übernommene NotificationID dieser Visu (gelöschte kommen nicht wieder).
     *
     * @return array{imports: list<array>, links: array<string,int>, reads: list<string>, removes: list<string>, max: int}
     */
    public static function Plan(array $ours, array $nc, int $visu, int $maxImported): array
    {
        $linked = [];
        $pending = [];
        foreach ($ours as $m) {
            $ncId = (int) ($m['nc']['id'] ?? 0);
            if ($ncId > 0 && (int) ($m['nc']['visu'] ?? 0) === $visu) {
                $linked[$ncId] = $m;
            } elseif (($m['forwardKey'] ?? '') !== '' && (int) ($m['forwardVisu'] ?? 0) === $visu) {
                $pending[$m['forwardKey']] = (string) $m['id'];
            }
        }
        $plan = ['imports' => [], 'links' => [], 'reads' => [], 'removes' => [], 'max' => $maxImported];
        $present = [];
        $eintraege = array_values(array_filter($nc, static fn($n): bool => is_array($n) && (int) ($n['VisualizationID'] ?? 0) === $visu));
        usort($eintraege, static fn(array $a, array $b): int => (int) $a['NotificationID'] <=> (int) $b['NotificationID']);
        foreach ($eintraege as $n) {
            $id = (int) ($n['NotificationID'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $present[$id] = true;
            $read = !empty($n['IsRead']);
            if (isset($linked[$id])) {
                if ($read && empty($linked[$id]['read'])) {
                    $plan['reads'][] = (string) $linked[$id]['id'];
                }
            } elseif (isset($pending[$k = self::Key((string) ($n['Title'] ?? ''), (string) ($n['Body'] ?? ''))])) {
                $plan['links'][$pending[$k]] = $id;
                unset($pending[$k]);
            } elseif ($id > $maxImported) {
                $plan['imports'][] = $n;
            }
            $plan['max'] = max($plan['max'], $id);
        }
        foreach ($linked as $ncId => $m) {
            if (!isset($present[$ncId]) && ($m['nc']['origin'] ?? '') === 'visu') {
                $plan['removes'][] = (string) $m['id'];
            }
        }
        return $plan;
    }
}
