<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/bookings';

    private User $user;
    private Office $office;
    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->office = Office::factory()->create([
            'timezone' => 'Europe/Moscow',
        ]);

        $this->room = Room::factory()->create([
            'office_id' => $this->office->id,
        ]);

        $this->actingAs($this->user);
    }

    private function payload(
        string $start = '2026-11-10T10:00:00+03:00',
        string $end = '2026-11-10T11:00:00+03:00',
        ?int   $roomId = null,
        string $title = 'Тестовое бронирование'
    ): array
    {
        return [
            'room_id' => $roomId ?? $this->room->id,
            'title' => $title,
            'starts_at' => $start,
            'ends_at' => $end,
        ];
    }

    private function createBooking(
        string $start = '2026-11-10T10:00:00+03:00',
        string $end = '2026-11-10T11:00:00+03:00',
        ?int   $userId = null,
        string $title = 'Тестовое бронирование'
    ): Booking
    {
        return Booking::query()->create([
            'room_id' => $this->room->id,
            'title' => $title,
            'user_id' => $userId ?? $this->user->id,
            'starts_at' => $start,
            'ends_at' => $end,
        ]);
    }

    // ------------------------------------------
    // Создание бронирования
    // ------------------------------------------

    public function test_can_create_booking(): void
    {
        $title = 'Планёрка команды разработки';

        $this->postJson(self::URL, $this->payload(
            title: $title
        ))
            ->assertCreated()
            ->assertJsonPath('room_id', $this->room->id)
            ->assertJsonPath('title', $title)
            ->assertJsonPath('user_id', $this->user->id);

        $this->assertDatabaseHas('bookings', [
            'room_id' => $this->room->id,
            'title' => $title,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_booking_title_is_saved(): void
    {
        $title = 'Обсуждение архитектуры API';

        $this->postJson(self::URL, $this->payload(
            title: $title
        ))->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'room_id' => $this->room->id,
            'title' => $title,
        ]);
    }

    public function test_user_id_cannot_be_spoofed(): void
    {
        $anotherUser = User::factory()->create();

        $this->postJson(self::URL, [
            ...$this->payload(),
            'user_id' => $anotherUser->id,
        ])->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
        ]);

        $this->assertDatabaseMissing('bookings', [
            'room_id' => $this->room->id,
            'user_id' => $anotherUser->id,
        ]);
    }

    // ------------------------------------------
    // Пересечения бронирований
    // ------------------------------------------

    public function test_overlapping_bookings_are_rejected(): void
    {
        $this->createBooking();

        $cases = [
            ['10:15', '11:15'],
            ['09:45', '10:15'],
            ['10:15', '10:45'],
            ['09:45', '11:15'],
            ['10:00', '11:00'],
        ];

        foreach ($cases as [$start, $end]) {
            $this->postJson(self::URL, $this->payload(
                "2026-11-10T{$start}:00+03:00",
                "2026-11-10T{$end}:00+03:00"
            ))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['starts_at']);
        }

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_back_to_back_bookings_are_allowed(): void
    {
        $this->createBooking();

        $this->postJson(self::URL, $this->payload(
            '2026-11-10T09:00:00+03:00',
            '2026-11-10T10:00:00+03:00'
        ))->assertCreated();

        $this->postJson(self::URL, $this->payload(
            '2026-11-10T11:00:00+03:00',
            '2026-11-10T12:00:00+03:00'
        ))->assertCreated();

        $this->assertDatabaseCount('bookings', 3);
    }

    public function test_same_time_in_different_rooms_is_allowed(): void
    {
        $this->createBooking();

        $anotherRoom = Room::factory()->create([
            'office_id' => $this->office->id,
        ]);

        $this->postJson(self::URL, $this->payload(
            roomId: $anotherRoom->id
        ))->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_same_instant_with_different_offsets_conflicts(): void
    {
        $this->createBooking();

        // 07:30 UTC = 10:30 по Москве.
        $this->postJson(self::URL, $this->payload(
            '2026-11-10T07:30:00Z',
            '2026-11-10T08:30:00Z'
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
    }

    // ------------------------------------------
    // Длительность
    // ------------------------------------------

    public function test_minimum_duration_is_allowed(): void
    {
        $this->postJson(self::URL, $this->payload(
            '2026-11-10T10:00:00+03:00',
            '2026-11-10T10:15:00+03:00'
        ))->assertCreated();
    }

    public function test_maximum_duration_is_allowed(): void
    {
        $this->postJson(self::URL, $this->payload(
            '2026-11-10T10:00:00+03:00',
            '2026-11-10T14:00:00+03:00'
        ))->assertCreated();
    }

    public function test_invalid_durations_are_rejected(): void
    {
        $cases = [
            ['10:00', '10:00'],
            ['11:00', '10:00'],
            ['10:00', '10:10'],
            ['10:00', '14:15'],
        ];

        foreach ($cases as [$start, $end]) {
            $this->postJson(self::URL, $this->payload(
                "2026-11-10T{$start}:00+03:00",
                "2026-11-10T{$end}:00+03:00"
            ))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['ends_at']);
        }

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_invalid_start_step_is_rejected(): void
    {
        $this->postJson(self::URL, $this->payload(
            '2026-11-10T10:07:00+03:00',
            '2026-11-10T11:07:00+03:00'
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
    }

    public function test_invalid_end_step_is_rejected(): void
    {
        $this->postJson(self::URL, $this->payload(
            '2026-11-10T10:00:00+03:00',
            '2026-11-10T11:10:00+03:00'
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at']);
    }

    public function test_seconds_are_rejected(): void
    {
        $this->postJson(self::URL, $this->payload(
            '2026-11-10T10:00:01+03:00',
            '2026-11-10T11:00:01+03:00'
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
    }

    // ------------------------------------------
    // Рабочее время
    // ------------------------------------------

    public function test_booking_at_opening_time_is_allowed(): void
    {
        $this->postJson(self::URL, $this->payload(
            '2026-11-10T09:00:00+03:00',
            '2026-11-10T09:15:00+03:00'
        ))->assertCreated();
    }

    public function test_booking_ending_at_closing_time_is_allowed(): void
    {
        $this->postJson(self::URL, $this->payload(
            '2026-11-10T19:45:00+03:00',
            '2026-11-10T20:00:00+03:00'
        ))->assertCreated();
    }

    public function test_booking_before_opening_is_rejected(): void
    {
        $this->postJson(self::URL, $this->payload(
            '2026-11-10T08:45:00+03:00',
            '2026-11-10T09:15:00+03:00'
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
    }

    public function test_booking_after_closing_is_rejected(): void
    {
        $this->postJson(self::URL, $this->payload(
            '2026-11-10T19:45:00+03:00',
            '2026-11-10T20:15:00+03:00'
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
    }

    public function test_booking_across_midnight_is_rejected(): void
    {
        $this->postJson(self::URL, $this->payload(
            '2026-11-10T19:45:00+03:00',
            '2026-11-11T00:00:00+03:00'
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at']);
    }

    // ------------------------------------------
    // Часовые пояса
    // ------------------------------------------

    public function test_utc_is_converted_to_office_timezone(): void
    {
        // 06:00 UTC = 09:00 Moscow.
        $this->postJson(self::URL, $this->payload(
            '2026-11-10T06:00:00Z',
            '2026-11-10T07:00:00Z'
        ))->assertCreated();
    }

    public function test_arbitrary_client_offset_is_supported(): void
    {
        // 11:00 +05:00 = 09:00 Moscow.
        $this->postJson(self::URL, $this->payload(
            '2026-11-10T11:00:00+05:00',
            '2026-11-10T12:00:00+05:00'
        ))->assertCreated();
    }

    public function test_office_timezone_is_used(): void
    {
        $this->office->update([
            'timezone' => 'Europe/Riga',
        ]);

        // 06:00 UTC = 08:00 Riga.
        $this->postJson(self::URL, $this->payload(
            '2026-11-10T06:00:00Z',
            '2026-11-10T07:00:00Z'
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);

        // 07:00 UTC = 09:00 Riga.
        $this->postJson(self::URL, $this->payload(
            '2026-11-10T07:00:00Z',
            '2026-11-10T08:00:00Z'
        ))->assertCreated();
    }

    public function test_summer_timezone_offset_is_supported(): void
    {
        $this->office->update([
            'timezone' => 'Europe/Riga',
        ]);

        // В июле Рига находится в UTC+03.
        $this->postJson(self::URL, $this->payload(
            '2026-07-10T06:00:00Z',
            '2026-07-10T07:00:00Z'
        ))->assertCreated();
    }

    // ------------------------------------------
    // Валидация
    // ------------------------------------------

    public function test_required_fields_are_validated(): void
    {
        $this->postJson(self::URL, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'room_id',
                'title',
                'starts_at',
                'ends_at',
            ]);
    }

    public function test_empty_title_is_rejected(): void
    {
        $this->postJson(self::URL, $this->payload(
            title: ''
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_title_longer_than_255_characters_is_rejected(): void
    {
        $this->postJson(self::URL, $this->payload(
            title: str_repeat('A', 256)
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);
    }

    public function test_room_name_instead_of_id_returns_422(): void
    {
        $this->postJson(self::URL, [
            ...$this->payload(),
            'room_id' => 'Планёрка 28',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id']);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_nonexistent_room_returns_422(): void
    {
        $this->postJson(self::URL, $this->payload(
            roomId: 999999
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id']);
    }

    public function test_invalid_iso8601_returns_422(): void
    {
        $this->postJson(self::URL, $this->payload(
            '2026-11-10 10:00:00',
            '2026-11-10T11:00:00+03:00'
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
    }

    public function test_invalid_calendar_date_returns_422(): void
    {
        $this->postJson(self::URL, $this->payload(
            '2026-02-30T10:00:00+03:00',
            '2026-02-30T11:00:00+03:00'
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
    }

    // ------------------------------------------
    // Нет дополнительных ограничений на даты
    // ------------------------------------------

    public function test_past_booking_is_allowed(): void
    {
        $this->postJson(self::URL, $this->payload(
            '2020-01-10T10:00:00+03:00',
            '2020-01-10T11:00:00+03:00'
        ))->assertCreated();
    }

    public function test_distant_future_booking_is_allowed(): void
    {
        $this->postJson(self::URL, $this->payload(
            '2035-01-10T10:00:00+03:00',
            '2035-01-10T11:00:00+03:00'
        ))->assertCreated();
    }

    // ------------------------------------------
    // Удаление
    // ------------------------------------------

    public function test_author_can_delete_booking(): void
    {
        $booking = $this->createBooking();

        $this->deleteJson(self::URL . '/' . $booking->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('bookings', [
            'id' => $booking->id,
        ]);
    }

    public function test_another_user_cannot_delete_booking(): void
    {
        $author = User::factory()->create();

        $booking = $this->createBooking(
            userId: $author->id
        );

        $this->deleteJson(self::URL . '/' . $booking->id)
            ->assertForbidden();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
        ]);
    }

    public function test_missing_booking_returns_404(): void
    {
        $this->deleteJson(self::URL . '/999999')
            ->assertNotFound();
    }

    // ------------------------------------------
    // Авторизация
    // ------------------------------------------

    public function test_guest_cannot_create_booking(): void
    {
        auth()->logout();

        $this->postJson(self::URL, $this->payload())
            ->assertUnauthorized();
    }

    public function test_guest_cannot_delete_booking(): void
    {
        $booking = $this->createBooking();

        auth()->logout();

        $this->deleteJson(self::URL . '/' . $booking->id)
            ->assertUnauthorized();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
        ]);
    }
}
