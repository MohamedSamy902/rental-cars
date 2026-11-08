import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney } from '@/shared/lib/format'
import {
  PageHeader, Button, Sheet, Fab, MoneyField, SelectField, TextAreaField,
  useToast, DataTable, IconWallet, IconEye, IconCash, type DataColumn,
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
  { id: 'balance', header: 'الرصيد', cell: (a) => <strong>{formatMoney(a.balance)}</strong>, sortValue: (a) => Number(a.balance), mobile: 'badge' },
]

export function PettyCashPage() {
  const toast = useToast()
  const navigate = useNavigate()
  const [rows, setRows] = useState<Account[]>([])
  const [page, setPage] = useState(1)
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [employees, setEmployees] = useState<Employee[]>([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [moveOpen, setMoveOpen] = useState<Account | null>(null)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState({ employee_id: '', balance: '' })
  const [move, setMove] = useState({ type: 'topup', amount: '', notes: '' })

  async function load(nextPage = page) {
    setLoading(true)
    try {
      const res = await api<{ data: Account[]; meta?: { current_page: number; last_page: number; total: number } }>(`/petty-cash?page=${nextPage}&per_page=50`)
      setRows(res.data)
      setMeta({
        current_page: res.meta?.current_page ?? nextPage,
        last_page: res.meta?.last_page ?? 1,
        total: res.meta?.total ?? res.data.length,
      })
      setPage(res.meta?.current_page ?? nextPage)
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load(page) }, [page])

  async function openCreate() {
    setForm({ employee_id: '', balance: '' })
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
      const amount = form.balance ? Number(form.balance) : 0
      await api('/petty-cash', {
        method: 'POST',
        json: {
          employee_id: Number(form.employee_id),
          // Limit kept nullable/zero for schema compatibility — not shown in UI.
          limit_amount: 0,
          balance: amount,
          topup_amount: amount > 0 ? amount : undefined,
        },
      })
      toast.success(amount > 0 ? 'تم فتح/تغذية العهدة.' : 'تم حفظ العهدة.')
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
          notes: move.notes || (move.type === 'topup' ? 'تغذية عهدة' : move.type === 'return' ? 'سحب / استرداد من العهدة' : undefined),
        },
      })
      toast.success('تم تسجيل حركة العهدة.')
      setMoveOpen(null)
      setMove({ type: 'topup', amount: '', notes: '' })
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
        subtitle="تسليم واستخدام واسترداد العهدة المرتبطة بالموظفين"
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
        onRowClick={(a) => navigate(`/petty-cash/${a.id}`)}
        rowActions={(a) => [
          { id: 'view', label: 'عرض التفاصيل', icon: <IconEye size={16} />, onSelect: () => navigate(`/petty-cash/${a.id}`) },
          {
            id: 'topup',
            label: 'تغذية عهدة',
            icon: <IconCash size={16} />,
            onSelect: () => { setMove({ type: 'topup', amount: '', notes: '' }); setMoveOpen(a) },
          },
          {
            id: 'return',
            label: 'سحب / استرداد من العهدة',
            icon: <IconWallet size={16} />,
            onSelect: () => { setMove({ type: 'return', amount: String(a.balance || ''), notes: '' }); setMoveOpen(a) },
          },
        ]}
        emptyTitle="لا توجد عهد مسجّلة"
        emptyDescription="سجّل عهدة لموظف لتتبع التسليم والاستخدام والاسترداد."
        serverPagination={{
          page: meta.current_page,
          lastPage: meta.last_page,
          total: meta.total,
          perPage: 50,
          onPageChange: setPage,
        }}
      />

      <Sheet
        open={open}
        title="تسجيل عهدة"
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
          <MoneyField
            label="مبلغ التغذية الابتدائي"
            value={form.balance}
            hint="يُضاف إلى رصيد الموظف إن وُجدت عهدة سابقة — اتركه فارغاً لفتح عهدة برصيد صفر"
            onChange={(e) => setForm({ ...form, balance: e.target.value })}
          />
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
              { value: 'topup', label: 'تغذية عهدة (إضافة للرصيد)' },
              { value: 'return', label: 'سحب / استرداد من العهدة' },
              { value: 'spend', label: 'استخدام' },
            ]}
          />
          <MoneyField
            label="المبلغ"
            required
            value={move.amount}
            hint={move.type === 'return' && moveOpen ? `الرصيد الحالي ${formatMoney(moveOpen.balance)} — يمكن سحب جزء أو الكل` : undefined}
            onChange={(e) => setMove({ ...move, amount: e.target.value })}
          />
          {move.type === 'return' && moveOpen && moveOpen.balance > 0 ? (
            <Button
              type="button"
              variant="secondary"
              size="sm"
              onClick={() => setMove({ ...move, amount: String(moveOpen.balance) })}
            >
              سحب الرصيد بالكامل ({formatMoney(moveOpen.balance)})
            </Button>
          ) : null}
          <TextAreaField label="ملاحظات" value={move.notes} onChange={(e) => setMove({ ...move, notes: e.target.value })} />
        </div>
      </Sheet>
    </div>
  )
}
