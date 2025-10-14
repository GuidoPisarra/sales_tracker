<?php

namespace App\Form\Type\CuentaCorriente;

use App\DTO\CuentaCorriente\VentaCtaCteDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class VentaCtaCteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('description', TextType::class)
            ->add('idProduct', IntegerType::class)
            ->add('negocio', IntegerType::class)
            ->add('price', MoneyType::class)
            ->add('quantity', IntegerType::class)
            ->add('subtotal', MoneyType::class)
            ->add('sucursal', IntegerType::class)
            ->add('typePayment', TextType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'        => VentaCtaCteDTO::class,
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
