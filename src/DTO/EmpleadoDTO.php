<?php

namespace App\DTO;

class EmpleadoDTO
{

  private ?int $id = null;
  private ?string $nombre = null;
  private ?string $email = null;
  private ?string $rol = null;
  private ?string $password = null;
  private ?int $idNegocio = null;

  // --- GETTERS ---

  public function getNombre(): ?string
  {
    return $this->nombre;
  }

  public function getEmail(): ?string
  {
    return $this->email;
  }

  public function getRol(): ?string
  {
    return $this->rol;
  }

  public function getPassword(): ?string
  {
    return $this->password;
  }

  public function getIdNegocio(): ?int
  {
    return $this->idNegocio;
  }

  // --- SETTERS (Obligatorios para Symfony Form) ---

  public function setIdEmpleado(int $id): self
  {
    $this->id = $id;
    return $this;
  }

  public function setNombre(?string $nombre): self
  {
    $this->nombre = $nombre;
    return $this;
  }

  public function setEmail(?string $email): self
  {
    $this->email = $email;
    return $this;
  }

  public function setRol(?string $rol): self
  {
    $this->rol = $rol;
    return $this;
  }

  public function setPassword(?string $pass): self
  {
    $this->password = $pass;
    return $this;
  }

  public function setIdNegocio(?int $idNegocio): self
  {
    $this->idNegocio = $idNegocio;
    return $this;
  }

  public function isValidForCreate(): bool
  {
    return !empty($this->nombre) && !empty($this->email) && !empty($this->rol) && !empty($this->idNegocio);
  }
}
