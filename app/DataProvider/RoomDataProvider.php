<?php
declare(strict_types=1);
namespace App\DataProvider;

use App\Models\Room;

class RoomDataProvider
{
    public function findById(int $roomId): Room
    {
        return Room::query()
            ->with('office')
            ->findOrFail($roomId);
    }

    public function lockById(int $roomId): Room
    {
        return Room::query()
            ->whereKey($roomId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
