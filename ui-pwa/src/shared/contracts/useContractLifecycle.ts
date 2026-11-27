import { useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { useToast } from '@/shared/ui'
import { type ActionKind, type Contract, type VehicleOption } from './contractModel'

const emptyForm = {
  odometer_out: '', odometer_in: '', damage_fee: '', reason: '', planned_ends_at: '',
  extension_days: '', period_rent: '',
  replacement_vehicle_id: '', method: 'cash', receipt_number: '',
  notes: '', damage_position: '', damage_description: '',
  damage_photo_path: '', deduct_amount: '', deduct_reason: '',
  payment_amount: '', payment_method: 'cash', balance_due_on: '',
}

export function useContractLifecycle(onMutated?: () => void) {
  const toast = useToast()
  const [action, setAction] = useState<ActionKind>(null)
  const [selected, setSelected] = useState<Contract | null>(null)
  const [vehicles, setVehicles] = useState<VehicleOption[]>([])
  const [saving, setSaving] = useState(false)
  const [actionForm, setActionForm] = useState(emptyForm)

  async function openAction(kind: ActionKind, contract: Contract) {
    setSelected(contract)
    setAction(kind)
    setActionForm({
      ...emptyForm,
      odometer_out: String(contract.vehicle?.odometer ?? contract.odometer_out ?? ''),
      odometer_in: String(contract.odometer_out ?? contract.vehicle?.odometer ?? ''),
      receipt_number: `R-${contract.number}`,
    })
    if (kind === 'swap' || kind === 'extend') {
      const v = await api<{ data: VehicleOption[] }>('/vehicles?per_page=100')
      setVehicles(v.data)
    }
    if (
      kind === 'return'
      || kind === 'collect'
      || kind === 'extend'
      || (kind === 'handover' && (contract.ledger?.balance === undefined))
    ) {
      try {
        const dossier = await api<{ data: Contract }>(`/contracts/${contract.id}`)
        setSelected({
          ...contract,
          ...dossier.data,
          open_violations: dossier.data.open_violations ?? contract.open_violations ?? [],
          open_violations_total: dossier.data.open_violations_total
            ?? dossier.data.ledger?.violations_due
            ?? contract.open_violations_total
            ?? 0,
          open_violations_count: dossier.data.open_violations?.length
            ?? contract.open_violations_count
            ?? 0,
        })
      } catch {
        /* الشيت يعمل ببيانات القائمة إن وُجدت */
      }
    }
  }

  function closeAction() {
    setAction(null)
    setSelected(null)
  }

  async function approve(id: number) {
    try {
      await api(`/contracts/${id}/approve`, { method: 'POST' })
      toast.success('تمّت موافقة الإدارة وتثبيت الأسعار.')
      onMutated?.()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  /** سوبر أدمن فقط — حذف ناعم للعقد وكل الماليات/المخالفات/الصيانة المرتبطة. */
  async function softDelete(id: number): Promise<boolean> {
    const ok1 = window.confirm(
      'حذف هذا العقد؟ سيُخفى العقد مع المدفوعات والقيود والتأمين والمخالفات والفحوصات والصيانة المرتبطة. لا يمكن التراجع من الواجهة.',
    )
    if (!ok1) return false
    const ok2 = window.confirm('تأكيد نهائي: حذف العقد وكل ما يخصه؟')
    if (!ok2) return false
    try {
      await api(`/contracts/${id}`, { method: 'DELETE' })
      toast.success('تم حذف العقد وما يخصه.')
      onMutated?.()
      return true
    } catch (e) {
      toast.error((e as ApiError).message)
      return false
    }
  }

  async function softPurgeAll(): Promise<boolean> {
    const ok1 = window.confirm(
      'مسح كل العقود ناعماً مع الماليات والمخالفات، وحذف أوامر الشغل/الصيانة المتبقية؟ هذا للسوبر أدمن فقط.',
    )
    if (!ok1) return false
    const typed = window.prompt('اكتب بالضبط: حذف الكل')
    if (typed !== 'حذف الكل') {
      toast.error('لم يُكتب التأكيد المطلوب.')
      return false
    }
    try {
      await api('/contracts/soft-purge-all', { method: 'POST', json: { confirm: 'حذف الكل' } })
      toast.success('تم الحذف الناعم لكل العقود والصيانات.')
      onMutated?.()
      return true
    } catch (e) {
      toast.error((e as ApiError).message)
      return false
    }
  }

  async function runAction(formOverride?: Partial<typeof emptyForm>) {
    if (!selected || !action) return
    const form = formOverride ? { ...actionForm, ...formOverride } : actionForm
    if (formOverride) setActionForm(form)

    if (action === 'handover') {
      const paid = form.payment_amount ? Number(form.payment_amount) : 0
      const total = Number(selected.final_total || selected.ledger?.contract_total || 0)
      const left = Math.max(0, total - paid)
      if (paid > 0 && left > 0 && !form.balance_due_on) {
        toast.error('حدد تاريخ تحصيل باقي المبلغ')
        return
      }
    }
    if (action === 'collect') {
      const paid = Number(form.payment_amount || 0)
      const remaining = Number(selected.ledger?.balance || 0)
      if (paid > 0 && paid < remaining && !form.balance_due_on) {
        toast.error('حدد تاريخ تحصيل الباقي التالي')
        return
      }
    }
    if (action === 'extend') {
      const days = Number(form.extension_days || 0)
      const rent = Number(form.period_rent || 0)
      if (!Number.isFinite(days) || days < 1) {
        toast.error('أدخل مدة التمديد بالأيام')
        return
      }
      if (!Number.isFinite(rent) || rent < 1) {
        toast.error('أدخل إيجار المدة الكامل')
        return
      }
    }

    setSaving(true)
    try {
      if (action === 'handover') {
        await api(`/contracts/${selected.id}/handover`, {
          method: 'POST',
          json: {
            odometer_out: Number(form.odometer_out),
            notes: form.notes || undefined,
            payment_amount: form.payment_amount ? Number(form.payment_amount) : undefined,
            payment_method: form.payment_method || 'cash',
            balance_due_on: form.balance_due_on || undefined,
          },
        })
        toast.success('تم التسليم وتفعيل العقد.')
      } else if (action === 'return') {
        const damages = form.damage_position && form.damage_description
          ? [{
              position: form.damage_position,
              description: form.damage_description,
              photo_path: form.damage_photo_path || undefined,
            }]
          : []
        await api(`/contracts/${selected.id}/return`, {
          method: 'POST',
          json: {
            odometer_in: Number(form.odometer_in),
            damage_fee: form.damage_fee ? Number(form.damage_fee) : 0,
            notes: form.notes || undefined,
            damages,
          },
        })
        toast.success('تم الاستلام وإقفال الإجمالي.')
      } else if (action === 'cancel') {
        await api(`/contracts/${selected.id}/cancel`, { method: 'POST', json: { reason: form.reason } })
        toast.success('تم إلغاء العقد.')
      } else if (action === 'extend') {
        await api(`/contracts/${selected.id}/extend`, {
          method: 'POST',
          json: {
            extension_days: Number(form.extension_days),
            period_rent: Math.round(Number(form.period_rent)),
            planned_ends_at: form.planned_ends_at || undefined,
          },
        })
        toast.success('تم تمديد العقد.')
      } else if (action === 'swap') {
        await api(`/contracts/${selected.id}/swap`, {
          method: 'POST',
          json: {
            replacement_vehicle_id: Number(form.replacement_vehicle_id),
            odometer_in: Number(form.odometer_in),
            odometer_out: Number(form.odometer_out),
            damage_fee: form.damage_fee ? Number(form.damage_fee) : 0,
            notes: form.notes || undefined,
            damages: form.damage_position
              ? [{
                  position: form.damage_position,
                  description: form.damage_description || form.damage_position,
                  photo_path: form.damage_photo_path || null,
                }]
              : [],
          },
        })
        toast.success('تم التبديل بسبب عطل.')
      } else if (action === 'deposit') {
        await api(`/contracts/${selected.id}/deposit`, {
          method: 'POST',
          json: {
            method: form.method,
            receipt_number: form.receipt_number,
            amount: selected.frozen_agreed_deposit,
          },
        })
        toast.success('تم قبض التأمين النقدي.')
      } else if (action === 'deduct') {
        await api(`/contracts/${selected.id}/deposit/deduct`, {
          method: 'POST',
          json: { amount: Number(form.deduct_amount), reason: form.deduct_reason },
        })
        toast.success('تم خصم المبلغ من التأمين.')
      } else if (action === 'refund') {
        await api(`/contracts/${selected.id}/deposit/return`, {
          method: 'POST',
          json: { method: form.method, receipt_number: form.receipt_number },
        })
        toast.success('تم رد متبقي التأمين.')
      } else if (action === 'collect') {
        await api('/accounting/payments', {
          method: 'POST',
          json: {
            amount: Number(form.payment_amount),
            method: form.payment_method || 'cash',
            direction: 'in',
            link_type: 'contract',
            link_id: selected.id,
            notes: form.notes || undefined,
            balance_due_on: form.balance_due_on || undefined,
          },
        })
        toast.success('تم تسجيل التحصيل.')
      }
      closeAction()
      onMutated?.()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  return {
    action, selected, vehicles, saving, actionForm, setActionForm,
    openAction, closeAction, approve, softDelete, softPurgeAll, runAction,
  }
}
