<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Production\Customer;
use App\Entity\Production\CustomerProject;
use App\Entity\Production\CustomerProjectStatus;
use App\Entity\UserSystem\User;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CustomerProjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('projectNumber', TextType::class, [
                'label' => 'production.customer_project.number',
            ])
            ->add('name', TextType::class, [
                'label' => 'production.common.name',
            ])
            ->add('customer', EntityType::class, [
                'class' => Customer::class,
                'label' => 'production.customer.label',
                'choice_label' => static fn(Customer $customer): string => (string) $customer,
                'required' => false,
                'placeholder' => 'production.project.no_customer',
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'production.common.status',
                'choices' => [
                    'production.customer_project.status.planning' => CustomerProjectStatus::Planning,
                    'production.customer_project.status.commissioned' => CustomerProjectStatus::Commissioned,
                    'production.customer_project.status.in_production' => CustomerProjectStatus::InProduction,
                    'production.customer_project.status.completed' => CustomerProjectStatus::Completed,
                    'production.customer_project.status.delivered' => CustomerProjectStatus::Delivered,
                    'production.customer_project.status.cancelled' => CustomerProjectStatus::Cancelled,
                ],
            ])
            ->add('assignedUsers', EntityType::class, [
                'class' => User::class,
                'label' => 'production.customer_project.assigned_users',
                'choice_label' => static fn(User $user): string => $user->getFullName(true),
                'query_builder' => static fn(UserRepository $repository) => $repository->createQueryBuilder('project_user')
                    ->where('project_user.disabled = :disabled')
                    ->andWhere('project_user.id <> :anonymous')
                    ->setParameter('disabled', false)
                    ->setParameter('anonymous', User::ID_ANONYMOUS)
                    ->orderBy('project_user.name', 'ASC'),
                'multiple' => true,
                'required' => false,
                'by_reference' => false,
                'help' => 'production.customer_project.assigned_users_help',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'production.common.description',
                'required' => false,
                'empty_data' => '',
                'attr' => ['rows' => 4],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'production.common.notes',
                'required' => false,
                'attr' => ['rows' => 5],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CustomerProject::class,
            'translation_domain' => 'production',
        ]);
    }
}
