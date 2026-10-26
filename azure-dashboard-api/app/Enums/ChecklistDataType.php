<?php

namespace App\Enums;

use Illuminate\Validation\Rule;

enum ChecklistDataType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';
    case Url = 'url';
    case SharepointLink = 'sharepoint_link';
    case Select = 'select';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Texto corto',
            self::Textarea => 'Texto largo',
            self::Number => 'Número',
            self::Date => 'Fecha',
            self::Boolean => 'Sí / No',
            self::Url => 'URL',
            self::SharepointLink => 'Link de SharePoint',
            self::Select => 'Lista de opciones',
        };
    }

    /**
     * Reglas de validación del valor registrado en un work item.
     */
    public function valueRules(?array $options = []): array
    {
        return match ($this) {
            self::Text => ['string', 'max:500'],
            self::Textarea => ['string', 'max:10000'],
            self::Number => ['numeric'],
            self::Date => ['date'],
            self::Boolean => ['boolean'],
            self::Url => ['url:http,https', 'max:2000'],
            self::SharepointLink => ['url:https', 'max:2000', 'regex:/^https:\/\/[^\/]+\.sharepoint\.com(\/|$)/i'],
            self::Select => ['string', Rule::in($options ?? [])],
        };
    }
}
