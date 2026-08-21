<?php

namespace App\Form\Type\Productos;

use App\DTO\Products\DeleteProductDTO;
use App\DTO\Products\DelProductDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DeleteProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('id', IntegerType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'        => DeleteProductDTO::class,
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
