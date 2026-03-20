<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\RelayMailbox;
use App\Repository\RelayMailboxRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

class RelayMailboxCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly RelayMailboxRepository $mailboxRepository,
    ) {}

    public static function getEntityFqcn(): string
    {
        return RelayMailbox::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Relay Mailbox')
            ->setEntityLabelInPlural('Relay Mailboxes')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['uuid'])
            ->setPaginatorPageSize(30)
            ->setEntityPermission('ROLE_ADMIN');
    }

    public function configureActions(Actions $actions): Actions
    {
        $purgeOrphans = Action::new('purgeOrphans', 'Purge Orphan Mailboxes')
            ->setIcon('fa fa-broom')
            ->setCssClass('btn btn-warning')
            ->linkToCrudAction('purgeOrphans')
            ->createAsGlobalAction();

        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, $purgeOrphans);
    }

    public function purgeOrphans(AdminUrlGenerator $adminUrlGenerator): Response
    {
        $orphanMailboxes = $this->mailboxRepository->countOrphans();
        $orphanMessages = $this->mailboxRepository->countOrphanMessages();

        if ($orphanMailboxes === 0) {
            $this->addFlash('info', 'No orphan mailboxes found. Everything is clean.');
        } else {
            $result = $this->mailboxRepository->purgeOrphans();
            $this->addFlash('success', sprintf(
                'Purged %d orphan mailbox(es) and %d stuck message(s).',
                $result['mailboxes'],
                $result['messages'],
            ));
        }

        $url = $adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        return $this->redirect($url);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('uuid', 'Mailbox UUID')
            ->setFormTypeOption('disabled', true);
        yield DateTimeField::new('createdAt', 'Created');
        yield DateTimeField::new('lastAccessed', 'Last Accessed');
    }
}
