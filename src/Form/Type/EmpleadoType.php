<?php

namespace App\Form\Type\Empleado;

use App\DTO\EmpleadoDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EmpleadoType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $builder
      ->add('nombre', TextType::class)
      ->add('email', TextType::class)
      ->add('rol', TextType::class)
      ->add('password', TextType::class)
      ->add('id_negocio', IntegerType::class, [
        'property_path' => 'idNegocio' // Le dice a Symfony que la clave 'id_negocio' mapea al setter 'setIdNegocio'
      ]);
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([
      'data_class'      => EmpleadoDTO::class,
      'csrf_protection' => false,
    ]);
  }

  public function getName(): string
  {
    return '';
  }

  public function getBlockPrefix(): string
  {
    return '';
  }
}
