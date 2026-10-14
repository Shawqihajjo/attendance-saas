<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Domain;

final readonly class TenantConfiguration
{
    /**
     * @param list<string> $supportedLanguages
     * @param array{primary: string, secondary: string, accent: string, background: string, text: string} $colors
     */
    public function __construct(
        public string $companyName,
        public ?string $logoUrl,
        public ?string $faviconUrl,
        public array $colors,
        public string $defaultLanguage,
        public array $supportedLanguages,
        public string $timezone,
        public string $dateFormat,
        public string $timeFormat,
        public int $weekStartsOn,
        public bool $photoRequired,
        public ?int $photoRetentionDays = null,
    ) {
    }

    public static function platformDefault(): self
    {
        return new self(
            'Attendance',
            null,
            null,
            [
                'primary' => '#1F4B99',
                'secondary' => '#16356E',
                'accent' => '#2F80ED',
                'background' => '#F4F7FB',
                'text' => '#122033',
            ],
            LanguageCatalog::FALLBACK,
            LanguageCatalog::codes(),
            'UTC',
            'iso',
            '24h',
            1,
            true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $colors = [
            'primary' => $this->colors['primary'],
            'secondary' => $this->colors['secondary'],
            'accent' => $this->colors['accent'],
            'background' => $this->colors['background'],
            'surface' => $this->colors['background'],
            'text' => $this->colors['text'],
        ];

        return [
            'companyName' => $this->companyName,
            'displayName' => $this->companyName,
            'logoUrl' => $this->logoUrl,
            'faviconUrl' => $this->faviconUrl,
            'colors' => $colors,
            'defaultLanguage' => $this->defaultLanguage,
            'supportedLanguages' => $this->supportedLanguages,
            'defaultLocale' => $this->defaultLanguage,
            'enabledLocales' => $this->supportedLanguages,
            'timezone' => $this->timezone,
            'dateFormat' => $this->dateFormat,
            'timeFormat' => $this->timeFormat,
            'weekStartsOn' => $this->weekStartsOn,
            'attendance' => [
                'photoRequired' => $this->photoRequired,
                'photoRetentionDays' => $this->photoRetentionDays,
            ],
            'photoRequired' => $this->photoRequired,
        ];
    }
}
