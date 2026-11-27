<?php

declare(strict_types=1);

/**
 * Freiwilliges Andocken an die Instanz „Visu-Erweiterung“ (Browser-Erweiterung „Visu-Erweiterung für Symcon“).
 *
 * Die Konsole meldet eine fehlende übergeordnete Instanz nur, wenn GetCompatibleParents „moduleIDs“ liefert, und
 * fragt beim Anlegen ohne „type“ nicht nach einer. Ohne Verbindung kommt deshalb '{}' zurück; angedockt wird über
 * den Knopf im Formular (DockFormItems → RequestAction 'VisuDock'). Braucht self::VISU_EXTENSION und Docked().
 */
trait VisuDockTrait
{
    public function GetCompatibleParents(): string
    {
        return $this->Docked() ? (string) json_encode(['type' => 'connect', 'modules' => [['moduleID' => self::VISU_EXTENSION]]]) : '{}';
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
