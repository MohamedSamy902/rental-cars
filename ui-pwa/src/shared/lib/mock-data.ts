export type VehicleStatus = 'available' | 'reserved' | 'rented' | 'maintenance'

export type Vehicle = {
  id: string
  code: string
  plate: string
  brand: string
  model: string
  year: number
  category: string
  fuel: string
  odometer: number
  dailyRate: number
  deposit: number
  status: VehicleStatus
  priority: number
}

export type BookingStatus = 'pending' | 'approved' | 'active' | 'completed' | 'cancelled'

export type Booking = {
  id: string
  number: string
  customer: string
  vehicle: string
  startAt: string
  endAt: string
  dailyRate: number
  status: BookingStatus
  salesRep: string
}

export const vehicles: Vehicle[] = [
  {
    id: 'v1',
    code: 'C-101',
    plate: 'أ ب ج 1234',
    brand: 'هيونداي',
    model: 'إلنترا',
    year: 2023,
    category: 'اقتصادي',
    fuel: 'بنزين',
    odometer: 41200,
    dailyRate: 900,
    deposit: 3000,
    status: 'available',
    priority: 80,
  },
  {
    id: 'v2',
    code: 'C-214',
    plate: 'س ص ع 7788',
    brand: 'تويوتا',
    model: 'كورولا',
    year: 2022,
    category: 'اقتصادي',
    fuel: 'بنزين',
    odometer: 61500,
    dailyRate: 950,
    deposit: 3000,
    status: 'rented',
    priority: 70,
  },
  {
    id: 'v3',
    code: 'S-012',
    plate: 'ل م ن 4501',
    brand: 'كيا',
    model: 'سبورتاج',
    year: 2024,
    category: 'SUV',
    fuel: 'بنزين',
    odometer: 18200,
    dailyRate: 1400,
    deposit: 5000,
    status: 'reserved',
    priority: 90,
  },
  {
    id: 'v4',
    code: 'C-088',
    plate: 'ق ر ش 3320',
    brand: 'نيسان',
    model: 'صني',
    year: 2021,
    category: 'اقتصادي',
    fuel: 'بنزين',
    odometer: 89000,
    dailyRate: 750,
    deposit: 2500,
    status: 'maintenance',
    priority: 40,
  },
]

export const bookings: Booking[] = [
  {
    id: 'b1',
    number: 'CNT-24081',
    customer: 'أحمد محمود',
    vehicle: 'تويوتا كورولا · س ص ع 7788',
    startAt: '2026-10-25T10:00:00',
    endAt: '2026-10-28T10:00:00',
    dailyRate: 950,
    status: 'active',
    salesRep: 'سارة',
  },
  {
    id: 'b2',
    number: 'CNT-24090',
    customer: 'منى حسين',
    vehicle: 'كيا سبورتاج · ل م ن 4501',
    startAt: '2026-10-28T09:00:00',
    endAt: '2026-11-02T09:00:00',
    dailyRate: 1400,
    status: 'approved',
    salesRep: 'كريم',
  },
  {
    id: 'b3',
    number: 'CNT-24095',
    customer: 'يوسف علي',
    vehicle: 'هيونداي إلنترا · أ ب ج 1234',
    startAt: '2026-10-29T12:00:00',
    endAt: '2026-10-31T12:00:00',
    dailyRate: 900,
    status: 'pending',
    salesRep: 'سارة',
  },
]

export const vehicleStatusLabel: Record<VehicleStatus, string> = {
  available: 'متاحة',
  reserved: 'محجوزة',
  rented: 'مؤجرة',
  maintenance: 'في صيانة',
}

export const bookingStatusLabel: Record<BookingStatus, string> = {
  pending: 'معلّق',
  approved: 'معتمد',
  active: 'نشط',
  completed: 'مكتمل',
  cancelled: 'ملغى',
}

export const categoryOptions = [
  { value: 'economy', label: 'اقتصادي' },
  { value: 'suv', label: 'SUV' },
  { value: 'luxury', label: 'فاخر' },
]

export const fuelOptions = [
  { value: 'petrol', label: 'بنزين' },
  { value: 'diesel', label: 'ديزل' },
  { value: 'hybrid', label: 'هجين' },
]
