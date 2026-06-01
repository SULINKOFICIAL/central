<?php

namespace App\Http\Controllers;

use App\Models\City;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantLocationController extends Controller
{
    /**
     * Retorna cidades ativas do estado selecionado para o formulário de tenant.
     */
    public function cities(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'state_id' => ['required', 'integer', 'exists:states,id'],
        ]);

        $cities = City::where('state_id', $validated['state_id'])
            ->where('status', 1)
            ->orderBy('name')
            ->get(['id', 'state_id', 'name', 'code_ibge']);

        return response()->json([
            'cities' => $cities,
        ]);
    }
}
