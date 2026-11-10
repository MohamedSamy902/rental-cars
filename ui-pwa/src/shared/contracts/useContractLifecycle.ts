import { useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { useToast } from '@/shared/ui'
import { type ActionKind, type Contract, type VehicleOption } from './contractModel'

const emptyForm = {
  odometer_out: '', odometer_in: '', damage_fee: '', reason: '', planned_ends_at: '',
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
      toast.success('تم اعتماد العقد وتثبيت الأسعار.')
      onMutated?.()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  async function runAction() {
    if (!selected || !action) return

    if (action === 'handover') {
      const paid = actionForm.payment_amount ? Number(actionForm.payment_amount) : 0
      const total = Number(selected.final_total || selected.ledger?.total || 0)
      const left = Math.max(0, total - paid)
      if (paid > 0 && left > 0 && !actionForm.balance_due_on) {
        toast.error('حدد تاريخ تحصيل باقي المبلغ')
        return
      }
    }
    if (action === 'collect') {
      const paid = Number(actionForm.payment_amount || 0)
      const remaining = Number(selected.ledger?.balance || 0)
      if (paid > 0 && paid < remaining && !actionForm.balance_due_on) {
        toast.error('حدد تاريخ تحصيل الباقي التالي')
        return
      }
    }

    setSaving(true)
    try {
      if (action === 'handover') {
        await api(`/contracts/${selected.id}/handover`, {
          method: 'POST',
          json: {
            odometer_out: Number(actionForm.odometer_out),
            notes: actionForm.notes || undefined,
            payment_amount: actionForm.payment_amount ? Number(actionForm.payment_amount) : undefined,
            payment_method: actionForm.payment_method || 'cash',
            balance_due_on: actionForm.balance_due_on || undefined,
          },
        })
        toast.success('تم التسليم وتفعيل العقد.')
      } else if (action === 'return') {
        const damages = actionForm.damage_position && actionForm.damage_description
          ? [{
              position: actionForm.damage_position,
              description: actionForm.damage_description,
              photo_path: actionForm.damage_photo_path || undefined,
            }]
          : []
        await api(`/contracts/${selected.id}/return`, {
          method: 'POST',
          json: {
            odometer_in: Number(actionForm.odometer_in),
            damage_fee: actionForm.damage_fee ? Number(actionForm.damage_fee) : 0,
            notes: actionForm.notes || undefined,
            damages,
          },
        })
        toast.success('تم الاستلام وإقفال الإجمالي.')
      } else if (action === 'cancel') {
        await api(`/contracts/${selected.id}/cancel`, { method: 'POST', json: { reason: actionForm.reason } })
        toast.success('تم إلغاء العقد.')
      } else if (action === 'extend') {
        await api(`/contracts/${selected.id}/extend`, {
          method: 'POST',
          json: { planned_ends_at: actionForm.planned_ends_at },
        })
        toast.success('تم إنشاء عقد التمديد.')
      } else if (action === 'swap') {
        await api(`/contracts/${selected.id}/swap`, {
          method: 'POST',
          json: {
            replacement_vehicle_id: Number(actionForm.replacement_vehicle_id),
            odometer_in: Number(actionForm.odometer_in),
            odometer_out: Number(actionForm.odometer_out),
            damage_fee: actionForm.damage_fee ? Number(actionForm.damage_fee) : 0,
            notes: actionForm.notes || undefined,
            damages: actionForm.damage_position
              ? [{
                  position: actionForm.damage_position,
                  description: actionForm.damage_description || actionForm.damage_position,
                  photo_path: actionForm.damage_photo_path || null,
                }]
              : [],
          },
        })
        toast.success('تم التبديل بسبب عطل.')
      } else if (action === 'deposit') {
        await api(`/contracts/${selected.id}/deposit`, {
          method: 'POST',
          json: {
            method: actionForm.method,
            receipt_number: actionForm.receipt_number,
            amount: selected.frozen_agreed_deposit,
          },
        })
        toast.success('تم قبض التأمين النقدي.')
      } else if (action === 'deduct') {
        await api(`/contracts/${selected.id}/deposit/deduct`, {
          method: 'POST',
          json: { amount: Number(actionForm.deduct_amount), reason: actionForm.deduct_reason },
        })
        toast.success('تم خصم المبلغ من التأمين.')
      } else if (action === 'refund') {
        await api(`/contracts/${selected.id}/deposit/return`, {
          method: 'POST',
          json: { method: actionForm.method, receipt_number: actionForm.receipt_number },
        })
        toast.success('تم رد متبقي التأمين.')
      } else if (action === 'collect') {
        await api('/accounting/payments', {
          method: 'POST',
          json: {
            amount: Number(actionForm.payment_amount),
            method: actionForm.payment_method || 'cash',
            direction: 'in',
            link_type: 'contract',
            link_id: selected.id,
            notes: actionForm.notes || undefined,
            balance_due_on: actionForm.balance_due_on || undefined,
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
    openAction, closeAction, approve, runAction,
  }
}
