<?php

namespace App\Enums;

enum Locale: string
{
    case FR = 'fr';
    case EN = 'en';

    /**
     * The language shown to anyone who has not chosen one: APP_LOCALE,
     * which config/app.php sets to French — the product's market is
     * Moroccan COD teams, and French is the working language of their
     * back offices. Read from config rather than fixed here so a deploy
     * (or the test suite, which runs in English) can change it in one
     * place.
     */
    public static function default(): self
    {
        return self::tryFrom((string) config('app.locale')) ?? self::FR;
    }

    /**
     * Resolve a stored or user-supplied value, falling back to the default
     * for anything unknown — a stale cookie from a retired language, say.
     */
    public static function fromMixed(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::default()) : self::default();
    }

    public function label(): string
    {
        return match ($this) {
            self::FR => 'Français',
            self::EN => 'English',
        };
    }
}
