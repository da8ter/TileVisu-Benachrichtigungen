<?php

declare(strict_types=1);

/**
 * Freiwilliges Andocken an die Instanz „Visu-Erweiterung“ (Browser-Erweiterung „Visu-Erweiterung für Symcon“).
 *
 * Die Konsole meldet eine fehlende übergeordnete Instanz nur, wenn GetCompatibleParents „moduleIDs“ liefert; mit
 * „modules“ bietet „Gateway ändern“ die Visu-Erweiterung trotzdem an. Beim Anlegen fragt die Konsole sofort nach und
 * öffnet ohne „type“ keinen Dialog (Abbrechen dort löscht die neue Instanz): deshalb in den ersten Sekunden nach der
 * Anlage '{}'. Zusätzlich Andocken und Lösen per Knopf im Formular (DockFormItems → RequestAction 'VisuDock').
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

    /** Hinweis und Knopf fürs Andocken an die (einzige) Instanz „Visu-Erweiterung“. */
    protected function DockFormItems(string $Docked, string $Undocked): array
    {
        if ($this->Docked()) {
            return [
                ['type' => 'Label', 'caption' => $this->Translate($Docked)],
                ['type' => 'Button', 'caption' => $this->Translate('Undock from the Visu Extension'), 'onClick' => 'IPS_RequestAction($id, \'VisuDock\', false);'],
            ];
        }
        if ($this->VisuExtensionID() === 0) {
            return [
                ['type' => 'Label', 'caption' => $this->Translate($Undocked)],
                ['type' => 'Label', 'caption' => $this->Translate('The module "Visu Extension" is optional and not installed.')],
            ];
        }
        return [
            ['type' => 'Label', 'caption' => $this->Translate($Undocked)],
            ['type' => 'Button', 'caption' => $this->Translate('Dock onto the Visu Extension'), 'onClick' => 'IPS_RequestAction($id, \'VisuDock\', true);'],
        ];
    }

    /** Andocken oder lösen, auf Knopfdruck im Formular. */
    protected function VisuDock(bool $Dock): void
    {
        if ($Dock) {
            $extension = $this->VisuExtensionID();
            if ($extension === 0) {
                throw new \RuntimeException($this->Translate('The module "Visu Extension" is optional and not installed.'));
            }
            IPS_ConnectInstance($this->InstanceID, $extension);
        } elseif ($this->Docked()) {
            IPS_DisconnectInstance($this->InstanceID);
        }
        $this->ReloadForm();
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
