<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\Employee;
use App\Models\Part;
use App\Models\PurchaseOrder;
use App\Models\RepairOrder;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Gère les opérations de stock atomiques par agence (réservations, sorties OR,
 * réceptions fournisseurs et transferts inter-agences).
 */
class StockService
{
    public function reserveForRepairOrder(Part $part, int $qty, RepairOrder $order, User $actor): void
    {
        if ($qty <= 0) {
            throw new InvalidArgumentException('La quantité doit être supérieure à zéro.');
        }

        DB::transaction(function () use ($part, $qty, $order, $actor): void {
            $locked = Part::where('id', $part->id)->lockForUpdate()->firstOrFail();

            if ($locked->availableQuantity() < $qty) {
                throw new InvalidArgumentException("Stock insuffisant pour {$locked->name} (disponible : {$locked->availableQuantity()}).");
            }

            $locked->increment('quantity_reserved', $qty);

            StockMovement::create([
                'tenant_id' => $locked->tenant_id,
                'agency_id' => $locked->agency_id,
                'part_id' => $locked->id,
                'repair_order_id' => $order->id,
                'user_id' => $actor->id,
                'type' => 'reservation',
                'quantity' => $qty,
                'unit_cost' => $locked->purchase_price,
                'reference' => $order->order_number,
            ]);
        });
    }

    public function issueToRepairOrder(
        Part $part,
        int $qty,
        RepairOrder $order,
        User $actor,
        bool $fromReservation = false,
        ?Employee $requestedBy = null
    ): void {
        if ($qty <= 0) {
            throw new InvalidArgumentException('La quantité doit être supérieure à zéro.');
        }

        DB::transaction(function () use ($part, $qty, $order, $actor, $fromReservation, $requestedBy): void {
            $locked = Part::where('id', $part->id)->lockForUpdate()->firstOrFail();

            if ($locked->quantity_on_hand < $qty) {
                throw new InvalidArgumentException("Stock physique insuffisant pour {$locked->name}.");
            }

            $locked->decrement('quantity_on_hand', $qty);

            if ($fromReservation && $locked->quantity_reserved > 0) {
                $release = min($qty, $locked->quantity_reserved);
                $locked->decrement('quantity_reserved', $release);
            }

            StockMovement::create([
                'tenant_id' => $locked->tenant_id,
                'agency_id' => $locked->agency_id,
                'part_id' => $locked->id,
                'repair_order_id' => $order->id,
                'user_id' => $actor->id,
                'requested_by_employee_id' => $requestedBy?->id,
                'type' => 'sortie_or',
                'quantity' => -$qty,
                'unit_cost' => $locked->purchase_price,
                'reference' => $order->order_number,
                'notes' => $requestedBy ? "Demandé par : {$requestedBy->full_name}" : null,
            ]);
        });
    }

    public function receivePurchaseOrder(PurchaseOrder $po, array $receivedByItemId, User $actor): void
    {
        DB::transaction(function () use ($po, $receivedByItemId, $actor): void {
            $po->load('items');

            foreach ($po->items as $item) {
                $qty = (int) ($receivedByItemId[$item->id] ?? 0);
                if ($qty <= 0) {
                    continue;
                }

                $item->increment('quantity_received', $qty);

                if ($item->part_id) {
                    $part = Part::where('id', $item->part_id)->lockForUpdate()->firstOrFail();
                    $part->increment('quantity_on_hand', $qty);

                    StockMovement::create([
                        'tenant_id' => $po->tenant_id,
                        'agency_id' => $po->agency_id,
                        'part_id' => $part->id,
                        'user_id' => $actor->id,
                        'type' => 'entree',
                        'quantity' => $qty,
                        'unit_cost' => $item->unit_cost,
                        'reference' => $po->po_number,
                    ]);
                }
            }

            $po->refresh();
            $allReceived = $po->items->every(fn ($i) => $i->quantity_received >= $i->quantity_ordered);

            $po->update([
                'status' => $allReceived ? PurchaseOrder::STATUS_RECEIVED : PurchaseOrder::STATUS_PARTIAL,
                'received_at' => $allReceived ? now() : $po->received_at,
            ]);
        });
    }

    public function transferBetweenAgencies(Part $sourcePart, Agency $targetAgency, int $qty, User $actor): Part
    {
        if ($qty <= 0) {
            throw new InvalidArgumentException('La quantité à transférer doit être positive.');
        }

        if ((int) $sourcePart->tenant_id !== (int) $targetAgency->tenant_id) {
            throw new InvalidArgumentException('Transfert impossible vers une agence d\'un autre tenant.');
        }

        if ((int) $sourcePart->agency_id === (int) $targetAgency->id) {
            throw new InvalidArgumentException('L\'agence cible doit être différente de l\'agence source.');
        }

        return DB::transaction(function () use ($sourcePart, $targetAgency, $qty, $actor): Part {
            $lockedSource = Part::where('id', $sourcePart->id)->lockForUpdate()->firstOrFail();

            if ($lockedSource->availableQuantity() < $qty) {
                throw new InvalidArgumentException("Stock disponible insuffisant pour le transfert.");
            }

            $lockedSource->decrement('quantity_on_hand', $qty);

            StockMovement::create([
                'tenant_id' => $lockedSource->tenant_id,
                'agency_id' => $lockedSource->agency_id,
                'part_id' => $lockedSource->id,
                'user_id' => $actor->id,
                'type' => 'transfert_sortie',
                'quantity' => -$qty,
                'unit_cost' => $lockedSource->purchase_price,
                'reference' => "TRF-VERS-{$targetAgency->id}",
            ]);

            $targetPart = Part::where('tenant_id', $lockedSource->tenant_id)
                ->where('agency_id', $targetAgency->id)
                ->where('sku', $lockedSource->sku)
                ->lockForUpdate()
                ->first();

            if (! $targetPart) {
                $targetPart = Part::create([
                    'tenant_id' => $lockedSource->tenant_id,
                    'agency_id' => $targetAgency->id,
                    'sku' => $lockedSource->sku,
                    'name' => $lockedSource->name,
                    'category' => $lockedSource->category,
                    'condition' => $lockedSource->condition,
                    'quantity_on_hand' => 0,
                    'quantity_reserved' => 0,
                    'min_threshold' => $lockedSource->min_threshold,
                    'purchase_price' => $lockedSource->purchase_price,
                    'selling_price' => $lockedSource->selling_price,
                ]);
            }

            $targetPart->increment('quantity_on_hand', $qty);

            StockMovement::create([
                'tenant_id' => $targetPart->tenant_id,
                'agency_id' => $targetPart->agency_id,
                'part_id' => $targetPart->id,
                'user_id' => $actor->id,
                'type' => 'transfert_entree',
                'quantity' => $qty,
                'unit_cost' => $targetPart->purchase_price,
                'reference' => "TRF-DEPUIS-{$lockedSource->agency_id}",
            ]);

            return $targetPart->fresh();
        });
    }
}
