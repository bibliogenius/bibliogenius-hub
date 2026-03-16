<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Follow;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class FollowCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Follow::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Follow')
            ->setEntityLabelInPlural('Follows')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['followerNodeId', 'followedNodeId']);
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
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('followerNodeId', 'Follower');
        yield TextField::new('followedNodeId', 'Followed');
        yield ChoiceField::new('status', 'Status')
            ->setChoices([
                'Pending'  => Follow::STATUS_PENDING,
                'Active'   => Follow::STATUS_ACTIVE,
                'Rejected' => Follow::STATUS_REJECTED,
                'Blocked'  => Follow::STATUS_BLOCKED,
            ])
            ->renderAsBadges([
                Follow::STATUS_ACTIVE   => 'success',
                Follow::STATUS_PENDING  => 'warning',
                Follow::STATUS_REJECTED => 'danger',
                Follow::STATUS_BLOCKED  => 'dark',
            ]);
        yield DateTimeField::new('createdAt', 'Created');
        yield DateTimeField::new('resolvedAt', 'Resolved')
            ->hideOnIndex();

        // Security: never expose encryptedContact in the admin (E2EE opaque blob)
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('status');
    }
}
