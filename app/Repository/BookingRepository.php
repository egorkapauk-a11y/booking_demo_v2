<?php
declare(strict_types=1);

namespace App\Repository;

use App\DataProvider\BookingDataProvider;
use App\Models\Booking;
use Carbon\CarbonImmutable;

class BookingRepository
{
    public function __construct(
        private readonly BookingDataProvider $dataProvider
    )
    {
    }

    public function create(int $roomId, string $title, int $userId, CarbonImmutable $start, CarbonImmutable $end): Booking
    {
        return $this->dataProvider->create($roomId, $title, $userId, $start, $end);
    }

    public function deleteForUser(int $bookingId, int $userId): void
    {
        $this->dataProvider->deleteForUser($bookingId, $userId);
    }
}
