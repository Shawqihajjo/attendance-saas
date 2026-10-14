<?php

declare(strict_types=1);

namespace App;

use App\Diagnostics\ProductionConfiguration;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function __construct(string $environment, bool $debug)
    {
        date_default_timezone_set('UTC');
        ProductionConfiguration::assertSafe($environment, $debug);
        parent::__construct($environment, $debug);
    }
}
