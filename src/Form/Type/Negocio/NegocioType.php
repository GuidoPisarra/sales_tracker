<?php

namespace App\Form\Type\Negocio;

use App\DTO\Negocio\NegocioDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class NegocioType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('id_negocio', IntegerType::class)
            ->add('sucursal', IntegerType::class)
            ->add('nombre', TextType::class)
            ->add('domicilio', TextType::class)
            ->add('telefono', TextType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'        => NegocioDTO::class,
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
