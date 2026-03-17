<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Plan achats - :name', ['name' => $wave->name]) }}</title>
    <style>
        body {
            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
            margin: 24px;
            color: #111827;
        }

        h1 {
            margin: 0;
            font-size: 24px;
        }

        .meta {
            margin-top: 8px;
            margin-bottom: 20px;
            color: #4b5563;
            font-size: 13px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th,
        td {
            border: 1px solid #d1d5db;
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f3f4f6;
            font-weight: 600;
        }

        .summary {
            margin-top: 16px;
            font-size: 14px;
            font-weight: 600;
        }

        .print-button {
            margin-top: 16px;
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
        }

        @media print {
            .print-controls {
                display: none;
            }

            body {
                margin: 0;
            }
        }
    </style>
</head>
<body>
    <h1>{{ __('Plan achats - :name', ['name' => $wave->name]) }}</h1>
    <div class="meta">
        {{ __('Date :date', ['date' => now()->format('d/m/Y H:i')]) }}<br>
        {{ __('Vague: :wave', ['wave' => $wave->slug]) }}<br>
        {{ __('Date besoin: :date', ['date' => $wave->planned_start_date?->copy()->subDays(7)->format('d/m/Y') ?? '-']) }}
    </div>

    <table>
        <thead>
            <tr>
                <th>{{ __('Ingrédient') }}</th>
                <th>{{ __('Date besoin') }}</th>
                <th>{{ __('Besoin total (kg)') }}</th>
                <th>{{ __('Besoin restant (kg)') }}</th>
                <th>{{ __('Stock disponible (kg)') }}</th>
                <th>{{ __('Commandé vague') }}</th>
                <th>{{ __('Reçu vague') }}</th>
                <th>{{ __('Cmd ouvertes non engagées') }}</th>
                <th>{{ __('Reste à commander (kg)') }}</th>
                <th>{{ __('Dernier prix (EUR/kg)') }}</th>
                <th>{{ __('Coût estimé (EUR)') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                @php($displayUnit = (string) ($line->display_unit ?? 'kg'))
                <tr>
                    <td>{{ $line->ingredient_name ?? '-' }}</td>
                    <td>{{ $line->need_date ? \Illuminate\Support\Carbon::parse($line->need_date)->format('d/m/Y') : '-' }}</td>
                    <td>{{ app(\App\Services\Production\WaveProcurementService::class)->formatPlanningQuantity((float) ($line->total_wave_requirement ?? 0), $displayUnit) }}</td>
                    <td>{{ app(\App\Services\Production\WaveProcurementService::class)->formatPlanningQuantity((float) ($line->remaining_requirement ?? 0), $displayUnit) }}</td>
                    <td>{{ app(\App\Services\Production\WaveProcurementService::class)->formatPlanningQuantity((float) ($line->available_stock ?? 0), $displayUnit) }}</td>
                    <td>{{ app(\App\Services\Production\WaveProcurementService::class)->formatPlanningQuantity((float) ($line->wave_ordered_quantity ?? 0), $displayUnit) }}</td>
                    <td>{{ app(\App\Services\Production\WaveProcurementService::class)->formatPlanningQuantity((float) ($line->wave_received_quantity ?? 0), $displayUnit) }}</td>
                    <td>{{ app(\App\Services\Production\WaveProcurementService::class)->formatPlanningQuantity((float) ($line->open_orders_not_committed ?? 0), $displayUnit) }}</td>
                    <td>{{ app(\App\Services\Production\WaveProcurementService::class)->formatPlanningQuantity((float) ($line->remaining_to_order ?? 0), $displayUnit) }}</td>
                    <td>
                        @if ((float) $line->ingredient_price > 0)
                            {{ number_format((float) $line->ingredient_price, 2, ',', ' ') }}
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        @if ($line->estimated_cost !== null)
                            {{ number_format((float) $line->estimated_cost, 2, ',', ' ') }}
                        @else
                            -
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="11">{{ __('Aucun besoin à afficher.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="summary">
        {{ __('Coût total estimé: :value EUR', ['value' => number_format($estimatedTotal, 2, ',', ' ')]) }}
    </div>

    <div class="print-controls">
        <button class="print-button" onclick="window.print()">{{ __('Imprimer') }}</button>
    </div>
</body>
</html>
