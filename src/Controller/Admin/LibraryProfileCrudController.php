<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\LibraryProfile;
use App\Repository\LibraryProfileRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

class LibraryProfileCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly LibraryProfileRepository $profileRepository,
    ) {}

    public static function getEntityFqcn(): string
    {
        return LibraryProfile::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Library Profile')
            ->setEntityLabelInPlural('Library Profiles')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['nodeId', 'displayName', 'locationCountry'])
            ->setEntityPermission('ROLE_ADMIN');
    }

    public function configureActions(Actions $actions): Actions
    {
        $purgeStale = Action::new('purgeStale', 'Purge Stale Profiles')
            ->setIcon('fa fa-trash')
            ->setCssClass('btn btn-danger')
            ->linkToCrudAction('purgeStale')
            ->createAsGlobalAction();

        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $purgeStale);
    }

    public function purgeStale(AdminUrlGenerator $adminUrlGenerator): Response
    {
        $deleted = $this->profileRepository->purgeStaleProfiles();

        $this->addFlash('success', sprintf(
            'Purged %d stale profile(s) (0 books, never seen).',
            $deleted
        ));

        $url = $adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        return $this->redirect($url);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('nodeId', 'Node ID')
            ->setFormTypeOption('disabled', true);
        yield TextField::new('displayName', 'Display Name');
        yield IntegerField::new('bookCount', 'Books');
        yield TextField::new('locationCountry', 'Country');
        yield BooleanField::new('isListed', 'Listed')
            ->renderAsSwitch(false);
        yield BooleanField::new('requiresApproval', 'Approval')
            ->renderAsSwitch(false);
        yield BooleanField::new('allowBorrowing', 'Borrowing')
            ->renderAsSwitch(false);
        yield IntegerField::new('viewCount', 'Views')
            ->hideOnIndex();
        yield TextField::new('website', 'Website')
            ->hideOnIndex();
        yield DateTimeField::new('lastSeenAt', 'Last Seen');
        yield DateTimeField::new('createdAt', 'Created')
            ->hideOnIndex();

        // Security: never expose writeToken or x25519PublicKey in the admin
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('isListed')
            ->add('requiresApproval')
            ->add('allowBorrowing')
            ->add('locationCountry');
    }
}
