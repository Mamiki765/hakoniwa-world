<?php

namespace App\Http\Controllers\Api;

use App\Application\SecretaryTicketGachaService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SecretaryTicketGachaController extends Controller
{
    public function draw(Request $request, SecretaryTicketGachaService $gacha): JsonResponse
    {
        $validated = $request->validate([
            'ticket_item_id' => ['required', 'integer', 'min:1'],
            'request_key' => ['required', 'uuid'],
        ]);
        try {
            $items = $gacha->draw(
                $request->user(),
                (int) $validated['ticket_item_id'],
                (string) $validated['request_key'],
            );
        } catch (DomainException $exception) {
            return response()->json([
                'code' => 'secretary_ticket_gacha_rejected',
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(['data' => ['items' => $items]]);
    }
}
