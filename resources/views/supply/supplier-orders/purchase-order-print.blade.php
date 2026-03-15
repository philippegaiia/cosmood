<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Impression PO {{ $supplierOrder->order_ref }}</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: "Segoe UI", Tahoma, sans-serif;
            color: #1f2937;
            margin: 0;
            background: #f3f4f6;
        }

        .page {
            max-width: 1100px;
            margin: 20px auto;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 20px;
        }

        .toolbar {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-bottom: 12px;
        }

        .btn {
            border: 1px solid #d1d5db;
            background: #ffffff;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            color: #111827;
            font-size: 14px;
        }

        .btn.primary {
            background: #111827;
            color: #ffffff;
            border-color: #111827;
        }

        .header {
            margin-bottom: 16px;
        }

        .title {
            font-size: 22px;
            font-weight: 700;
        }

        .muted {
            color: #6b7280;
        }

        .grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }

        .grid td {
            vertical-align: top;
            width: 50%;
            padding: 10px;
            border: 1px solid #e5e7eb;
        }

        .block-title {
            font-weight: 700;
            margin-bottom: 6px;
        }

        .items {
            width: 100%;
            border-collapse: collapse;
        }

        .items th,
        .items td {
            border: 1px solid #d1d5db;
            padding: 8px;
            text-align: left;
            font-size: 13px;
        }

        .items th {
            background: #f9fafb;
        }

        .text-right {
            text-align: right;
        }

        .totals {
            margin-top: 12px;
            margin-left: auto;
            width: 340px;
            border-collapse: collapse;
        }

        .totals td {
            border: 1px solid #d1d5db;
            padding: 8px;
            font-size: 13px;
        }

        .totals .grand {
            font-weight: 700;
            background: #f9fafb;
        }

        @media print {
            body {
                background: #ffffff;
            }

            .page {
                max-width: none;
                margin: 0;
                border: 0;
                border-radius: 0;
                padding: 0;
            }

            .toolbar {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="toolbar">
            <a class="btn" href="{{ route('supplier-orders.po-pdf', $supplierOrder) }}" target="_blank" rel="noopener">PDF</a>
            <button class="btn primary" type="button" onclick="window.print()">Imprimer</button>
        </div>

        <div class="header">
            <div class="title">Bon de commande fournisseur</div>
            <div class="muted">Ref commande: {{ $supplierOrder->order_ref ?? ('PO-'.$supplierOrder->id) }}</div>
            @if ($supplierOrder->wave)
                <div class="muted" style="font-size: 12px;">Ref interne vague: {{ $supplierOrder->wave->name }}</div>
            @endif
        </div>

        <table class="grid">
            <tr>
                <td>
                    <div class="block-title">Emetteur</div>
                    <div><strong>{{ $issuer['name'] }}</strong></div>
                    @foreach ($issuer['address_lines'] as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                    @if (filled($issuer['vat_number']))
                        <div>TVA: {{ $issuer['vat_number'] }}</div>
                    @endif
                </td>
                <td>
                    <div class="block-title">Fournisseur</div>
                    <div><strong>{{ $supplierOrder->supplier?->name ?? '-' }}</strong></div>
                    <div>{{ $supplierOrder->supplier?->address1 ?? '' }}</div>
                    @if (filled($supplierOrder->supplier?->address2))
                        <div>{{ $supplierOrder->supplier?->address2 }}</div>
                    @endif
                    <div>{{ trim(($supplierOrder->supplier?->zipcode ?? '').' '.($supplierOrder->supplier?->city ?? '')) }}</div>
                    <div>{{ $supplierOrder->supplier?->country ?? '' }}</div>
                    @if (filled($supplierOrder->supplier?->email))
                        <div>{{ $supplierOrder->supplier?->email }}</div>
                    @endif
                </td>
            </tr>
        </table>

        <table class="grid">
            <tr>
                <td>
                    <div><strong>Date commande:</strong> {{ filled($supplierOrder->order_date) ? \Illuminate\Support\Carbon::parse($supplierOrder->order_date)->format('d/m/Y') : '-' }}</div>
                    <div><strong>Date livraison souhaitee:</strong> {{ filled($supplierOrder->delivery_date) ? \Illuminate\Support\Carbon::parse($supplierOrder->delivery_date)->format('d/m/Y') : '-' }}</div>
                </td>
                <td>
                    @if (filled($supplierOrder->invoice_number))
                        <div><strong>Facture:</strong> {{ $supplierOrder->invoice_number }}</div>
                    @endif
                    @if (filled($supplierOrder->bl_number))
                        <div><strong>BL:</strong> {{ $supplierOrder->bl_number }}</div>
                    @endif
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th style="width: 24%;">Article</th>
                    <th style="width: 12%;">Code fournisseur</th>
                    <th style="width: 10%;" class="text-right">Quantite</th>
                    <th style="width: 12%;" class="text-right">UOM</th>
                    <th style="width: 12%;" class="text-right">Total</th>
                    <th style="width: 15%;" class="text-right">Prix unit.</th>
                    <th style="width: 15%;" class="text-right">Montant EUR</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($lines as $line)
                    @php
                        $quantity = (float) ($line->quantity ?? 0);
                        $unitWeight = (float) ($line->unit_weight ?? 0);
                        $unitPrice = (float) ($line->unit_price ?? 0);
                        $displayUnit = $line->supplierListing?->getNormalizedUnitOfMeasure() ?? 'kg';
                        $unitMultiplier = $unitWeight > 0 ? $unitWeight : 1;
                        $lineTotal = round($quantity * $unitMultiplier, 3);
                        $lineAmount = round($lineTotal * $unitPrice, 2);
                    @endphp
                    <tr>
                        <td>{{ $line->supplierListing?->name ?? '-' }}</td>
                        <td>{{ $line->supplierListing?->supplier_code ?? '-' }}</td>
                        <td class="text-right">{{ $displayUnit === 'u' ? number_format($quantity, 0, ',', ' ') : number_format($quantity, 3, ',', ' ') }}</td>
                        <td class="text-right">{{ $displayUnit === 'u' ? number_format($unitMultiplier, 0, ',', ' ') : number_format($unitMultiplier, 3, ',', ' ') }} {{ $displayUnit }}</td>
                        <td class="text-right">{{ $displayUnit === 'u' ? number_format($lineTotal, 0, ',', ' ') : number_format($lineTotal, 3, ',', ' ') }} {{ $displayUnit }}</td>
                        <td class="text-right">{{ number_format($unitPrice, 2, ',', ' ') }}</td>
                        <td class="text-right">{{ number_format($lineAmount, 2, ',', ' ') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="muted">Aucune ligne de commande.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <table class="totals">
            <tr>
                <td>Total lignes</td>
                <td class="text-right">{{ number_format((float) $totalAmount, 2, ',', ' ') }} EUR</td>
            </tr>
            <tr>
                <td>Transport</td>
                <td class="text-right">{{ number_format((float) $freightCost, 2, ',', ' ') }} EUR</td>
            </tr>
            <tr class="grand">
                <td>Total commande</td>
                <td class="text-right">{{ number_format((float) $grandTotal, 2, ',', ' ') }} EUR</td>
            </tr>
        </table>
    </div>
</body>
</html>
