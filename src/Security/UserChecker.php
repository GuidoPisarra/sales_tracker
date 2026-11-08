<?php

namespace App\Security;

use App\Model\Usuario;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Se ejecuta en el login y en cada request con JWT:
 * - un empleado dado de baja no puede entrar ni seguir usando un token viejo;
 * - una cuenta nueva no entra hasta confirmar el email.
 */
class UserChecker implements UserCheckerInterface
{
    public const EMAIL_NO_VERIFICADO = 'Confirmá tu email para ingresar. Revisá tu casilla (y la carpeta de spam).';

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof Usuario) {
            return;
        }
        if ($user->estaEliminado()) {
            throw new CustomUserMessageAccountStatusException('El usuario fue dado de baja.');
        }
        if (!$user->emailVerificado()) {
            throw new CustomUserMessageAccountStatusException(self::EMAIL_NO_VERIFICADO);
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
