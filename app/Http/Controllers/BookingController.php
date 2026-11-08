<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use App\Service\BookingService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    private int $userId;

    public function __construct(
        private readonly BookingService $bookingService,
        private Authenticatable $user,
    ) {
        $this->user = Auth::user();
        $this->userId = $this->user->getAttributes()['id'];
    }
    public function index(Request $request): LengthAwarePaginator
    {
        return Booking::where('user_id', $this->userId)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
        ]);

        $booking = $this->bookingService->create(
            (int) $data['room_id'],
            $data['title'],
            $this->userId,
            $data['starts_at'],
            $data['ends_at']
        );

        Log::channel('bookings')->info('Booking created', [
            'booking_id' => $booking->id,
            'user_id' => $this->userId,
        ]);

        return response()->json($booking, 201);
    }

    public function destroy(Request $request, int $id): Response
    {
        $this->bookingService->delete($id, $this->userId);

        return response()->noContent();
    }

    public function today(Room $room): JsonResponse
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at . ' – ' . $b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
