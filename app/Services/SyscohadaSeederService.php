<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\FiscalYear;
use App\Models\Tenant;

/**
 * Provisionne le plan comptable SYSCOHADA révisé (classes 1 à 9),
 * les 7 journaux et l'exercice comptable courant pour un tenant.
 */
class SyscohadaSeederService
{
    public const ACCOUNTS = [
        // Classe 1 : Ressources durables
        ['101', 'Capital social', 1, 'passif', false],
        ['121', 'Report à nouveau créditeur', 1, 'passif', false],
        ['131', 'Résultat net : Bénéfice', 1, 'passif', false],
        ['139', 'Résultat net : Perte', 1, 'passif', false],
        ['162', 'Emprunts et dettes auprès des établissements de crédit', 1, 'passif', false],
        // Classe 2 : Actif immobilisé
        ['244', 'Matériel et outillage', 2, 'actif', false],
        ['245', 'Matériel de transport', 2, 'actif', false],
        ['284', 'Amortissements du matériel', 2, 'actif', false],
        // Classe 3 : Stocks
        ['311', 'Marchandises (Pièces de rechange)', 3, 'actif', false],
        ['321', 'Matières consommables', 3, 'actif', false],
        // Classe 4 : Tiers
        ['401', 'Fournisseurs', 4, 'passif', true],
        ['411', 'Clients', 4, 'actif', true],
        ['419', 'Clients, avances et acomptes reçus', 4, 'passif', true],
        ['421', 'Personnel, rémunérations dues', 4, 'passif', true],
        ['4431', 'TVA facturée sur ventes et prestations', 4, 'passif', false],
        ['4441', 'État, TVA due', 4, 'passif', false],
        ['4452', 'TVA récupérable sur achats', 4, 'actif', false],
        // Classe 5 : Trésorerie
        ['521', 'Banques locales', 5, 'actif', false],
        ['522', 'Mobile Money (MTN MoMo / Orange Money / SaSPay)', 5, 'actif', false],
        ['571', 'Caisse', 5, 'actif', false],
        // Classe 6 : Charges
        ['601', 'Achats de pièces et marchandises', 6, 'charge', false],
        ['6031', 'Variations des stocks de marchandises', 6, 'charge', false],
        ['604', 'Achats stockés de matières et fournitures consommables', 6, 'charge', false],
        ['624', 'Entretien, réparations et maintenance', 6, 'charge', false],
        ['661', 'Rémunérations directes versées au personnel', 6, 'charge', false],
        // Classe 7 : Produits
        ['701', 'Ventes de marchandises et pièces', 7, 'produit', false],
        ['706', 'Services vendus (main-d\'œuvre garage)', 7, 'produit', false],
        ['707', 'Produits accessoires', 7, 'produit', false],
        // Classe 8 : Autres charges et produits
        ['812', 'Valeurs comptables des cessions d\'immobilisations', 8, 'charge', false],
        ['822', 'Produits des cessions d\'immobilisations', 8, 'produit', false],
        // Classe 9 : Comptabilité analytique
        ['901', 'Comptabilité analytique — Marges par agence/intervention', 9, 'analytique', false],
    ];

    public const JOURNALS = [
        ['VE', 'Journal des Ventes'],
        ['AC', 'Journal des Achats'],
        ['BQ', 'Journal de Banque'],
        ['CA', 'Journal de Caisse'],
        ['MM', 'Journal Mobile Money'],
        ['OD', 'Journal des Opérations Diverses'],
        ['AN', 'Journal des À-Nouveaux'],
    ];

    public function provisionForTenant(Tenant $tenant): void
    {
        foreach (self::ACCOUNTS as [$code, $name, $class, $type, $isAux]) {
            AccountingAccount::withoutTenantScope()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                [
                    'name' => $name,
                    'account_class' => $class,
                    'type' => $type,
                    'is_auxiliary' => $isAux,
                    'is_system' => true,
                ]
            );
        }

        foreach (self::JOURNALS as [$code, $name]) {
            AccountingJournal::withoutTenantScope()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                ['name' => $name]
            );
        }

        $year = now()->year;
        FiscalYear::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'label' => (string) $year],
            [
                'start_date' => "{$year}-01-01",
                'end_date' => "{$year}-12-31",
                'status' => FiscalYear::STATUS_OPEN,
            ]
        );
    }
}
