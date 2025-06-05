<?php

namespace App\Controller;

use App\Service\EntrepriseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class EntrepriseController extends AbstractController
{
    private EntrepriseService $entrepriseService;

    public function __construct(EntrepriseService $entrepriseService)
    {
        $this->entrepriseService = $entrepriseService;
    }

    #[Route('/entreprise/search', name: 'app_entreprise_index')]
    public function index(): Response
    {
        // Données pour les formulaires de filtres
        $regions = $this->entrepriseService->getRegions();
        $formesJuridiques = $this->entrepriseService->getFormesJuridiques();
        $tranchesEffectifs = $this->entrepriseService->getTranchesEffectifs();
        $secteursActivite = $this->entrepriseService->getSecteursActivite();

        return $this->render('entreprise/index.html.twig', [
            'regions' => $regions,
            'formes_juridiques' => $formesJuridiques,
            'tranches_effectifs' => $tranchesEffectifs,
            'secteurs_activite' => $secteursActivite,
            'user' => $this->getUser()
        ]);
    }

    #[Route('/search', name: 'app_entreprise_search', methods: ['POST'])]
    public function search(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            if (!$data) {
                throw new \Exception('Données de recherche invalides');
            }

            $results = $this->entrepriseService->searchEntreprises($data);

            // Log de l'activité utilisateur (optionnel)
            $user = $this->getUser();
            if ($user) {
                // Ici vous pourriez logger l'activité de recherche
                // Par exemple, sauvegarder les termes de recherche populaires
            }

            return new JsonResponse([
                'success' => true,
                'results' => $results['entreprises'],
                'total' => $results['total'],
                'message' => $results['total'] > 0 ?
                    $results['total'] . ' entreprise(s) trouvée(s)' :
                    'Aucun résultat trouvé pour votre recherche'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Erreur lors de la recherche: ' . $e->getMessage(),
                'results' => [],
                'total' => 0
            ], 400);
        }
    }

    #[Route('/export', name: 'app_entreprise_export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        try {
            $data = json_decode($request->getContent(), true);
            $results = $this->entrepriseService->searchEntreprises($data);

            // Limiter les exports pour les utilisateurs non-premium (optionnel)
            $user = $this->getUser();
            if ($user && count($results['entreprises']) > 100) {
                // Vous pourriez limiter le nombre d'exports pour les utilisateurs gratuits
                // $results['entreprises'] = array_slice($results['entreprises'], 0, 100);
            }

            // Générer un CSV
            $csv = $this->entrepriseService->generateCsv($results['entreprises']);

            $response = new Response($csv);
            $response->headers->set('Content-Type', 'text/csv');
            $response->headers->set('Content-Disposition', 'attachment; filename="entreprises_' . date('Y-m-d_H-i-s') . '.csv"');

            return $response;
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Erreur lors de l\'export: ' . $e->getMessage()
            ], 400);
        }
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    public function dashboard(): Response
    {
        $user = $this->getUser();

        return $this->render('entreprise/dashboard.html.twig', [
            'user' => $user
        ]);
    }
}
