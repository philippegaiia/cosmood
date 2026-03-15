<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Copier email PO {{ $supplierOrder->order_ref }}</title>
    <style>
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: #f3f4f6;
            color: #111827;
        }

        .wrap {
            max-width: 980px;
            margin: 20px auto;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 16px;
        }

        .title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .muted {
            color: #6b7280;
            margin-bottom: 12px;
        }

        .toolbar {
            display: flex;
            gap: 8px;
            margin-bottom: 10px;
        }

        .subject-wrap {
            margin-bottom: 10px;
        }

        .subject-label {
            font-size: 13px;
            color: #374151;
            margin-bottom: 6px;
            display: block;
        }

        .subject-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
        }

        .subject-input {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
            background: #ffffff;
            color: #111827;
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

        textarea {
            width: 100%;
            min-height: 520px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 12px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 13px;
            line-height: 1.5;
            background: #ffffff;
            color: #111827;
            resize: vertical;
        }

        .preview {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 12px;
        }

        .preview th,
        .preview td {
            border: 1px solid #d1d5db;
            padding: 8px 10px;
            text-align: left;
            font-size: 13px;
        }

        .preview th {
            background: #f9fafb;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="title">Copier-coller email fournisseur</div>
        <div class="muted">
            PO {{ $supplierOrder->order_ref ?? ('PO-'.$supplierOrder->id) }}
            @if (! empty($supplierCodes ?? []))
                - Codes fournisseur {{ implode(', ', $supplierCodes) }}
            @endif
        </div>

        <div class="toolbar">
            <button type="button" class="btn primary" onclick="copyEmailText()">Copier le texte</button>
            <a class="btn" href="{{ route('supplier-orders.po-print', $supplierOrder) }}" target="_blank" rel="noopener">Imprimer</a>
            <a class="btn" href="{{ route('supplier-orders.po-pdf', $supplierOrder) }}" target="_blank" rel="noopener">PDF</a>
        </div>

        <div class="subject-wrap">
            <label class="subject-label" for="email-subject">Sujet propose</label>
            <div class="subject-row">
                <input id="email-subject" class="subject-input" type="text" value="{{ $emailSubject }}" readonly>
                <button type="button" class="btn" onclick="copyEmailSubject()">Copier sujet</button>
            </div>
        </div>

        <table class="preview">
            <thead>
                <tr>
                    <th>Article</th>
                    <th>Code fournisseur</th>
                    <th>Quantite</th>
                    <th>Total kg</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($lines as $line)
                    @php
                        $quantity = (float) ($line->quantity ?? 0);
                        $unitWeight = (float) ($line->unit_weight ?? 0);
                    @endphp
                    <tr>
                        <td>{{ $line->supplierListing?->name ?? '-' }}</td>
                        <td>{{ $line->supplierListing?->supplier_code ?? '-' }}</td>
                        <td>{{ number_format($quantity, 3, ',', ' ') }}</td>
                        <td>{{ number_format($quantity * $unitWeight, 3, ',', ' ') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Aucune ligne de commande.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <textarea id="email-text" readonly>{{ $emailText }}</textarea>
    </div>

    <script>
        function copyEmailSubject() {
            const element = document.getElementById('email-subject');
            element.focus();
            element.select();

            navigator.clipboard.writeText(element.value).then(() => {
                alert('Sujet copie dans le presse-papiers.');
            }).catch(() => {
                document.execCommand('copy');
                alert('Sujet copie dans le presse-papiers.');
            });
        }

        function copyEmailText() {
            const element = document.getElementById('email-text');
            element.focus();
            element.select();

            navigator.clipboard.writeText(element.value).then(() => {
                alert('Texte copie dans le presse-papiers.');
            }).catch(() => {
                document.execCommand('copy');
                alert('Texte copie dans le presse-papiers.');
            });
        }
    </script>
</body>
</html>
