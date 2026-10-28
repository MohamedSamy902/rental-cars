import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney } from '@/shared/lib/format'
import {
  PageHeader, Button, Sheet, Fab, NumberField, MoneyField, SelectField, TextAreaField,
  useToast, DataTable, IconWallet, type DataColumn,
} from '@/shared/ui'
import './pages.css'

type Account = {
  id: number
  employee_id: number
  limit_amount: number
  balance: number
  is_active: boolean
  employee?: { id: number; name: string; job_title?: string }
}

type Employee = { id: number; name: string; job_title?: string }

const columns: DataColumn<Account>[] = [
  {
    id: 'employee',
    header: 'الموظف',
    cell: (a) => <strong className="rpm-table__primary">{a.employee?.name || `موظف #${a.employee_id}`}</strong>,
    sortValue: (a) => a.employee?.name,
    mobile: 'title',
  },
  { id: 'job', header: 'الوظيفة', cell: (a) => a.employee?.job_title || '—', sortValue: (a) => a.employee?.job_title, mobile: 'subtitle' },
  { id: 'balance', header: 'الرصيد', cell: (a) => <strong>{formatMoney(a.balance)}</strong>, sortValue: (a) => Number(a.balance) },
  { id: 'limit', header: 'حد العهدة', cell: (a) => formatMoney(a.limit_amount), sortValue: (a) => Number(a.limit_amount) },
]

export function PettyCashPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Account[]>([])
  const [employees, setEmployees] = useState<Employee[]>([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [moveOpen, setMoveOpen] = useState<Account | null>(null)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState({ employee_id: '', limit_amount: '1000', balance: '' })
  const [move, setMove] = useState({ type: 'spend', amount: '', notes: '' })

  async function load() {
    setLoading(true)
    try {
      const res = await api<{ data: Account[] }>('/petty-cash')
      setRows(res.data)
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [])

  async function openCreate() {
    setOpen(true)
    try {
      const res = await api<{ data: Employee[] }>('/employees/lookup')
      setEmployees(res.data)
    } catch {
      const res = await api<{ data: Employee[] }>('/employees')
      setEmployees(res.data)
    }
  }

  async function saveAccount() {
    setSaving(true)
    try {
      await api('/petty-cash', {
        method: 'POST',
        json: {
          employee_id: Number(form.employee_id),
          limit_amount: Number(form.limit_amount),
          balance: form.balance ? Number(form.balance) : undefined,
        },
      })
      toast.success('تم حفظ العهدة.')
      setOpen(false)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  async function saveMove() {
    if (!moveOpen) return
    setSaving(true)
    try {
      await api(`/petty-cash/${moveOpen.id}/move`, {
        method: 'POST',
        json: {
          type: move.type,
          amount: Number(move.amount),
          notes: move.notes || undefined,
        },
      })
      toast.success('تم تسجيل حركة العهدة.')
      setMoveOpen(null)
      setMove({ type: 'spend', amount: '', notes: '' })
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="rpm-stack">
      <PageHeader
        title="العهدة النقدية"
        subtitle="تسليم واستخدام ومرتجع للعهدة المرتبطة بالموظفين"
        action={<Button onClick={() => void openCreate()}>عهدة جديدة</Button>}
        mobileFab
      />
      <Fab label="عهدة جديدة" onClick={() => void openCreate()} />
      <DataTable
        label="العهد"
        rows={rows}
        columns={columns}
        rowKey={(a) => a.id}
        rowLabel={(a) => `عهدة ${a.employee?.name ?? ''}`}
        loading={loading}
        onRowClick={(a) => setMoveOpen(a)}
        rowActions={(a) => [
          { id: 'move', label: 'تسجيل حركة', icon: <IconWallet size={16} />, onSelect: () => setMoveOpen(a) },
        ]}
        emptyTitle="لا توجد عهد مسجّلة"
        emptyDescription="سجّل عهدة لموظف لتتبع التسليم والاستخدام والمرتجع."
      />

      <Sheet
        open={open}
        title="تسجيل / تحديث عهدة"
        onClose={() => setOpen(false)}
        footer={<><Button loading={saving} onClick={() => void saveAccount()}>حفظ</Button><Button variant="secondary" onClick={() => setOpen(false)}>إلغاء</Button></>}
      >
        <div className="rpm-form-grid">
          <SelectField
            label="الموظف"
            required
            value={form.employee_id}
            onChange={(e) => setForm({ ...form, employee_id: e.target.value })}
            options={employees.map((e) => ({ value: String(e.id), label: `${e.name}${e.job_title ? ` · ${e.job_title}` : ''}` }))}
          />
          <MoneyField label="حد العهدة" required value={form.limit_amount} onChange={(e) => setForm({ ...form, limit_amount: e.target.value })} />
          <MoneyField label="الرصيد الابتدائي" value={form.balance} hint="اتركه فارغاً = نفس الحد" onChange={(e) => setForm({ ...form, balance: e.target.value })} />
        </div>
      </Sheet>

      <Sheet
        open={!!moveOpen}
        title={moveOpen ? `حركة عهدة · ${moveOpen.employee?.name || ''}` : ''}
        onClose={() => setMoveOpen(null)}
        footer={<><Button loading={saving} onClick={() => void saveMove()}>تأكيد</Button><Button variant="secondary" onClick={() => setMoveOpen(null)}>إلغاء</Button></>}
      >
        <div className="rpm-form-grid">
          <SelectField
            label="نوع الحركة"
            required
            value={move.type}
            onChange={(e) => setMove({ ...move, type: e.target.value })}
            options={[
              { value: 'topup', label: 'تسليم / تعزيز' },
              { value: 'spend', label: 'استخدام' },
              { value: 'adjust', label: 'تسوية رصيد' },
            ]}
          />
          <NumberField label="المبلغ" required value={move.amount} onChange={(e) => setMove({ ...move, amount: e.target.value })} />
          <TextAreaField label="ملاحظات" value={move.notes} onChange={(e) => setMove({ ...move, notes: e.target.value })} />
        </div>
      </Sheet>
    </div>
  )
}
