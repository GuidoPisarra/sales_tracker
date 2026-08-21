<?php

namespace App\Form\Type\CuentaCorriente;

use App\DTO\CuentaCorriente\NuevaVentaCtaCteDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class NuevaVentaCtaCteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('cliente', ClienteType::class)
            ->add('venta', CollectionType::class, [
                'entry_type' => VentaCtaCteType::class,
                'allow_add' => true, // Permite la adición dinámica de elementos de venta
                'allow_delete' => true, // Permite la eliminación de elementos de venta
                'entry_options' => ['label' => false],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'        => NuevaVentaCtaCteDTO::class,
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
