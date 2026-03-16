<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CachedCatalog;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class CachedCatalogCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return CachedCatalog::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Cached Catalog')
            ->setEntityLabelInPlural('Cached Catalogs')
            ->setDefaultSort(['updatedAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            // Read-only + delete (no create/edit to avoid bypassing service logic)
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('nodeId', 'Node ID');
        yield DateTimeField::new('updatedAt', 'Updated');
        yield DateTimeField::new('expiresAt', 'Expires');

        // Show payloads only on detail page (can be large)
        if ($pageName === Crud::PAGE_DETAIL) {
            yield TextareaField::new('isbnPayload', 'ISBN Payload')
                ->setMaxLength(500);
            yield TextareaField::new('catalogPayload', 'Catalog Payload')
                ->setMaxLength(500);
        }
    }
}
