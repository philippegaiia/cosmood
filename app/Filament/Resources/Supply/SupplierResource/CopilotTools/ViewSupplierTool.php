<?php

declare(strict_types=1);

namespace App\Filament\Resources\Supply\SupplierResource\CopilotTools;

use App\Models\Supply\Supplier;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ViewSupplierTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return __('View detailed information about a specific supplier');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->description('Supplier ID')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $id = $request['id'] ?? null;

        if (! $id) {
            return __('Please provide a supplier ID.');
        }

        $supplier = Supplier::with([
            'contacts',
            'supplier_listings' => fn ($q) => $q->with('ingredient')->limit(10),
        ])->find($id);

        if (! $supplier) {
            return __('Supplier not found');
        }

        $contacts = $supplier->contacts->map(fn ($c) => sprintf(
            '%s%s | %s | %s',
            $c->name,
            $c->is_primary ? ' (primary)' : '',
            $c->email ?? '-',
            $c->phone ?? '-'
        ))->implode("\n");

        $listings = $supplier->supplier_listings->map(fn ($l) => sprintf(
            '%s | SKU: %s | %s %s | MOQ: %s | Lead: %s days',
            $l->ingredient?->name ?? 'Unknown',
            $l->sku,
            $l->price,
            $l->currency,
            $l->moq ?? '-',
            $l->lead_time_days ?? '-'
        ))->implode("\n");

        return sprintf(
            "Supplier: %s (%s)\nCustomer Code: %s\nStatus: %s\nDelivery: %s days\n\nAddress:\n%s\n%s %s, %s\n\nContact Info:\nEmail: %s\nPhone: %s\nWebsite: %s\n\nContacts:\n%s\n\nListings (%s total):\n%s",
            $supplier->name,
            $supplier->code,
            $supplier->customer_code ?? '-',
            $supplier->is_active ? 'Active' : 'Inactive',
            $supplier->estimated_delivery_days,
            $supplier->address1 ?? '',
            $supplier->zipcode ?? '',
            $supplier->city ?? '',
            $supplier->country ?? '',
            $supplier->email ?? '-',
            $supplier->phone ?? '-',
            $supplier->website ?? '-',
            $contacts ?: __('No contacts'),
            $supplier->supplier_listings()->count(),
            $listings ?: __('No listings')
        );
    }
}
