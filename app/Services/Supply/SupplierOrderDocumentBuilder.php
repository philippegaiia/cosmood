<?php

namespace App\Services\Supply;

use App\Models\Settings;
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\SupplierOrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SupplierOrderDocumentBuilder
{
    /**
     * @return array{supplierOrder: SupplierOrder, lines: Collection<int, SupplierOrderItem>, totalAmount: float, freightCost: float, grandTotal: float, issuer: array{name: string, address_lines: array<int, string>, vat_number: ?string}}
     */
    public function buildViewData(SupplierOrder $supplierOrder): array
    {
        $supplierOrder->loadMissing([
            'supplier',
            'wave',
            'supplier_order_items.supplierListing',
        ]);

        /** @var Collection<int, SupplierOrderItem> $lines */
        $lines = $supplierOrder->supplier_order_items
            ->sortBy('id')
            ->values();

        $totalAmount = round((float) $lines->sum(function (SupplierOrderItem $item): float {
            $unitPrice = (float) ($item->unit_price ?? 0);

            return $item->getOrderedQuantityKg() * $unitPrice;
        }), 2);

        $freightCost = (float) ($supplierOrder->freight_cost ?? 0);

        return [
            'supplierOrder' => $supplierOrder,
            'lines' => $lines,
            'totalAmount' => $totalAmount,
            'freightCost' => $freightCost,
            'grandTotal' => round($totalAmount + $freightCost, 2),
            'issuer' => $this->getIssuerData(),
        ];
    }

    /**
     * @return array{emailSubject: string, emailText: string, supplierCodes: array<int, string>}
     */
    public function buildEmailCopy(SupplierOrder $supplierOrder): array
    {
        $documentData = $this->buildViewData($supplierOrder);
        $supplierCodes = $this->getSupplierListingCodes($documentData['lines']);
        $hasCompletePricing = true;
        $textLines = [
            __('Bonjour :supplier,', ['supplier' => (string) ($supplierOrder->supplier?->name ?? '')]),
            '',
            __('Veuillez trouver ci-dessous notre bon de commande :reference.', [
                'reference' => $this->getOrderReference($supplierOrder),
            ]),
        ];

        if ($supplierCodes->isNotEmpty()) {
            $textLines[] = __('Codes fournisseur: :codes', ['codes' => $supplierCodes->implode(', ')]);
        }

        $textLines[] = __('Date commande: :date', ['date' => $this->formatDate($supplierOrder->order_date)]);
        $textLines[] = __('Date livraison souhaitee: :date', ['date' => $this->formatDate($supplierOrder->delivery_date)]);
        $textLines[] = '';
        $textLines[] = __('Lignes:');

        foreach ($documentData['lines'] as $line) {
            $quantity = (float) ($line->quantity ?? 0);
            $unitWeight = (float) ($line->unit_weight ?? 0);
            $hasLinePrice = filled($line->unit_price);
            $lineSupplierCode = trim((string) ($line->supplierListing?->supplier_code ?? ''));
            $displayUnit = $line->supplierListing?->getNormalizedUnitOfMeasure() ?? 'kg';
            $unitMultiplier = $unitWeight > 0 ? $unitWeight : 1;
            $totalQuantity = round($quantity * $unitMultiplier, 3);
            $formattedQuantity = $displayUnit === 'u'
                ? number_format($quantity, 0, ',', ' ')
                : number_format($quantity, 3, ',', ' ');
            $formattedTotalQuantity = $displayUnit === 'u'
                ? number_format($totalQuantity, 0, ',', ' ')
                : number_format($totalQuantity, 3, ',', ' ');
            $formattedUnitMultiplier = $displayUnit === 'u'
                ? number_format($unitMultiplier, 0, ',', ' ')
                : number_format($unitMultiplier, 3, ',', ' ');

            $lineText = '- '.($line->supplierListing?->name ?? __('Article'))
                .($lineSupplierCode !== '' ? ' ['.__('Ref fournisseur: :code', ['code' => $lineSupplierCode]).']' : '')
                .' | '.$formattedQuantity.' x '.$formattedUnitMultiplier
                .' = '.$formattedTotalQuantity.' '.$displayUnit;

            if ($hasLinePrice) {
                $unitPrice = (float) $line->unit_price;
                $lineAmount = round($totalQuantity * $unitPrice, 2);
                $lineText .= ' | '.number_format($unitPrice, 2, ',', ' ')
                    .' EUR | '.number_format($lineAmount, 2, ',', ' ')
                    .' EUR';
            } else {
                $hasCompletePricing = false;
                $lineText .= ' | '.__('prix a confirmer');
            }

            $textLines[] = $lineText;
        }

        $textLines[] = '';

        if ($hasCompletePricing) {
            $textLines[] = __('Total lignes: :amount EUR', ['amount' => number_format((float) $documentData['totalAmount'], 2, ',', ' ')]);
            $textLines[] = __('Transport: :amount EUR', ['amount' => number_format((float) $documentData['freightCost'], 2, ',', ' ')]);
            $textLines[] = __('Total commande: :amount EUR', ['amount' => number_format((float) $documentData['grandTotal'], 2, ',', ' ')]);
        } else {
            $textLines[] = __('Tarifs a confirmer par retour d\'email.');
        }

        $textLines[] = '';
        $textLines[] = __('Cordialement,');
        $textLines[] = $documentData['issuer']['name'];

        $emailSubject = 'PO '.$this->getOrderReference($supplierOrder)
            .' - '.($supplierOrder->supplier?->name ?? config('app.name'));

        if ($supplierCodes->count() === 1) {
            $emailSubject .= ' ['.$supplierCodes->first().']';
        }

        return [
            'emailSubject' => $emailSubject,
            'emailText' => implode(PHP_EOL, $textLines),
            'supplierCodes' => $supplierCodes->all(),
        ];
    }

    public function buildPdfFilename(SupplierOrder $supplierOrder): string
    {
        return 'po-'.$this->getOrderReference($supplierOrder).'.pdf';
    }

    private function getOrderReference(SupplierOrder $supplierOrder): string
    {
        return (string) ($supplierOrder->order_ref ?: 'PO-'.$supplierOrder->id);
    }

    private function formatDate(mixed $date): string
    {
        if (! filled($date)) {
            return '-';
        }

        return Carbon::parse($date)->format('d/m/Y');
    }

    /**
     * @return array{name: string, address_lines: array<int, string>, vat_number: ?string}
     */
    private function getIssuerData(): array
    {
        $addressLines = collect(preg_split('/\r\n|\r|\n/', (string) (Settings::companyAddress() ?? '')))
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->values()
            ->all();

        return [
            'name' => Settings::companyName(),
            'address_lines' => $addressLines,
            'vat_number' => Settings::companyVatNumber(),
        ];
    }

    /**
     * @param  Collection<int, SupplierOrderItem>  $lines
     * @return Collection<int, string>
     */
    private function getSupplierListingCodes(Collection $lines): Collection
    {
        return $lines
            ->map(fn (SupplierOrderItem $line): string => trim((string) ($line->supplierListing?->supplier_code ?? '')))
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->values();
    }
}
