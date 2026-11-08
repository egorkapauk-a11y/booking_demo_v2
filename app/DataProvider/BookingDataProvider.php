<?php
declare(strict_types=1);
namespace App\DataProvider;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingDataProvider
{
    public function __construct(
        private readonly RoomDataProvider $roomDataProvider
    )
    {
    }

    public function create(int $roomId, string $title, int $userId, CarbonImmutable $start, CarbonImmutable $end): Booking
    {
        return DB::transaction(function () use ($roomId, $title, $userId, $start, $end) {
            $this->roomDataProvider->lockById($roomId);

            $startUtc = $start->utc()->toIso8601String();
            $endUtc = $end->utc()->toIso8601String();

            $query = Booking::query();
            $conflict = $query
                ->where('room_id', $roomId)
                ->where('starts_at', '<', $endUtc)
                ->where('ends_at', '>', $startUtc)
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'starts_at' => [
                        'Комната уже забронирована на указанное время.',
                    ],
                ]);
            }

            return $query->create([
                'room_id' => $roomId,
                'title' => $title,
                'user_id' => $userId,
                'starts_at' => $startUtc,
                'ends_at' => $endUtc,
            ]);
        }, 3);
    }

    public function deleteForUser(int $bookingId, int $userId): void
    {
        DB::transaction(function () use ($bookingId, $userId) {
            $booking = Booking::query()
                ->whereKey($bookingId)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int)$booking->user_id !== $userId) {
                abort(
                    403,
                    'Отменить бронирование может только его автор.'
                );
            }

            $booking->delete();
        }, 3);
    }
}
