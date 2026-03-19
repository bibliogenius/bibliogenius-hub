<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\HubEvent;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;

class HubEventCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return HubEvent::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Hub Event')
            ->setEntityLabelInPlural('Hub Events')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['message', 'channel', 'context'])
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('level')->setChoices([
                'Warning' => 'warning',
                'Error' => 'error',
            ]))
            ->add(ChoiceFilter::new('channel')->setChoices([
                'Relay' => 'relay',
                'Directory' => 'directory',
            ]));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('level')->setTemplatePath('admin/field/log_level.html.twig');
        yield TextField::new('channel');
        yield TextField::new('message');
        yield TextField::new('context')->setMaxLength(200);
        yield DateTimeField::new('createdAt', 'When');
    }
}
