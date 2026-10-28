<?php

namespace App\Http\Controllers\V1\PDF;

use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class CreditNotePdfController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @return Response|View
     */
    public function __invoke(Request $request, CreditNote $creditNote)
    {
        if ($request->has('preview')) {
            return $creditNote->getPreview();
        }

        return $creditNote->getPdfResponse();
    }
}
