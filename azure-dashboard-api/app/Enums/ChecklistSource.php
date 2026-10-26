<?php

namespace App\Enums;

/**
 * Origen del valor de un item del alistamiento: lo registra el usuario
 * o se calcula a partir de Azure DevOps al consultar el work item.
 */
enum ChecklistSource: string
{
    case Manual = 'manual';
    case AzureBranch = 'azure_branch';
    case AzurePr = 'azure_pr';
    case AzurePipeline = 'azure_pipeline';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::AzureBranch => 'Automático: rama enlazada',
            self::AzurePr => 'Automático: Pull Request enlazado',
            self::AzurePipeline => 'Automático: estado del pipeline',
        };
    }
}
