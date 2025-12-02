<?php

namespace App\Command;

use App\Entity\RegisteredLibrary;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:load-fixtures',
    description: 'Loads dummy data for RegisteredLibrary',
)]
class LoadFixturesCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Clear existing data
        $repository = $this->entityManager->getRepository(RegisteredLibrary::class);
        $entities = $repository->findAll();
        foreach ($entities as $entity) {
            $this->entityManager->remove($entity);
        }
        $this->entityManager->flush();

        // Create Lib A
        $libA = new RegisteredLibrary();
        $libA->setName('Lib A');
        $libA->setUrl('http://lib-a.local');
        $libA->setDescription('The first library in the network.');
        $libA->setTags(['public', 'main']);
        $this->entityManager->persist($libA);

        // Create Lib B
        $libB = new RegisteredLibrary();
        $libB->setName('Lib B');
        $libB->setUrl('http://lib-b.local');
        $libB->setDescription('The second library, specialized in science.');
        $libB->setTags(['science', 'research']);
        $this->entityManager->persist($libB);

        $this->entityManager->flush();

        $io->success('Fixtures loaded: Lib A and Lib B created.');

        return Command::SUCCESS;
    }
}
