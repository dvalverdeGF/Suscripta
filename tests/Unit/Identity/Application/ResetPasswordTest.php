<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\RequestPasswordReset;
use App\Identity\Application\ResetPassword;
use App\Identity\Domain\Entity\PasswordResetRequest;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\PasswordResetRequestRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ResetPasswordTest extends TestCase
{
    public function testItRejectsAnUnknownToken(): void
    {
        $requests = $this->createMock(PasswordResetRequestRepositoryInterface::class);
        $requests->expects(self::once())->method('findByTokenHash')->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no es válido');

        ($this->useCase($requests))('token-inventado', 'NuevaClave!2026');
    }

    public function testItRejectsAnExpiredToken(): void
    {
        $request = $this->request(expiresAt: new DateTimeImmutable('-1 minute'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ha caducado');

        ($this->useCase($this->repositoryReturning($request)))('token', 'NuevaClave!2026');
    }

    public function testItRejectsAnAlreadyUsedToken(): void
    {
        $request = $this->request(expiresAt: new DateTimeImmutable('+1 hour'));
        $request->markUsed(new DateTimeImmutable('-1 minute'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ya se ha usado');

        ($this->useCase($this->repositoryReturning($request)))('token', 'NuevaClave!2026');
    }

    public function testItHashesTheNewPasswordAndConsumesTheToken(): void
    {
        $request = $this->request(expiresAt: new DateTimeImmutable('+1 hour'));

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())->method('hashPassword')->willReturn('hashed');

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('save');

        $requests = $this->repositoryReturning($request);
        $requests->expects(self::once())->method('save')->with($request);

        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::once())->method('log');

        ($this->useCase($requests, $users, $hasher, $audit))('token', 'NuevaClave!2026');

        self::assertSame('hashed', $request->getUser()->getPassword());
        self::assertNotNull($request->getUsedAt());
    }

    public function testTheStoredHashIsTheSha256OfTheToken(): void
    {
        self::assertSame(hash('sha256', 'abc'), RequestPasswordReset::hash('abc'));
        self::assertNotSame('abc', RequestPasswordReset::hash('abc'));
    }

    private function request(DateTimeImmutable $expiresAt): PasswordResetRequest
    {
        $user = new User('ada@example.com', 'hash', 'Ada Lovelace');

        return new PasswordResetRequest($user, RequestPasswordReset::hash('token'), $expiresAt);
    }

    /**
     * @return PasswordResetRequestRepositoryInterface&MockObject
     */
    private function repositoryReturning(PasswordResetRequest $request): PasswordResetRequestRepositoryInterface
    {
        $repository = $this->createMock(PasswordResetRequestRepositoryInterface::class);
        $repository->method('findByTokenHash')->willReturn($request);

        return $repository;
    }

    private function useCase(
        PasswordResetRequestRepositoryInterface $requests,
        ?UserRepositoryInterface $users = null,
        ?UserPasswordHasherInterface $hasher = null,
        ?AuditLoggerInterface $audit = null,
    ): ResetPassword {
        return new ResetPassword(
            $requests,
            $users ?? $this->createMock(UserRepositoryInterface::class),
            $hasher ?? $this->createMock(UserPasswordHasherInterface::class),
            $audit ?? $this->createMock(AuditLoggerInterface::class),
        );
    }
}
