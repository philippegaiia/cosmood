<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $displayBatchNumber = $production->permanent_batch_number ?: '-';
        $planningBatchNumber = $production->batch_number ?: '-';
        $productName = $production->product?->name ?? '-';
        $productionDate = $production->production_date?->format('d/m/Y') ?? '-';
        $expectedUnits = $production->expected_units ?? '-';
        $mainLabel = $productName.' - '.$displayBatchNumber;
    @endphp
    <title>Fiche de suivi - {{ $mainLabel }}</title>
    <style>
        body {
            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
            margin: 24px;
            color: #111827;
        }

        .section-title {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            color: #4b5563;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .part-a {
            border: 2px solid #111827;
            padding: 18px;
            margin-bottom: 18px;
        }

        .tape-space {
            height: 2in;
        }

        .part-a-main {
            font-size: 34px;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 12px;
        }

        .part-a-sub {
            font-size: 22px;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 8px;
        }

        .part-a-ref {
            font-size: 16px;
            font-weight: 600;
            color: #374151;
        }

        .part-b {
            border: 2px dashed #9ca3af;
            padding: 12px;
        }

        .part-b-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }

        .label-mini {
            border: 1px solid #111827;
            min-height: 78px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px;
            text-align: center;
            font-size: 15px;
            font-weight: 700;
            line-height: 1.2;
            word-break: break-word;
        }

        .print-controls {
            margin-top: 18px;
        }

        .print-button {
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
    <div class="section-title">A - Bac principal</div>
    <div class="part-a">
        <div class="tape-space"></div>
        <div class="part-a-main">{{ $mainLabel }}</div>
        <div class="part-a-sub">
            Date de production: {{ $productionDate }} - Quantité attendue: {{ $expectedUnits }} - Quantité réelle: __________________
        </div>
        <div class="part-a-ref">Référence planning: {{ $planningBatchNumber }}</div>
    </div>

    <div class="section-title">B - Bacs secondaires</div>
    <div class="part-b">
        <div class="part-b-grid">
            @foreach (range(1, 6) as $index)
                <div class="label-mini">{{ $mainLabel }}</div>
            @endforeach
        </div>
    </div>

    <div class="print-controls">
        <button class="print-button" onclick="window.print()">Imprimer</button>
    </div>
</body>
</html>
