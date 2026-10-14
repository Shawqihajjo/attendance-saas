<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Persistence;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tenant_branding')]
class TenantBranding
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        private string $tenantId,
        #[ORM\Column(length: 200)]
        private string $displayName,
        #[ORM\Column(length: 7)]
        private string $primaryColor = '#1F4B99',
        #[ORM\Column(length: 7)]
        private string $secondaryColor = '#16356E',
        #[ORM\Column(length: 7)]
        private string $accentColor = '#2F80ED',
        #[ORM\Column(length: 7)]
        private string $surfaceColor = '#F4F7FB',
        #[ORM\Column(length: 7)]
        private string $textColor = '#122033',
        #[ORM\Column(length: 500, nullable: true)]
        private ?string $logoUrl = null,
        #[ORM\Column(length: 500, nullable: true)]
        private ?string $faviconUrl = null,
    ) {
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function logoUrl(): ?string
    {
        return $this->logoUrl;
    }

    public function faviconUrl(): ?string
    {
        return $this->faviconUrl;
    }

    /**
     * @return array{primary: string, secondary: string, accent: string, background: string, surface: string, text: string}
     */
    public function colors(): array
    {
        return [
            'primary' => $this->primaryColor,
            'secondary' => $this->secondaryColor,
            'accent' => $this->accentColor,
            'background' => $this->surfaceColor,
            'surface' => $this->surfaceColor,
            'text' => $this->textColor,
        ];
    }

    public function rename(string $displayName): void
    {
        $this->displayName = $displayName;
    }

    public function recolor(string $primary, string $secondary, string $accent, string $background, string $text): void
    {
        $this->primaryColor = $primary;
        $this->secondaryColor = $secondary;
        $this->accentColor = $accent;
        $this->surfaceColor = $background;
        $this->textColor = $text;
    }

    public function changeLogo(?string $logoUrl): void
    {
        $this->logoUrl = $logoUrl;
    }

    public function changeFavicon(?string $faviconUrl): void
    {
        $this->faviconUrl = $faviconUrl;
    }
}
