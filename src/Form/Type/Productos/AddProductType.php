<?php

namespace App\Form\Type\Productos;

use App\DTO\Products\AddProductDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AddProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('description', TextType::class)
            ->add('costPrice', MoneyType::class)
            ->add('salePrice', MoneyType::class)
            ->add('quantity', IntegerType::class)
            ->add('idProveedor', IntegerType::class)
            ->add('size', TextType::class)
            ->add('code', TextType::class)
            ->add('idSucursal', TextType::class)
            ->add('idNegocio', TextType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'        => AddProductDTO::class,
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
