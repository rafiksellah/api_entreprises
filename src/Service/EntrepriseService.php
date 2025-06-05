<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class EntrepriseService
{
    private HttpClientInterface $httpClient;
    private const API_URL = 'https://recherche-entreprises.api.gouv.fr/search';

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    public function searchEntreprises(array $filters): array
    {
        $params = $this->buildApiParams($filters);

        try {
            $response = $this->httpClient->request('GET', self::API_URL, [
                'query' => $params,
                'timeout' => 30,
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'Symfony-EntrepriseApp/1.0'
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \Exception('Erreur API: ' . $response->getStatusCode() . ' - Vérifiez les paramètres de recherche');
            }

            $content = $response->toArray();
            $entreprises = [];

            if (isset($content['results']) && is_array($content['results'])) {
                foreach ($content['results'] as $entreprise) {
                    $entreprises[] = $this->formatEntreprise($entreprise);
                }
            }

            return [
                'entreprises' => $entreprises,
                'total' => $content['total_results'] ?? count($entreprises)
            ];
        } catch (\Exception $e) {
            // Log l'erreur pour debug
            error_log('API Error: ' . $e->getMessage());
            error_log('API Params: ' . json_encode($params));
            throw $e;
        }
    }

    private function buildApiParams(array $filters): array
    {
        $params = [
            'per_page' => 10,
            'page' => 1
        ];

        // Recherche par nom (terme général)
        if (!empty($filters['nom'])) {
            $params['q'] = trim($filters['nom']);
        }

        // Département - utiliser le bon paramètre de l'API
        if (!empty($filters['departement'])) {
            $dept = trim($filters['departement']);
            $params['departement'] = $dept;
        }

        // Commune/Ville - utiliser le bon paramètre
        if (!empty($filters['ville'])) {
            $params['libelle_commune'] = trim($filters['ville']);
        }

        // Région - utiliser le code région
        if (!empty($filters['region'])) {
            $params['region'] = trim($filters['region']);
        }

        // Code NAF/APE
        if (!empty($filters['code_naf'])) {
            $params['activite_principale'] = trim($filters['code_naf']);
        }

        // Nature juridique
        if (!empty($filters['nature_juridique'])) {
            $params['nature_juridique'] = trim($filters['nature_juridique']);
        }

        // Tranche d'effectifs
        if (!empty($filters['tranche_effectifs'])) {
            $params['tranche_effectif_salarie'] = trim($filters['tranche_effectifs']);
        }

        // Dates de création
        if (!empty($filters['date_creation_min'])) {
            $params['date_creation_min'] = $filters['date_creation_min'];
        }

        if (!empty($filters['date_creation_max'])) {
            $params['date_creation_max'] = $filters['date_creation_max'];
        }

        // Section d'activité
        if (!empty($filters['section_activite'])) {
            $params['section_activite_principale'] = trim($filters['section_activite']);
        }

        // Statut (actif/fermé)
        if (!empty($filters['etat_administratif'])) {
            $params['etat_administratif'] = trim($filters['etat_administratif']);
        }

        return $params;
    }

    private function formatEntreprise(array $data): array
    {
        // Données depuis le siège si disponible, sinon depuis l'entreprise
        $siege = $data['siege'] ?? [];

        return [
            'siren' => $data['siren'] ?? 'N/A',
            'nom_complet' => $data['nom_complet'] ?? 'N/A',
            'nom_raison_sociale' => $data['nom_raison_sociale'] ?? '',
            'siret' => $siege['siret'] ?? 'N/A',
            'date_creation' => $this->formatDate($data['date_creation'] ?? null),
            'date_creation_etablissement' => $this->formatDate($siege['date_creation'] ?? null),
            'adresse' => $this->formatAdresse($siege),
            'code_postal' => $siege['code_postal'] ?? '',
            'ville' => $siege['libelle_commune'] ?? '',
            'departement' => $siege['departement'] ?? '',
            'region' => $siege['region'] ?? '',
            'nature_juridique' => $this->formatNatureJuridique($data['nature_juridique'] ?? ''),
            'activite_principale' => $data['activite_principale'] ?? ($siege['activite_principale'] ?? 'N/A'),
            'section_activite_principale' => $data['section_activite_principale'] ?? '',
            'dirigeants' => $this->formatDirigeants($data['dirigeants'] ?? []),
            'tranche_effectifs' => $this->formatEffectifs($data['tranche_effectif_salarie'] ?? ''),
            'nombre_etablissements' => $data['nombre_etablissements'] ?? 0,
            'nombre_etablissements_ouverts' => $data['nombre_etablissements_ouverts'] ?? 0,
            'categorie_entreprise' => $this->formatCategorieEntreprise($data['categorie_entreprise'] ?? ''),
            'etat_administratif' => $data['etat_administratif'] ?? '',
            'caractere_employeur' => $data['caractere_employeur'] ?? ''
        ];
    }

    private function formatDate(?string $dateString): string
    {
        if (!$dateString) return 'N/A';

        try {
            $date = new \DateTime($dateString);
            return $date->format('d/m/Y');
        } catch (\Exception $e) {
            return $dateString;
        }
    }

    private function formatAdresse(array $etablissement): string
    {
        if (empty($etablissement)) return 'Adresse non disponible';

        $adresse = [];

        if (!empty($etablissement['numero_voie'])) $adresse[] = $etablissement['numero_voie'];
        if (!empty($etablissement['type_voie'])) $adresse[] = $etablissement['type_voie'];
        if (!empty($etablissement['libelle_voie'])) $adresse[] = $etablissement['libelle_voie'];
        if (!empty($etablissement['complement_adresse'])) $adresse[] = $etablissement['complement_adresse'];

        // Si pas d'adresse structurée, utiliser le champ adresse complet
        if (empty($adresse) && !empty($etablissement['adresse'])) {
            return $etablissement['adresse'];
        }

        return !empty($adresse) ? implode(' ', $adresse) : 'Adresse non disponible';
    }

    private function formatDirigeants(array $dirigeants): string
    {
        if (empty($dirigeants)) return 'N/A';

        $dirigeantsFormates = [];
        foreach ($dirigeants as $dirigeant) {
            if (empty($dirigeant)) continue;

            $nom = '';
            if (isset($dirigeant['prenom']) && isset($dirigeant['nom'])) {
                $nom = trim($dirigeant['prenom'] . ' ' . $dirigeant['nom']);
            } elseif (isset($dirigeant['denomination'])) {
                $nom = $dirigeant['denomination'];
            }

            $qualite = $dirigeant['qualite'] ?? '';

            if ($nom) {
                $dirigeantsFormates[] = $qualite ? $nom . ' (' . $qualite . ')' : $nom;
            } elseif ($qualite) {
                $dirigeantsFormates[] = $qualite;
            }
        }

        return !empty($dirigeantsFormates) ? implode(', ', $dirigeantsFormates) : 'N/A';
    }

    private function formatEffectifs(string $tranche): string
    {
        $tranches = [
            'NN' => 'Non déterminé',
            '00' => '0 salarié',
            '01' => '1-2 salariés',
            '02' => '3-5 salariés',
            '03' => '6-9 salariés',
            '11' => '10-19 salariés',
            '12' => '20-49 salariés',
            '21' => '50-99 salariés',
            '22' => '100-199 salariés',
            '31' => '200-249 salariés',
            '32' => '250-499 salariés',
            '41' => '500-999 salariés',
            '42' => '1000-1999 salariés',
            '51' => '2000-4999 salariés',
            '52' => '5000-9999 salariés',
            '53' => '10000+ salariés'
        ];

        return $tranches[$tranche] ?? ($tranche ?: 'N/A');
    }

    private function formatNatureJuridique(string $code): string
    {
        $formes = [
            '1000' => 'Entrepreneur individuel',
            '5499' => 'SA (Société Anonyme)',
            '5505' => 'SARL (Société à Responsabilité Limitée)',
            '5510' => 'SA à conseil d\'administration (s.a.i.)',
            '5720' => 'SASU (Société par Actions Simplifiée Unipersonnelle)',
            '5710' => 'SAS (Société par Actions Simplifiée)',
            '1200' => 'Commerçant',
            '5699' => 'EURL (Entreprise Unipersonnelle à Responsabilité Limitée)',
            '9220' => 'Association déclarée',
            '5308' => 'SCOP (Société Coopérative de Production)',
            '5370' => 'Société civile'
        ];

        return $formes[$code] ?? ($code ?: 'N/A');
    }

    private function formatCategorieEntreprise(string $categorie): string
    {
        $categories = [
            'GE' => 'Grande entreprise',
            'ETI' => 'Entreprise de taille intermédiaire',
            'PME' => 'Petite et moyenne entreprise',
            'TPE' => 'Très petite entreprise'
        ];

        return $categories[$categorie] ?? ($categorie ?: 'N/A');
    }

    public function getRegions(): array
    {
        return [
            '84' => 'Auvergne-Rhône-Alpes',
            '27' => 'Bourgogne-Franche-Comté',
            '53' => 'Bretagne',
            '24' => 'Centre-Val de Loire',
            '94' => 'Corse',
            '44' => 'Grand Est',
            '32' => 'Hauts-de-France',
            '11' => 'Île-de-France',
            '28' => 'Normandie',
            '75' => 'Nouvelle-Aquitaine',
            '76' => 'Occitanie',
            '52' => 'Pays de la Loire',
            '93' => 'Provence-Alpes-Côte d\'Azur',
            '01' => 'Guadeloupe',
            '02' => 'Martinique',
            '03' => 'Guyane',
            '04' => 'La Réunion',
            '06' => 'Mayotte'
        ];
    }

    public function getFormesJuridiques(): array
    {
        return [
            '1000' => 'Entrepreneur individuel',
            '5499' => 'SA (Société Anonyme)',
            '5505' => 'SARL (Société à Responsabilité Limitée)',
            '5510' => 'SA à conseil d\'administration (s.a.i.)',
            '5720' => 'SASU (Société par Actions Simplifiée Unipersonnelle)',
            '5710' => 'SAS (Société par Actions Simplifiée)',
            '1200' => 'Commerçant',
            '5699' => 'EURL (Entreprise Unipersonnelle à Responsabilité Limitée)',
            '9220' => 'Association déclarée',
            '5308' => 'SCOP (Société Coopérative de Production)',
            '5370' => 'Société civile',
            '6100' => 'Caisse d\'Épargne et de Prévoyance',
            '9230' => 'Association déclarée reconnue d\'utilité publique'
        ];
    }

    public function getTranchesEffectifs(): array
    {
        return [
            '00' => '0 salarié',
            '01' => '1-2 salariés',
            '02' => '3-5 salariés',
            '03' => '6-9 salariés',
            '11' => '10-19 salariés',
            '12' => '20-49 salariés',
            '21' => '50-99 salariés',
            '22' => '100-199 salariés',
            '31' => '200-249 salariés',
            '32' => '250-499 salariés',
            '41' => '500-999 salariés',
            '42' => '1000-1999 salariés',
            '51' => '2000-4999 salariés',
            '52' => '5000-9999 salariés',
            '53' => '10000+ salariés'
        ];
    }

    public function getSecteursActivite(): array
    {
        return [
            'A' => 'Agriculture, sylviculture et pêche',
            'B' => 'Industries extractives',
            'C' => 'Industrie manufacturière',
            'D' => 'Production et distribution d\'électr., gaz, vapeur et climatisation',
            'E' => 'Production et distribution d\'eau; assainissement, traitement des déchets',
            'F' => 'Construction',
            'G' => 'Commerce; réparation d\'automobiles et de motocycles',
            'H' => 'Transports et entreposage',
            'I' => 'Hébergement et restauration',
            'J' => 'Information et communication',
            'K' => 'Activités financières et d\'assurance',
            'L' => 'Activités immobilières',
            'M' => 'Activités spécialisées, scientifiques et techniques',
            'N' => 'Activités de services administratifs et de soutien',
            'O' => 'Administration publique',
            'P' => 'Enseignement',
            'Q' => 'Santé humaine et action sociale',
            'R' => 'Arts, spectacles et activités récréatives',
            'S' => 'Autres activités de services',
            'T' => 'Activités des ménages en tant qu\'employeurs',
            'U' => 'Activités extra-territoriales'
        ];
    }

    public function getEtatsAdministratifs(): array
    {
        return [
            'A' => 'Entreprise active',
            'C' => 'Entreprise cessée'
        ];
    }

    public function generateCsv(array $entreprises): string
    {
        // BOM UTF-8 pour Excel
        $output = "\xEF\xBB\xBF";
        $output .= "SIREN;Nom complet;Raison sociale;SIRET;Date création;Adresse;Code postal;Ville;Département;Nature juridique;Code APE;Secteur;Dirigeants;Effectifs;Nb établissements;Catégorie;État\n";

        foreach ($entreprises as $entreprise) {
            $output .= sprintf(
                '"%s";"%s";"%s";"%s";"%s";"%s";"%s";"%s";"%s";"%s";"%s";"%s";"%s";"%s";"%s";"%s";"%s"' . "\n",
                str_replace('"', '""', $entreprise['siren']),
                str_replace('"', '""', $entreprise['nom_complet']),
                str_replace('"', '""', $entreprise['nom_raison_sociale']),
                str_replace('"', '""', $entreprise['siret']),
                str_replace('"', '""', $entreprise['date_creation']),
                str_replace('"', '""', $entreprise['adresse']),
                str_replace('"', '""', $entreprise['code_postal']),
                str_replace('"', '""', $entreprise['ville']),
                str_replace('"', '""', $entreprise['departement']),
                str_replace('"', '""', $entreprise['nature_juridique']),
                str_replace('"', '""', $entreprise['activite_principale']),
                str_replace('"', '""', $entreprise['section_activite_principale']),
                str_replace('"', '""', $entreprise['dirigeants']),
                str_replace('"', '""', $entreprise['tranche_effectifs']),
                str_replace('"', '""', $entreprise['nombre_etablissements']),
                str_replace('"', '""', $entreprise['categorie_entreprise']),
                str_replace('"', '""', $this->formatEtatAdministratif($entreprise['etat_administratif']))
            );
        }

        return $output;
    }

    private function formatEtatAdministratif(string $etat): string
    {
        return $etat === 'A' ? 'Active' : ($etat === 'C' ? 'Cessée' : $etat);
    }
}
