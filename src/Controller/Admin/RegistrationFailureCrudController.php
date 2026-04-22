<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\RegistrationFailure;
use Doctrine\DBAL\Connection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

class RegistrationFailureCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

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
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM registration_failures');

        $purgeAll = Action::new('purgeAll', 'Delete All Failures')
            ->setIcon('fa fa-trash')
            ->setCssClass('btn btn-danger')
            ->setHtmlAttributes([
                'onclick' => sprintf(
                    "return confirm('Purge all %d failure(s)? This is irreversible.')",
                    $count,
                ),
            ])
            ->linkToCrudAction('purgeAll')
            ->createAsGlobalAction();

        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, $purgeAll);
    }

    public function purgeAll(AdminUrlGenerator $adminUrlGenerator): Response
    {
        $deleted = (int) $this->connection->executeStatement('DELETE FROM registration_failures');

        if ($deleted === 0) {
            $this->addFlash('info', 'No registration failures to delete.');
        } else {
            $this->addFlash('success', sprintf('Deleted %d registration failure(s).', $deleted));
        }

        $url = $adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        return $this->redirect($url);
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
