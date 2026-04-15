<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\RegistrationFailure;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class RegistrationFailureCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return RegistrationFailure::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Registration Failure')
            ->setEntityLabelInPlural('Registration Failures')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['nodeId', 'displayName', 'appVersion']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('nodeId', 'Node ID');
        yield TextField::new('displayName', 'Display Name');
        yield IntegerField::new('bookCount', 'Books');
        yield TextField::new('clientIp', 'Client IP');
        yield TextField::new('appVersion', 'App Version')
            ->formatValue(fn($value) => $value ?: '—');
        yield DateTimeField::new('createdAt', 'When');
    }
}
