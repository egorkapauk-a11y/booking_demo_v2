<?php
declare(strict_types=1);
namespace App\Service;

use App\Models\Booking;
use App\Repository\BookingRepository;
use App\Repository\RoomRepository;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(
        private readonly BookingRepository $bookingRepository,
        private readonly RoomRepository $roomRepository
    ) {
    }

    public function create(int $roomId, string $title, int $userId, string $startsAt, string $endsAt): Booking
    {
        $room = $this->roomRepository->findById($roomId);
        $timezone = new DateTimeZone(
            $room->getRelations()['office']->getAttributes()['timezone']
        );

        $start = $this->parseDate($startsAt, 'starts_at');
        $end = $this->parseDate($endsAt, 'ends_at');

        $this->validateInterval($start, $end, $timezone);

        return $this->bookingRepository->create($roomId, $title, $userId, $start, $end);
    }

    public function delete(int $bookingId, int $userId): void {
        $this->bookingRepository->deleteForUser($bookingId, $userId);
    }

    private function parseDate(string $value, string $field): CarbonImmutable {
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                $field => ['Некорректная дата или время.'],
            ]);
        }
    }

    private function validateInterval(CarbonImmutable $start, CarbonImmutable $end, DateTimeZone $timezone): void
    {
        foreach ($this->getRules($start, $end, $timezone) as $rule) {
            if ($rule['condition']) {
                throw ValidationException::withMessages([
                    $rule['field'] => [$rule['message']],
                ]);
            }
        }
    }

    private function getRules(CarbonImmutable $start, CarbonImmutable $end, DateTimeZone $timezone): array
    {
        $duration = $end->getTimestamp() - $start->getTimestamp();

        $localStart = $start->setTimezone($timezone);
        $localEnd = $end->setTimezone($timezone);

        $startMinutes = $localStart->hour * 60 + $localStart->minute;
        $endMinutes = $localEnd->hour * 60 + $localEnd->minute;

        return [
            [
                'condition' => $duration <= 0,
                'field' => 'ends_at',
                'message' => 'Окончание должно быть позже начала.',
            ],
            [
                'condition' => $duration < 900 || $duration > 14400,
                'field' => 'ends_at',
                'message' => 'Длительность должна быть от 15 минут до 4 часов.',
            ],
            [
                'condition' => $duration % 900 !== 0,
                'field' => 'ends_at',
                'message' => 'Длительность должна быть кратна 15 минутам.',
            ],
            [
                'condition' => $localStart->minute % 15 !== 0
                    || $localStart->second !== 0
                    || $localStart->micro !== 0,
                'field' => 'starts_at',
                'message' => 'Время начала должно быть кратно 15 минутам.',
            ],
            [
                'condition' => $localEnd->minute % 15 !== 0
                    || $localEnd->second !== 0
                    || $localEnd->micro !== 0,
                'field' => 'ends_at',
                'message' => 'Время окончания должно быть кратно 15 минутам.',
            ],
            [
                'condition' => !$localStart->isSameDay($localEnd),
                'field' => 'ends_at',
                'message' => 'Бронирование должно укладываться в один рабочий день.',
            ],
            [
                'condition' => $startMinutes < 540 || $endMinutes > 1200,
                'field' => 'starts_at',
                'message' => 'Бронирование должно укладываться в рабочее время 09:00–20:00.',
            ],
        ];
    }
}
