<?php

namespace App\Security;

use App\Model\Usuario;
use App\Repository\UsuarioRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class UsuariosProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    protected $rep_usuario;

    public function __construct(UsuarioRepository $rep_usuario)
    {
        $this->rep_usuario = $rep_usuario;
    }

    /**
     * Symfony calls this method if you use features like switch_user
     * or remember_me. If you're not using these features, you do not
     * need to implement this method.
     *
     * @throws UserNotFoundException if the user is not found
     */
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        // Load a User object from your data source or throw UserNotFoundException.
        // The $identifier argument is whatever value is being returned by the
        // getUserIdentifier() method in your User class.

        $usuario = $this->get_rep_usuario()->buscar_usuario_email($identifier);

        if ($usuario === null) {
            throw new UserNotFoundException('El usuario no se encuentra registrado');
        }


        return $usuario;
    }

    /**
     * Refreshes the user after being reloaded from the session.
     *
     * When a user is logged in, at the beginning of each request, the
     * User object is loaded from the session and then this method is
     * called. Your job is to make sure the user's data is still fresh by,
     * for example, re-querying for fresh User data.
     *
     * If your firewall is "stateless: true" (for a pure API), this
     * method is not called.
     *
     * @return UserInterface
     */
    public function refreshUser(UserInterface $usuario): UserInterface
    {
        if (!$usuario instanceof Usuario) {
            throw new UnsupportedUserException(sprintf('Invalid user class "%s".', get_class($usuario)));
        }

        // $usuario = $this->get_rep_usuario()->buscar_usuario_email($usuario->email);
        return $usuario;
    }

    /**
     * Tells Symfony to use this provider for this User class.
     */
    public function supportsClass(string $class)
    {
        return UserInterface::class === $class || is_subclass_of($class, UserInterface::class);
    }

    /**
     * Upgrades the hashed password of a user, typically for using a better hash algorithm.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        // TODO: when hashed passwords are in use, this method should:
        // 1. persist the new password in the user storage
        // 2. update the $user object with $user->setPassword($newHashedPassword);
    }

    public function loadUserByUsername(string $username): UserInterface
    {

        $usuario = null;

        $usuario = $this->get_rep_usuario()->buscar_usuario_email($username);

        if ($usuario === null) {
            throw new UserNotFoundException('El usuario no se encuentra registrado');
        }

        return $usuario;
    }

    public function get_rep_usuario(): UsuarioRepository
    {
        return $this->rep_usuario;
    }
}
