<?php

namespace App\Form\Type\Productos;

use App\DTO\Products\TrasladoProductDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\FloatType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TrasladoProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('id', IntegerType::class)
            ->add('description', TextType::class)
            ->add('cost_price', MoneyType::class)
            ->add('sale_price', MoneyType::class)
            ->add('quantity', IntegerType::class)
            ->add('id_proveedor', IntegerType::class)
            ->add('id_negocio', IntegerType::class)
            ->add('size', TextType::class)
            ->add('code', TextType::class)
            ->add('code_canvas', TextType::class)
            ->add('activo', IntegerType::class)
            ->add('sucursal', IntegerType::class)
            ->add('sucursalNueva', IntegerType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'        => TrasladoProductDTO::class,
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
