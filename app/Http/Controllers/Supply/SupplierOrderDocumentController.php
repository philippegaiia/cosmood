<?php

namespace App\Http\Controllers\Supply;

use App\Http\Controllers\Controller;
use App\Models\Supply\SupplierOrder;
use App\Services\Supply\SupplierOrderDocumentBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\Response;

class SupplierOrderDocumentController extends Controller
{
    public function __construct(private SupplierOrderDocumentBuilder $documentBuilder) {}

    public function printView(SupplierOrder $supplierOrder): View
    {
        return view(
            'supply.supplier-orders.purchase-order-print',
            $this->documentBuilder->buildViewData($supplierOrder),
        );
    }

    public function pdf(SupplierOrder $supplierOrder): Response
    {
        $data = $this->documentBuilder->buildViewData($supplierOrder);
        $pdf = Pdf::loadView('supply.supplier-orders.purchase-order-pdf', $data);

        return $pdf->download($this->documentBuilder->buildPdfFilename($supplierOrder));
    }

    public function emailCopy(SupplierOrder $supplierOrder): View
    {
        return view(
            'supply.supplier-orders.purchase-order-email-copy',
            array_merge(
                $this->documentBuilder->buildViewData($supplierOrder),
                $this->documentBuilder->buildEmailCopy($supplierOrder),
            ),
        );
    }
}
