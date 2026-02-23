<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fiche production - {{ $production->batch_number }}</title>
    <style>
        body {
            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
            margin: 24px;
            color: #111827;
        }

        h1, h2 {
            margin: 0;
        }

        h1 {
            font-size: 24px;
        }

        h2 {
            font-size: 16px;
            margin-top: 24px;
            margin-bottom: 8px;
        }

        .meta {
            margin-top: 8px;
            margin-bottom: 16px;
            color: #374151;
            font-size: 13px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px 16px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
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

        .signatures {
            margin-top: 24px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .signature-box {
            border: 1px solid #d1d5db;
            min-height: 70px;
            padding: 8px;
            font-size: 12px;
        }

        .print-controls {
            margin-top: 20px;
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
    <h1>Fiche production - {{ $production->batch_number }}</h1>

    <div class="meta">
        <div><strong>Produit:</strong> {{ $production->product?->name ?? '-' }}</div>
        <div><strong>Vague:</strong> {{ $production->wave?->name ?? 'Autonome' }}</div>
        <div><strong>Formule:</strong> {{ $production->formula?->name ?? '-' }}</div>
        <div><strong>Statut:</strong> {{ $production->status?->getLabel() ?? '-' }}</div>
        <div><strong>Date production:</strong> {{ $production->production_date?->format('d/m/Y') ?? '-' }}</div>
        <div><strong>Date disponibilite:</strong> {{ $production->ready_date?->format('d/m/Y') ?? '-' }}</div>
        <div><strong>Quantite planifiee:</strong> {{ number_format((float) $production->planned_quantity, 3, ',', ' ') }} kg</div>
        <div><strong>Unites attendues:</strong> {{ $production->expected_units ?? '-' }}</div>
    </div>

    <h2>Items de production</h2>
    <table>
        <thead>
            <tr>
                <th>Ingredient</th>
                <th>Phase</th>
                <th>Quantite calculee (kg)</th>
                <th>Lot supply</th>
                <th>Prix ref (EUR/kg)</th>
                <th>Cout estime (EUR)</th>
            </tr>
        </thead>
        <tbody>
            @if (($masterbatchLine ?? null) !== null)
                <tr>
                    <td><strong>Masterbatch {{ $masterbatchLine['masterbatch_batch_number'] ?? '-' }}</strong></td>
                    <td>{{ (string) ($masterbatchLine['phase'] ?? '-') }} - {{ \App\Enums\Phases::tryFrom((string) ($masterbatchLine['phase'] ?? ''))?->getLabel() ?? '-' }}</td>
                    <td>{{ number_format((float) ($masterbatchLine['quantity'] ?? 0), 3, ',', ' ') }}</td>
                    <td>{{ $masterbatchLine['masterbatch_batch_number'] ?? '-' }}</td>
                    <td>-</td>
                    <td>-</td>
                </tr>
            @endif
            @forelse (($displayItems ?? collect()) as $item)
                <tr>
                    <td>{{ $item->ingredient?->name ?? '-' }}</td>
                    <td>{{ (string) ($item->phase ?? '-') }} - {{ $item->getPhaseLabel() }}</td>
                    <td>{{ number_format($item->getCalculatedQuantityKg(), 3, ',', ' ') }}</td>
                    <td>{{ $item->supply_batch_number ?? '-' }}</td>
                    <td>
                        @if ($item->getReferenceUnitPrice() !== null)
                            {{ number_format((float) $item->getReferenceUnitPrice(), 2, ',', ' ') }}
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        @if ($item->getEstimatedCost() !== null)
                            {{ number_format((float) $item->getEstimatedCost(), 2, ',', ' ') }}
                        @else
                            -
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">Aucun item de production.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if (($masterbatchTraceability ?? collect())->isNotEmpty())
        <h2>Traçabilité masterbatch {{ $masterbatchLine['masterbatch_batch_number'] ?? '' }}</h2>
        <table>
            <thead>
                <tr>
                    <th>Ingrédient (MB)</th>
                    <th>Phase</th>
                    <th>Quantité (kg)</th>
                    <th>Lot supply</th>
                    <th>Référence</th>
                </tr>
            </thead>
            <tbody>
                @foreach (($masterbatchTraceability ?? collect()) as $line)
                    <tr>
                        <td>{{ $line['ingredient_name'] ?? '-' }}</td>
                        <td>{{ (string) ($line['phase'] ?? '-') }} - {{ \App\Enums\Phases::tryFrom((string) ($line['phase'] ?? ''))?->getLabel() ?? '-' }}</td>
                        <td>{{ number_format((float) ($line['quantity'] ?? 0), 3, ',', ' ') }}</td>
                        <td>{{ $line['supply_batch_number'] ?? '-' }}</td>
                        <td>{{ $line['supply_ref'] ?? '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Taches de production</h2>
    <table>
        <thead>
            <tr>
                <th>Tache</th>
                <th>Date planifiee</th>
                <th>Duree (min)</th>
                <th>Statut systeme</th>
                <th>Suivi papier</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($production->productionTasks->sortBy('scheduled_date') as $task)
                <tr>
                    <td>{{ $task->name ?? ($task->templateItem?->name ?? '-') }}</td>
                    <td>{{ $task->scheduled_date?->format('d/m/Y') ?? '-' }}</td>
                    <td>{{ $task->duration_minutes ?? '-' }}</td>
                    <td>
                        @if ($task->is_finished)
                            Terminee
                        @elseif ($task->isCancelled())
                            Annulee
                        @elseif ($task->scheduled_date && $task->scheduled_date->isFuture())
                            Non demarree
                        @else
                            Demarree
                        @endif
                    </td>
                    <td>[ ] Debut [ ] Fin [ ] Conforme</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">Aucune tache.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2>Controles QC</h2>
    <table>
        <thead>
            <tr>
                <th>Controle</th>
                <th>Type</th>
                <th>Tolerance / Cible</th>
                <th>Fait</th>
                <th>Non fait</th>
                <th>Mesure systeme</th>
                <th>Mesure papier</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($production->productionQcChecks->sortBy('sort_order') as $check)
                <tr>
                    <td>{{ $check->label }}</td>
                    <td>{{ $check->input_type?->getLabel() ?? '-' }}</td>
                    <td>
                        @if ($check->min_value !== null || $check->max_value !== null)
                            {{ $check->min_value ?? '-' }} - {{ $check->max_value ?? '-' }} {{ $check->unit ?? '' }}
                        @elseif ($check->target_value)
                            {{ $check->target_value }}
                        @else
                            -
                        @endif
                    </td>
                    <td>{{ $check->isDone() ? '[x]' : '[ ]' }}</td>
                    <td>{{ $check->isDone() ? '[ ]' : '[x]' }}</td>
                    <td>{{ $check->getDisplayValue() ?? '-' }}</td>
                    <td>....................................</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">Aucun controle QC.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="signatures">
        <div class="signature-box">
            <strong>Operateur production</strong><br>
            Nom / Signature / Date
        </div>
        <div class="signature-box">
            <strong>Validation QC</strong><br>
            Nom / Signature / Date
        </div>
    </div>

    @if (! ($isPdf ?? false))
        <div class="print-controls">
            <button class="print-button" onclick="window.print()">Imprimer</button>
        </div>
    @endif
</body>
</html>
