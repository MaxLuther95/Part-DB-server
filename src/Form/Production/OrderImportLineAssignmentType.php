<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Parts\Part;
use App\Entity\Production\OrderPositionUnit;
use App\Entity\Production\SystemTemplate;
use App\Entity\ProjectSystem\Project;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;

final class OrderImportLineAssignmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('systemTemplate', EntityType::class, ['class' => SystemTemplate::class, 'choice_label' => 'name', 'required' => false, 'placeholder' => 'Keine Systemvorlage', 'label' => 'Systemvorlage'])
            ->add('templateProject', EntityType::class, ['class' => Project::class, 'choice_label' => static fn(Project $project): string => $project->getFullPath(), 'required' => false, 'placeholder' => 'Kein Bauprojekt', 'label' => 'Part-DB-Bauprojekt'])
            ->add('part', EntityType::class, ['class' => Part::class, 'choice_label' => 'name', 'required' => false, 'placeholder' => 'Kein Lagerteil', 'label' => 'Part-DB-Lagerteil'])
            ->add('unit', EnumType::class, ['class' => OrderPositionUnit::class, 'choice_label' => static fn(OrderPositionUnit $unit): string => $unit->getLabel(), 'label' => 'Einheit', 'help' => 'Die Anzahl bleibt unverändert. Lagerteile und Bauprojekte benötigen Stück; Systemvorlagen ihre festgelegte Auftragseinheit.'])
            ->add('expected', HiddenType::class);
    }
}
