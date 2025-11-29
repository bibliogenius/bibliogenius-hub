<?php

namespace App\Controller;

use App\Entity\Language;
use App\Entity\Translation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api')]
class TranslationController extends AbstractController
{
    #[Route('/languages', name: 'api_languages', methods: ['GET'])]
    public function getLanguages(EntityManagerInterface $em): JsonResponse
    {
        $languages = $em->getRepository(Language::class)->findAll();
        $data = [];

        foreach ($languages as $lang) {
            $data[] = [
                'code' => $lang->getCode(),
                'name' => $lang->getName(),
            ];
        }

        return $this->json($data);
    }

    #[Route('/translations/{locale}', name: 'api_translations', methods: ['GET'])]
    public function getTranslations(string $locale, EntityManagerInterface $em): JsonResponse
    {
        $translations = $em->getRepository(Translation::class)->findBy(['locale' => $locale]);
        $data = [];

        foreach ($translations as $t) {
            $data[$t->getKeyName()] = $t->getContent();
        }

        // Fallback to empty object if no translations found, or handle 404?
        // Returning empty object allows app to fallback to default.
        return $this->json($data);
    }
}
