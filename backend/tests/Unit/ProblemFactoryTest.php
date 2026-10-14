<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Shared\Http\ProblemFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class ProblemFactoryTest extends TestCase
{
    public function testValidationFailuresBecomeProblemDetails(): void
    {
        $factory = new ProblemFactory();
        $request = Request::create('/api/v1/public/diagnostics/validate', 'POST');
        $request->attributes->set('request_id', 'req-12345678');
        $violations = new ConstraintViolationList([
            new ConstraintViolation('This value should not be blank.', null, [], '', 'name', ''),
        ]);

        $problem = $factory->fromThrowable(new ValidationFailedException('', $violations), $request, false);

        self::assertSame(422, $problem['status']);
        self::assertSame('validation_failed', $problem['code']);
        self::assertSame('req-12345678', $problem['requestId']);
        self::assertSame('name', $problem['errors'][0]['field']);
    }

    public function testProductionServerErrorsHideTheExceptionMessage(): void
    {
        $factory = new ProblemFactory();
        $request = Request::create('/api/v1/health');

        $problem = $factory->fromThrowable(new \RuntimeException('mysql://user:secret@db/platform'), $request, false);

        self::assertSame(500, $problem['status']);
        self::assertSame('The server could not complete the request.', $problem['detail']);
        self::assertStringNotContainsString('secret', (string) $problem['detail']);
    }
}
