<?php

namespace App\Form\Type\Usuario;

use App\DTO\Usuario\UsuarioDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UsuarioType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nombre', TextType::class)
            ->add('apellido', TextType::class)
            ->add('email', TextType::class)
            ->add('telefono', TextType::class)
            ->add('google_id', TextType::class)
            ->add('google_access_token', TextType::class)
            ->add('password', TextType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'        => UsuarioDTO::class,
            'csrf_protection'   => false,
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
