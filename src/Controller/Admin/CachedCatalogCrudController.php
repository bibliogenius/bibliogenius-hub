<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CachedCatalog;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Orm\EntityPaginator;

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
            ->setDefaultSort(['updatedAt' => 'DESC'])
            ->setPaginatorUseOutputWalkers(true);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('nodeId', 'Node ID');
        yield DateTimeField::new('updatedAt', 'Updated');
        yield DateTimeField::new('expiresAt', 'Expires');
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->leftJoin('entity.libraryProfile', 'lp')
            ->addSelect('lp');
    }
}
