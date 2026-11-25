<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Services\Contracts\ContractInvoiceShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * مشاركة فاتورة العقد عبر واتساب: صفحة عامة موقّعة بلا تسجيل دخول.
 */
class ContractInvoiceShareController extends Controller
{
    public function __construct(private readonly ContractInvoiceShareService $invoices) {}

    /** موظف مسجّل: يجهّز رابط فاتورة عام + نص واتساب. */
    public function prepare(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('view', $contract);

        return response()->json([
            'data' => $this->invoices->prepare($contract),
        ]);
    }

    /** صفحة عامة (HTML) يفتحها العميل من واتساب بلا لوجين. */
    public function publicShare(Request $request): Response
    {
        $id = (int) $request->query('id', 0);
        if ($id < 1) {
            return response('الرابط غير صالح.', 404)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $contract = Contract::query()->find($id);
        if (! $contract) {
            return response('الفاتورة غير متاحة.', 404)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response($this->invoices->renderPublicHtml($contract), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
