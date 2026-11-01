<?php

namespace Base\Estate\Enum;

/**
 * When a works photo was taken: before the works, during them (the progress of works under way) or
 * after them. A before and an after of the same view make a pair.
 */
enum PhotoPhase: string
{
    case BEFORE = 'before';
    case DURING = 'during';
    case AFTER = 'after';

    /** As the back office says it. */
    public function label(): string
    {
        return match ($this) {
            self::BEFORE => 'Avant',
            self::DURING => 'Pendant',
            self::AFTER => 'Après',
        };
    }
}
