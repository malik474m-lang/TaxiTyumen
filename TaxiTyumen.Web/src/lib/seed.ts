// Порт DataSeeder.cs: тарифы Тюмени, админ, оператор + демо клиенты и водители
import { db } from "@/db";
import { users, drivers, tariffs } from "@/db/schema";
import { eq, sql } from "drizzle-orm";
import { hashPassword } from "@/lib/auth";
import { CITY } from "@/lib/taxi";

export async function ensureSeeded() {
  // Тарифы (тарифы для Тюмени из DataSeeder)
  const [{ count: tariffCount }] = await db
    .select({ count: sql<number>`count(*)::int` })
    .from(tariffs);
  if (tariffCount === 0) {
    await db.insert(tariffs).values([
      {
        type: "economy",
        name: "Эконом",
        description: "Бюджетные поездки по городу",
        baseFare: 49,
        pricePerKm: 10,
        pricePerMinute: 3,
        minimumFare: 99,
        freeWaitingMinutes: 3,
        paidWaitingPerMinute: 4,
        nightMultiplier: 1.3,
        peakMultiplier: 1.5,
      },
      {
        type: "comfort",
        name: "Комфорт",
        description: "Комфортные авто, кондиционер",
        baseFare: 99,
        pricePerKm: 16,
        pricePerMinute: 5,
        minimumFare: 179,
        freeWaitingMinutes: 5,
        paidWaitingPerMinute: 5,
        nightMultiplier: 1.3,
        peakMultiplier: 1.4,
      },
      {
        type: "business",
        name: "Бизнес",
        description: "Авто бизнес-класса",
        baseFare: 199,
        pricePerKm: 25,
        pricePerMinute: 8,
        minimumFare: 349,
        freeWaitingMinutes: 5,
        paidWaitingPerMinute: 8,
        nightMultiplier: 1.2,
        peakMultiplier: 1.3,
      },
      {
        type: "minivan",
        name: "Минивэн",
        description: "Для больших компаний, 6+ мест",
        baseFare: 149,
        pricePerKm: 20,
        pricePerMinute: 5,
        minimumFare: 249,
        freeWaitingMinutes: 5,
        paidWaitingPerMinute: 5,
        nightMultiplier: 1.3,
        peakMultiplier: 1.5,
      },
    ]);
  }

  // Администратор и оператор (как в DataSeeder)
  const [{ count: staffCount }] = await db
    .select({ count: sql<number>`count(*)::int` })
    .from(users)
    .where(eq(users.role, "admin"));
  if (staffCount === 0) {
    await db.insert(users).values([
      {
        phone: "+79001234567",
        firstName: "Админ",
        lastName: "Системы",
        email: "admin@taxityumen.ru",
        passwordHash: hashPassword("Admin123!"),
        role: "admin",
        isPhoneVerified: true,
      },
      {
        phone: "+79001234568",
        firstName: "Мария",
        lastName: "Диспетчер",
        email: "operator@taxityumen.ru",
        passwordHash: hashPassword("Operator123!"),
        role: "operator",
        isPhoneVerified: true,
      },
    ]);
  }

  // Демо-водители с машинами и координатами по Тюмени
  const [{ count: driverCount }] = await db
    .select({ count: sql<number>`count(*)::int` })
    .from(drivers);
  if (driverCount === 0) {
    const demoDrivers = [
      {
        firstName: "Алексей",
        lastName: "Иванов",
        phone: "+79221000001",
        carBrand: "Kia",
        carModel: "Rio",
        carColor: "Белый",
        licensePlate: "А123ВС72",
        carYear: 2021,
        balance: 600,
        lat: 57.158,
        lng: 65.534,
        online: true,
      },
      {
        firstName: "Дмитрий",
        lastName: "Петров",
        phone: "+79221000002",
        carBrand: "Hyundai",
        carModel: "Solaris",
        carColor: "Серебристый",
        licensePlate: "В456ОР72",
        carYear: 2022,
        balance: 420,
        lat: 57.138,
        lng: 65.5605,
        online: true,
      },
      {
        firstName: "Сергей",
        lastName: "Сидоров",
        phone: "+79221000003",
        carBrand: "Toyota",
        carModel: "Camry",
        carColor: "Чёрный",
        licensePlate: "Е789КХ72",
        carYear: 2023,
        balance: 900,
        lat: 57.1225,
        lng: 65.5908,
        online: true,
      },
      {
        firstName: "Андрей",
        lastName: "Кузнецов",
        phone: "+79221000004",
        carBrand: "Skoda",
        carModel: "Octavia",
        carColor: "Синий",
        licensePlate: "М234ТУ72",
        carYear: 2020,
        balance: 350,
        lat: 57.1654,
        lng: 65.4749,
        online: false,
      },
      {
        firstName: "Игорь",
        lastName: "Васильев",
        phone: "+79221000005",
        carBrand: "Volkswagen",
        carModel: "Multivan",
        carColor: "Серый",
        licensePlate: "Х567УТ72",
        carYear: 2022,
        balance: 750,
        lat: 57.0951,
        lng: 65.5691,
        online: false,
      },
    ];
    for (const d of demoDrivers) {
      const [user] = await db
        .insert(users)
        .values({
          phone: d.phone,
          firstName: d.firstName,
          lastName: d.lastName,
          passwordHash: hashPassword("Driver123!"),
          role: "driver",
          isPhoneVerified: true,
          rating: 4.8,
        })
        .returning();
      await db.insert(drivers).values({
        userId: user.id,
        carBrand: d.carBrand,
        carModel: d.carModel,
        carColor: d.carColor,
        licensePlate: d.licensePlate,
        carYear: d.carYear,
        isVerified: true,
        status: d.online ? "available" : "offline",
        latitude: d.lat,
        longitude: d.lng,
        balance: d.balance,
        rejectionPenalty: 50,
        lastLocationUpdate: new Date(),
      });
    }
  }

  // Демо-клиент
  const [demoClient] = await db
    .select()
    .from(users)
    .where(eq(users.phone, "+79221112233"));
  if (!demoClient) {
    await db.insert(users).values({
      phone: "+79221112233",
      firstName: "Демо",
      lastName: "Клиент",
      passwordHash: hashPassword("Client123!"),
      role: "client",
      isPhoneVerified: true,
    });
  }
}
