/** Single source for operational status labels (Arabic · RTL). */

export const contractStatusLabel: Record<string, string> = {
  pending: 'معلّق',
  approved: 'معتمد',
  active: 'نشط',
  completed: 'مكتمل',
  cancelled: 'ملغى',
  draft: 'مسودة',
  handed_over: 'نشط / مسلّم',
  confirmed: 'معتمد',
  returned: 'مكتمل',
  closed: 'مكتمل',
  canceled: 'ملغى',
}

export const vehicleStatusLabel: Record<string, string> = {
  available: 'متاحة',
  reserved: 'محجوزة',
  rented: 'مؤجرة',
  maintenance: 'في صيانة',
  out_of_service: 'خارج الخدمة',
  expiring: 'قاربت على الانتهاء',
}

export const workOrderStatusLabel: Record<string, string> = {
  open: 'مفتوح',
  in_progress: 'قيد التنفيذ',
  closed: 'مغلق',
  cancelled: 'ملغى',
}

export const violationStatusLabel: Record<string, string> = {
  new: 'جديدة',
  assigned: 'مسندة',
  confirmed: 'مؤكّد السداد',
  paid: 'مدفوعة للجهة',
}

export function labelFor(map: Record<string, string>, status: string): string {
  return map[status] || status
}
