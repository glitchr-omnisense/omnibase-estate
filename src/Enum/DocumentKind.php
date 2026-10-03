<?php

namespace Base\Estate\Enum;

/** What a document is: the lease's papers, the tenant's insurance, a contractor's quote, invoice or photo. */
enum DocumentKind: string
{
    case LEASE = 'lease';
    case INVENTORY = 'inventory';
    case DIAGNOSTIC = 'diagnostic';
    case RULES = 'rules';
    case INSURANCE = 'insurance';
    case QUOTE = 'quote';
    case INVOICE = 'invoice';
    case PHOTO = 'photo';
    case OTHER = 'other';

    public function icon(): string
    {
        return match ($this) {
            self::LEASE => 'fa-solid fa-file-signature',
            self::INVENTORY => 'fa-solid fa-clipboard-check',
            self::DIAGNOSTIC => 'fa-solid fa-leaf',
            self::RULES => 'fa-solid fa-scale-balanced',
            self::INSURANCE => 'fa-solid fa-shield-halved',
            self::QUOTE => 'fa-solid fa-file-pen',
            self::INVOICE => 'fa-solid fa-file-invoice',
            self::PHOTO => 'fa-solid fa-camera',
            self::OTHER => 'fa-solid fa-file-lines',
        };
    }

    /** The kinds a contractor attaches to a work order. */
    public static function work(): array
    {
        return [self::QUOTE, self::INVOICE, self::PHOTO];
    }
}
