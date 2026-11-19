<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\RepairOrder;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class ExportController extends Controller
{
    public function excel(string $type): Response
    {
        [$title, $headers, $rows] = match ($type) {
            'clients' => $this->exportClients(),
            'factures' => $this->exportInvoices(),
            'stock' => $this->exportStock(),
            'ordres-reparation' => $this->exportRepairOrders(),
            'rapports' => $this->exportReports(),
            'rh' => $this->exportEmployees(),
            default => abort(404),
        };

        $html = view('exports.excel_table', [
            'title' => $title,
            'headers' => $headers,
            'rows' => $rows,
        ])->render();

        $filename = "garagepro-{$type}-".now()->format('Y-m-d').'.xls';

        return response("\xEF\xBB\xBF".$html, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    private function exportClients(): array
    {
        $rows = Customer::with('vehicles')->orderBy('name')->get()->map(fn (Customer $c) => [
            $c->name,
            $c->phone,
            $c->email ?? '-',
            $c->type,
            $c->city ?? '-',
            $c->vehicles->count(),
        ])->all();

        return ['Liste des Clients', ['Nom', 'Téléphone', 'Email', 'Type', 'Ville', 'Véhicules'], $rows];
    }

    private function exportInvoices(): array
    {
        $rows = Invoice::with('customer')->latest('issued_at')->get()->map(fn (Invoice $i) => [
            $i->invoice_number,
            $i->type,
            $i->customer?->name ?? '-',
            $i->status,
            $i->total_ttc,
            $i->paid_amount,
            $i->issued_at?->format('d/m/Y') ?? '-',
        ])->all();

        return ['Factures', ['Numéro', 'Type', 'Client', 'Statut', 'Total TTC (FCFA)', 'Payé (FCFA)', 'Date'], $rows];
    }

    private function exportStock(): array
    {
        $rows = Part::with('agency')->orderBy('name')->get()->map(fn (Part $p) => [
            $p->sku,
            $p->name,
            $p->agency?->name ?? '-',
            $p->category,
            $p->quantity_on_hand,
            $p->quantity_reserved,
            $p->min_threshold,
            $p->purchase_price,
            $p->selling_price,
        ])->all();

        return ['Stock & Pièces', ['SKU', 'Désignation', 'Agence', 'Catégorie', 'Stock', 'Réservé', 'Seuil Alerte', 'Prix Achat', 'Prix Vente'], $rows];
    }

    private function exportRepairOrders(): array
    {
        $rows = RepairOrder::with(['customer', 'vehicle'])->latest('id')->get()->map(fn (RepairOrder $ro) => [
            $ro->order_number,
            $ro->customer?->name ?? '-',
            $ro->vehicle?->license_plate ?? '-',
            $ro->status,
            $ro->created_at?->format('d/m/Y') ?? '-',
        ])->all();

        return ['Ordres de Réparation', ['N° OR', 'Client', 'Véhicule', 'Statut', 'Date'], $rows];
    }

    private function exportReports(): array
    {
        $rows = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthDate = Carbon::now()->startOfMonth()->subMonths($i);
            $start = $monthDate->copy()->startOfMonth();
            $end = $monthDate->copy()->endOfMonth();

            $revenue = (int) Payment::where('status', Payment::STATUS_SUCCESS)->whereBetween('paid_at', [$start, $end])->sum('amount');
            $purchases = (int) PurchaseOrder::whereIn('status', ['commandee', 'partiellement_recue', 'recue'])->whereBetween('created_at', [$start, $end])->sum('total_amount');
            $payroll = (int) Employee::where('is_active', true)->where('created_at', '<=', $end)->sum('base_salary');
            $manual = (int) Expense::whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])->sum('amount');
            $expenses = $purchases + $payroll + $manual;

            $rows[] = [
                ucfirst($monthDate->translatedFormat('M Y')),
                $revenue,
                $purchases,
                $payroll,
                $manual,
                $expenses,
                $revenue - $expenses,
            ];
        }

        return ['Rapport Mensuel CA & Bénéfices', ['Mois', 'Chiffre Affaires', 'Approvisionnement', 'Salaires', 'Autres Charges', 'Total Dépenses', 'Bénéfice Net'], $rows];
    }

    private function exportEmployees(): array
    {
        $rows = Employee::orderBy('full_name')->get()->map(fn (Employee $e) => [
            $e->full_name,
            $e->role_title,
            $e->phone ?? '-',
            $e->base_salary,
            $e->commission_rate ?? 0,
            $e->is_active ? 'Actif' : 'Inactif',
        ])->all();

        return ['Employés & Professionnels', ['Nom complet', 'Poste', 'Téléphone', 'Salaire Base (FCFA)', 'Commission (%)', 'Statut'], $rows];
    }
}
