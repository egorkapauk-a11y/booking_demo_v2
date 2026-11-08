<?php

declare(strict_types=1);

namespace App\Repository;

use App\DataProvider\RoomDataProvider;
use App\Models\Room;

class RoomRepository
{
    public function __construct(
        private readonly RoomDataProvider $dataProvider
    ) {
    }

    public function findById(int $roomId): Room
    {
        return $this->dataProvider->findById($roomId);
    }

    public function lockById(int $roomId): Room
    {
        return $this->dataProvider->lockById($roomId);
    }
}
