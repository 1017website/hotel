<?php

namespace App\Http\Controllers;

use App\Support\HotelContext;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function show(Request $request, int $id)
    {
        $ctx = new HotelContext($request);
        $ctx->authorize('finance.view');
        $reservation = $ctx->table('reservations')->find($id);
        abort_unless($reservation, 404);
        return response()->view('invoice', [
            'hotel' => $ctx->hotel, 'reservation' => $reservation,
            'room' => $ctx->table('rooms')->find($reservation->room_id),
            'orders' => $ctx->table('food_orders')->where('reservation_id', $id)->get(),
            'payments' => $ctx->table('payments')->where('reservation_id', $id)->get(),
            'refunds' => $ctx->table('payment_refunds')->where('reservation_id',$id)->get(),
        ])->header('Cache-Control', 'no-store');
    }
}
