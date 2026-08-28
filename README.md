# TaxiTyumen — экосистема такси «Тюмень»

Комплексная система заказа такси: сервер, админка, клиент, водитель, диспетчерская.

## Состав решения

| Проект | Платформа | Назначение |
|---|---|---|
| `TaxiService.API` | ASP.NET Core + SignalR | REST API, реал-тайм уведомления |
| `TaxiService.Core` | .NET 8 | Бизнес-логика: ценообразование, поиск водителя, баланс |
| `TaxiService.Domain` | .NET 8 | Сущности и enum-ы |
| `TaxiService.Infrastructure` | EF Core + PostgreSQL | БД, миграции, сиды |
| `TaxiAdmin` | Blazor Server | Админ-панель |
| `TaxiClient` | .NET MAUI | Приложение клиента |
| `TaxiDriver` | .NET MAUI | Приложение водителя |
| `TaxiOperator` | WPF | Диспетчерская |
| **`TaxiTyumen.Web`** | **Next.js 16 + PostgreSQL (Drizzle)** | **Веб-порт всей системы (ветка `web-port`)** |

## Веб-порт (TaxiTyumen.Web)

Полный порт бизнес-логики в виде веб-приложения — четыре изолированных
приложения (клиент `/client`, водитель `/driver`, диспетчерская `/operator`,
админ `/admin`). Подробности и инструкция запуска: [`TaxiTyumen.Web/README.md`](TaxiTyumen.Web/README.md).

## Запуск .NET-решения

```powershell
cd TaxiService.API; dotnet run                                     # сервер :5000
cd TaxiAdmin; dotnet run --urls "http://localhost:5200"            # админка
dotnet run --project TaxiClient -f net8.0-windows10.0.19041.0      # клиент
dotnet run --project TaxiDriver -f net8.0-windows10.0.19041.0      # водитель
dotnet run --project TaxiOperator                                  # диспетчерская
```
