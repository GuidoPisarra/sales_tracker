<?php

namespace App\Form\Type\Productos;

use App\DTO\Products\AddStockDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AddStockType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('id', IntegerType::class)
            ->add('description', TextType::class)
            ->add('costPrice', MoneyType::class)
            ->add('salePrice', MoneyType::class)
            ->add('quantity', IntegerType::class)
            ->add('idProveedor', IntegerType::class)
            ->add('size', TextType::class)
            ->add('code', TextType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'        => AddStockDTO::class,
            'csrf_protection'   => false,
            'allow_extra_fields' => true
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
