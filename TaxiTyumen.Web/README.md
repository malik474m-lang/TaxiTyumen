# TaxiTyumen.Web — веб-порт системы такси «Тюмень»

Полнофункциональный веб-порт оригинальной системы **TaxiService** (.NET 8):
та же бизнес-логика, те же тарифы Тюмени и все четыре роли — но в виде
веб-приложения на **Next.js 16 (App Router) + PostgreSQL (Drizzle ORM) + Tailwind CSS 4**.

## Архитектура: 4 приложения, роли не пересекаются

Как и в оригинале (TaxiClient / TaxiDriver / TaxiOperator / TaxiAdmin),
у каждой роли своё приложение и свой экран входа:

| Приложение | Маршрут | Роль | Порт оригинала |
|---|---|---|---|
| Клиент | `/client` | только client | TaxiClient (MAUI) |
| Водитель | `/driver` | только driver | TaxiDriver (MAUI) |
| Диспетчерская | `/operator` | только operator | TaxiOperator (WPF) |
| Админ | `/admin` | только admin | TaxiAdmin (Blazor) |

`/` — портал-витрина с выбором приложения. Чужой аккаунт система отклоняет.

## Перенесённая бизнес-логика

- `src/lib/taxi.ts` — `PricingService.cs` + `DistanceCalculator.cs` +
  `OrderNumberGenerator.cs`: расстояние по дорогам через OSRM (fallback Haversine×1.3),
  ночной (23:00–6:00) и пиковый (7–9, 17–19) множители по времени Тюмени (UTC+5),
  минимальный тариф
- `src/app/api/orders/[id]/action/route.ts` — весь жизненный цикл из `OrderService.cs`:
  accept / reject / arrived / start / complete / cancel / force-assign / rate
- Финансы из `BalanceService.cs`: минимальный баланс 100 ₽, комиссия 15 %
  при завершении, штраф 50 ₽ за отказ, история транзакций
- Тарифы из `DataSeeder.cs` (Эконом 49+10/км, Комфорт 99+16, Бизнес 199+25, Минивэн 149+20)
- CI-чат клиент↔водитель вместо SignalR-чата (polling), адреса-подсказки Тюмени

## Запуск

```bash
cp .env.example .env          # укажите свой DATABASE_URL (PostgreSQL)
npm install
npx drizzle-kit push          # создать таблицы
npm run seed                  # нет отдельного скрипта — сиды применяются автоматически
npm run dev                   # http://localhost:3000
```

Сид-данные (тарифы, админ, оператор, демо-водители с машинами) создаются
автоматически при первом обращении к API (`src/lib/seed.ts`).

## Демо-аккаунты

| Роль | Телефон | Пароль |
|---|---|---|
| Клиент | +79221112233 | `Client123!` |
| Водители | +79221000001 … +79221000005 | `Driver123!` |
| Оператор | +79001234568 | `Operator123!` |
| Админ | +79001234567 | `Admin123!` |
