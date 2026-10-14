<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;
use App\Platform\TenantRegistry\Domain\TenantInputValidator;
use PHPUnit\Framework\TestCase;

final class TenantInputValidatorTest extends TestCase
{
    private TenantInputValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new TenantInputValidator();
    }

    public function testGeneratedDatabaseNamesAreAcceptedAndRequestNamesAreRejected(): void
    {
        $name = 'tenant_'.str_repeat('ab', 16);
        self::assertSame($name, $this->validator->databaseName($name));
        self::assertSame('tu_'.str_repeat('cd', 8), $this->validator->databaseUser('tu_'.str_repeat('cd', 8)));

        foreach (['platform', 'tenant_placeholder', 'tenant_ABC', 'tenant_ab;drop', '../tenant_'.str_repeat('a', 32)] as $rejected) {
            try {
                $this->validator->databaseName($rejected);
                self::fail($rejected.' should be rejected.');
            } catch (InvalidTenantInputException) {
            }
        }
    }

    public function testTimezonesMustBeIanaNames(): void
    {
        self::assertSame('Europe/Stockholm', $this->validator->timezone('Europe/Stockholm'));
        self::assertSame('UTC', $this->validator->timezone('UTC'));
        foreach (['+01:00', 'UTC+1', 'GMT', 'Etc/GMT-1'] as $rejected) {
            try {
                $this->validator->timezone($rejected);
                self::fail($rejected.' should be rejected.');
            } catch (InvalidTenantInputException) {
            }
        }
    }
}
