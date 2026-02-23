<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Plan achats - {{ $wave->name }}</title>
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
    <h1>Plan achats - {{ $wave->name }}</h1>
    <div class="meta">
        Date: {{ now()->format('d/m/Y H:i') }}<br>
        Vague: {{ $wave->slug }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Ingredient</th>
                <th>A commander (kg)</th>
                <th>Deja commande (kg)</th>
                <th>Stock indicatif (kg)</th>
                <th>Manque indicatif (kg)</th>
                <th>Dernier prix (EUR/kg)</th>
                <th>Cout estime (EUR)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td>{{ $line->ingredient_name ?? '-' }}</td>
                    <td>{{ number_format((float) $line->to_order_quantity, 3, ',', ' ') }}</td>
                    <td>{{ number_format((float) $line->ordered_quantity, 3, ',', ' ') }}</td>
                    <td>{{ number_format((float) $line->stock_advisory, 3, ',', ' ') }}</td>
                    <td>{{ number_format((float) $line->advisory_shortage, 3, ',', ' ') }}</td>
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
                    <td colspan="7">Aucun besoin a afficher.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="summary">
        Cout total estime: {{ number_format($estimatedTotal, 2, ',', ' ') }} EUR
    </div>

    <div class="print-controls">
        <button class="print-button" onclick="window.print()">Imprimer</button>
    </div>
</body>
</html>
