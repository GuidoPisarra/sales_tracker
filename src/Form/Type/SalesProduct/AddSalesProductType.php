<?php

namespace App\Form\Type\SalesProduct;

use App\DTO\SalesProduct\AddSalesProductDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\FloatType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AddSalesProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('idProduct', IntegerType::class)
            ->add('idProveedor', IntegerType::class)
            ->add('saleDay', TextType::class)
            ->add('quantity', IntegerType::class)
            ->add('price', MoneyType::class)
            ->add('typePayment', TextType::class)
            ->add('id_negocio', TextType::class)
            ->add('sucursal', TextType::class)
            ->add('usuario', TextType::class)
            ->add('id_persona', TextType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'        => AddSalesProductDTO::class,
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
