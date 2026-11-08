Запуск Нужны Docker и Docker Compose.

docker compose up -d --wait

docker compose exec app php artisan migrate:fresh --seed

Первый запуск занимает несколько минут: контейнер устанавливает зависимости. API доступно по адресу http://localhost:8000/api.

Запуск тестов

Запустить все Feature-тесты бронирований:

docker compose exec app php artisan test --filter=BookingTest

Запустить только тесты, связанные с названием бронирования:

docker compose exec app php artisan test --filter="test_can_create_booking|test_empty_title_is_rejected|test_required_fields_are_validated"

В compose.yaml прописана новая строка
    ports:
        - "127.0.0.1:5432:5432"

Нужна для доступа к БД локально. Удалить, если не нужно.
