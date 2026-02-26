<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Impression groupée productions</title>
    <style>
        body {
            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
            margin: 24px;
            color: #111827;
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .button {
            display: inline-block;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 8px 12px;
            background: #fff;
            color: #111827;
            text-decoration: none;
            cursor: pointer;
            font-size: 13px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        th,
        td {
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            padding: 8px;
            vertical-align: top;
        }

        th {
            background: #f9fafb;
            font-weight: 700;
        }

        .links {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
    @php
        $productionUrls = $productions->map(function ($production) {
            return route('productions.print-sheet', $production);
        })->values()->all();

        $followUrls = $productions->map(function ($production) {
            return route('productions.follow-sheet', $production);
        })->values()->all();
    @endphp

    <h1>Impression groupée - Productions</h1>

    <div class="actions">
        <button class="button" type="button" onclick="openAll('production')">Ouvrir toutes les fiches de production</button>
        <button class="button" type="button" onclick="openAll('follow')">Ouvrir toutes les fiches de suivi</button>
        <button class="button" type="button" onclick="openAll('both')">Ouvrir les 2 pour chaque production</button>
    </div>

    <table>
        <thead>
            <tr>
                <th>Batch permanent</th>
                <th>Batch planning</th>
                <th>Produit</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($productions as $production)
                <tr>
                    <td>{{ $production->permanent_batch_number ?: '-' }}</td>
                    <td>{{ $production->batch_number ?: '-' }}</td>
                    <td>{{ $production->product?->name ?? '-' }}</td>
                    <td>
                        <div class="links">
                            <a class="button" href="{{ route('productions.print-sheet', $production) }}" target="_blank">Fiche production</a>
                            <a class="button" href="{{ route('productions.follow-sheet', $production) }}" target="_blank">Fiche suivi</a>
                            <a class="button" href="{{ route('productions.sheet-pdf', $production) }}" target="_blank">PDF production</a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">Aucune production sélectionnée.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <script id="bulk-production-urls" type="application/json">{!! json_encode($productionUrls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    <script id="bulk-follow-urls" type="application/json">{!! json_encode($followUrls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    <script>
        const productionUrls = JSON.parse(document.getElementById('bulk-production-urls')?.textContent ?? '[]');
        const followUrls = JSON.parse(document.getElementById('bulk-follow-urls')?.textContent ?? '[]');

        function openMany(urls) {
            for (const url of urls) {
                const popup = window.open(url, '_blank');

                if (! popup) {
                    window.alert('Le navigateur a bloque les popups. Autorisez les popups pour ce site, puis reessayez.');

                    break;
                }
            }
        }

        function openAll(mode) {
            if (mode === 'production' || mode === 'both') {
                openMany(productionUrls);
            }

            if (mode === 'follow' || mode === 'both') {
                openMany(followUrls);
            }
        }
    </script>
</body>
</html>
