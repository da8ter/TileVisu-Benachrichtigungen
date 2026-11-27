<?php

declare(strict_types=1);

/**
 * Freiwilliges Andocken an die Instanz „Visu-Erweiterung“ (Browser-Erweiterung „Visu-Erweiterung für Symcon“).
 *
 * Die Konsole meldet eine fehlende übergeordnete Instanz nur, wenn GetCompatibleParents „moduleIDs“ liefert; mit
 * „modules“ bietet „Gateway ändern“ die Visu-Erweiterung trotzdem an. Beim Anlegen fragt die Konsole sofort nach und
 * öffnet ohne „type“ keinen Dialog (Abbrechen dort löscht die neue Instanz): deshalb in den ersten Sekunden nach der
 * Anlage '{}'. Das Formular zeigt den Stand und einen Knopf zur Anleitung (DockFormItems).
 * Braucht self::VISU_EXTENSION, Docked() und RegisterDockAttributes() in Create().
 */
trait VisuDockTrait
{
    /** So lange nach der Anlage gilt eine Abfrage als Teil des Anlege-Dialogs der Konsole (Sekunden). */
    private static int $DockCreateWindow = 5;

    public function GetCompatibleParents(): string
    {
        if (!$this->Docked() && time() - $this->ReadAttributeInteger('CreatedAt') < self::$DockCreateWindow) {
            return '{}';
        }
        return (string) json_encode(['type' => 'connect', 'modules' => [['moduleID' => self::VISU_EXTENSION]]]);
    }

    /** In Create() aufrufen: merkt sich den Zeitpunkt der Anlage (einmalig, überlebt Neustart und Neuladen). */
    protected function RegisterDockAttributes(): void
    {
        $this->RegisterAttributeInteger('CreatedAt', 0);
        if ($this->ReadAttributeInteger('CreatedAt') === 0) {
            $this->WriteAttributeInteger('CreatedAt', time());
        }
    }

    /** Hinweis zum Andocken (über „Gateway ändern“) und ein Knopf zur Anleitung der Visu-Erweiterung. */
    protected function DockFormItems(string $Docked, string $Undocked): array
    {
        $items = [['type' => 'Label', 'caption' => $this->Translate($this->Docked() ? $Docked : $Undocked)]];
        if (!$this->Docked() && $this->VisuExtensionID() === 0) {
            $items[] = ['type' => 'Label', 'caption' => $this->Translate('The module "Visu Extension" is optional and not installed.')];
        }
        $items[] = ['type' => 'Button', 'caption' => $this->Translate('Visu Extension guide'), 'onClick' => "echo 'https://github.com/da8ter/VisuExtensionForSymcon#readme';"];
        return $items;
    }

    /** Die aktive Instanz „Visu-Erweiterung“ (je Server eine: die mit der kleinsten Kennung), sonst 0. */
    private function VisuExtensionID(): int
    {
        if (!IPS_ModuleExists(self::VISU_EXTENSION)) {
            return 0;
        }
        $instances = IPS_GetInstanceListByModuleID(self::VISU_EXTENSION);
        return $instances === [] ? 0 : min($instances);
    }
}
