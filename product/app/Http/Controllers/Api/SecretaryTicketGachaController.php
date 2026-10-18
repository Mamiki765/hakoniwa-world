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
            'draw_count' => ['sometimes', 'integer', 'min:1', 'max:9999'],
        ]);
        try {
            $result = $gacha->draw(
                $request->user(),
                (int) $validated['ticket_item_id'],
                (string) $validated['request_key'],
                isset($validated['draw_count']) ? (int) $validated['draw_count'] : null,
            );
        } catch (DomainException $exception) {
            return response()->json([
                'code' => $exception->getCode() === 409 ? 'request_conflict' : 'secretary_ticket_gacha_rejected',
                'message' => $exception->getMessage(),
            ], $exception->getCode() === 409 ? 409 : 422);
        }

        return response()->json(['data' => $result]);
    }
}
