// Справочник опций заказа (OrderOption.cs) — клиент-безопасный
export const ORDER_OPTIONS = [
  { code: "child_seat", name: "Детское кресло", price: 50 },
  { code: "pet", name: "Перевозка животного", price: 70 },
  { code: "meeting_sign", name: "Встреча с табличкой", price: 100 },
  { code: "extra_luggage", name: "Крупный багаж", price: 30 },
  { code: "non_smoking", name: "Некурящий салон", price: 0 },
] as const;

export type OrderOptionCode = (typeof ORDER_OPTIONS)[number]["code"];

export interface OrderOptionDto {
  code: string;
  name: string;
  price: number;
}

export function resolveOptions(codes: string[]): OrderOptionDto[] {
  return ORDER_OPTIONS.filter((o) => (codes as string[]).includes(o.code)).map((o) => ({
    code: o.code,
    name: o.name,
    price: o.price,
  }));
}

export function optionsTotal(codes: string[]): number {
  return resolveOptions(codes).reduce((sum, o) => sum + o.price, 0);
}
