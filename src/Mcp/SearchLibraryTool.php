<?php

namespace App\Mcp;

use App\Entity\RegisteredLibrary;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;

class SearchLibraryTool
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Search for libraries in the BiblioGenius network by name or tag.
     *
     * @param string $query The search query (name or tag)
     */
    #[McpTool(name: 'search_libraries')]
    public function searchLibraries(string $query): array
    {
        $repository = $this->entityManager->getRepository(RegisteredLibrary::class);
        $qb = $repository->createQueryBuilder('l');

        if (!empty($query)) {
            $qb->where('l.name LIKE :query')
                ->orWhere('l.description LIKE :query')
                ->setParameter('query', '%' . $query . '%');
        }

        $libraries = $qb->setMaxResults(10)->getQuery()->getResult();

        $results = [];
        foreach ($libraries as $lib) {
            /** @var RegisteredLibrary $lib */
            $results[] = [
                'name' => $lib->getName(),
                'url' => $lib->getUrl(),
                'description' => $lib->getDescription(),
                'tags' => $lib->getTags(),
            ];
        }

        return $results;
    }
}
