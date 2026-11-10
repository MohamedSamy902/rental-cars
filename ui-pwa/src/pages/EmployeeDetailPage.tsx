import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { civilDateOnly, todayCivilIso } from '@/shared/lib/dates'
import { formatDate, formatMoney } from '@/shared/lib/format'
import {
  PageHeader, Button, BackNavButton, Sheet, DetailList, TextField, TextAreaField, MoneyField, SelectField, SwitchField,
  useToast, SkeletonBlock, ErrorState, StatusPill, MetricCardGrid,
  IconWallet, IconCash, IconTrend,
} from '@/shared/ui'
import './pages.css'

type Permission = { id: number; key: string; label: string; module: string }

type StaffUser = {
  id: number
  name: string
  email?: string | null
  phone?: string | null
  is_active: boolean
  role?: {
    id: number
    name: string
    label: string
    permissions: Permission[]
  } | null
}

type Employee = {
  id: number
  name: string
  job_title?: string | null
  hired_on?: string | null
  base_salary: number
  status: string
}

type Advance = {
  id: number
  amount: number
  advanced_on: string
  method?: string | null
  notes?: string | null
}

type Assignment = {
  id: number
  title: string
  status: string
  notes?: string | null
  assigned_at?: string | null
  assignable_type?: string | null
}

type PettyCash = {
  id: number
  employee_id: number
  balance: number
  is_active: boolean
} | null


type PayrollRow = {
  id: number
  year?: number | null
  month?: number | null
  base_salary: number
  advances: number
  net_amount: number
  paid_amount: number
  run_status?: string | null
}

type Dossier = {
  employee: Employee
  user: StaffUser | null
  advances: Advance[]
  assignments: Assignment[]
  petty_cash: PettyCash
  payroll_history: PayrollRow[]
}

export function EmployeeDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()
  const [data, setData] = useState<Dossier | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [editOpen, setEditOpen] = useState(false)
  const [accountOpen, setAccountOpen] = useState(false)
  const [passwordOpen, setPasswordOpen] = useState(false)
  const [advanceOpen, setAdvanceOpen] = useState(false)
  const [assignOpen, setAssignOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [editForm, setEditForm] = useState({ name: '', job_title: '', hired_on: '', base_salary: '', status: 'active' })
  const [accountForm, setAccountForm] = useState({ name: '', phone: '', email: '', is_active: true })
  const [accountErrors, setAccountErrors] = useState<Record<string, string>>({})
  const [passwordForm, setPasswordForm] = useState({ password: '', password_confirmation: '' })
  const [passwordErrors, setPasswordErrors] = useState<Record<string, string>>({})
  const [advanceForm, setAdvanceForm] = useState({ amount: '', advanced_on: todayCivilIso(), method: 'cash', notes: '' })
  const [assignForm, setAssignForm] = useState({ notes: '', handed_on: todayCivilIso() })

  async function load() {
    if (!id) return
    setLoading(true)
    setError('')
    try {
      const res = await api<{ data: Dossier }>(`/employees/${id}`)
      setData(res.data)
      const e = res.data.employee
      setEditForm({
        name: e.name,
        job_title: e.job_title || '',
        hired_on: e.hired_on ? civilDateOnly(String(e.hired_on)) : '',
        base_salary: String(e.base_salary ?? ''),
        status: e.status || 'active',
      })
      const u = res.data.user
      if (u) {
        setAccountForm({
          name: u.name,
          phone: u.phone || '',
          email: u.email || '',
          is_active: u.is_active,
        })
      }
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [id])

  async function saveEmployee() {
    if (!data) return
    setSaving(true)
    try {
      await api(`/employees/${data.employee.id}`, {
        method: 'PUT',
        json: {
          name: editForm.name,
          job_title: editForm.job_title || null,
          hired_on: editForm.hired_on || null,
          base_salary: Number(editForm.base_salary || 0),
          status: editForm.status,
        },
      })
      toast.success('تم تحديث بيانات الموظف.')
      setEditOpen(false)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  async function saveAccount() {
    if (!data?.user) return
    setSaving(true)
    setAccountErrors({})
    try {
      await api(`/staff/${data.user.id}`, {
        method: 'PUT',
        json: {
          name: accountForm.name,
          phone: accountForm.phone.trim(),
          email: accountForm.email.trim() || null,
          is_active: accountForm.is_active,
        },
      })
      toast.success('تم تحديث حساب الدخول.')
      setAccountOpen(false)
      await load()
    } catch (e) {
      const err = e as ApiError
      const next: Record<string, string> = {}
      if (err.errors) for (const [k, v] of Object.entries(err.errors)) next[k] = v[0]
      setAccountErrors(next)
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  async function resetPassword() {
    if (!data?.user) return
    setPasswordErrors({})
    if (passwordForm.password.length < 8) {
      setPasswordErrors({ password: 'كلمة المرور ٨ أحرف على الأقل.' })
      return
    }
    if (passwordForm.password !== passwordForm.password_confirmation) {
      setPasswordErrors({ password_confirmation: 'تأكيد كلمة المرور غير متطابق.' })
      return
    }
    setSaving(true)
    try {
      await api(`/staff/${data.user.id}`, {
        method: 'PUT',
        json: { password: passwordForm.password },
      })
      toast.success('تم تحديث كلمة المرور.')
      setPasswordOpen(false)
      setPasswordForm({ password: '', password_confirmation: '' })
    } catch (e) {
      const err = e as ApiError
      const next: Record<string, string> = {}
      if (err.errors) for (const [k, v] of Object.entries(err.errors)) next[k] = v[0]
      setPasswordErrors(next)
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  async function saveAdvance() {
    if (!data) return
    setSaving(true)
    try {
      await api('/salary-advances', {
        method: 'POST',
        json: {
          employee_id: data.employee.id,
          amount: Number(advanceForm.amount),
          advanced_on: advanceForm.advanced_on,
          method: advanceForm.method,
          notes: advanceForm.notes || null,
        },
      })
      toast.success('تم تسجيل السلفة.')
      setAdvanceOpen(false)
      setAdvanceForm({ amount: '', advanced_on: todayCivilIso(), method: 'cash', notes: '' })
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }


  async function saveAssignment() {
    if (!data) return
    const note = assignForm.notes.trim()
    if (!note) {
      toast.error('أدخل ملاحظات التسليم.')
      return
    }
    setSaving(true)
    try {
      const day = assignForm.handed_on || todayCivilIso()
      await api('/employee-assignments', {
        method: 'POST',
        json: {
          employee_id: data.employee.id,
          title: note.slice(0, 200),
          assignable_type: 'manual',
          notes: `تاريخ التسليم: ${day}\n${note}`,
        },
      })
      toast.success('تم تسجيل تسليم العهدة.')
      setAssignOpen(false)
      setAssignForm({ notes: '', handed_on: todayCivilIso() })
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <div className="rpm-stack"><SkeletonBlock rows={8} /></div>
  if (error || !data) {
    return (
      <div className="rpm-stack">
        <ErrorState
          title="تعذّر تحميل ملف الموظف"
          description={error || 'غير موجود'}
          action={<BackNavButton to="/staff" label="رجوع للموظفين" />}
        />
      </div>
    )
  }

  const { employee: emp, user, advances, assignments, petty_cash: petty, payroll_history: payroll } = data
  const openAdvances = advances.reduce((s, a) => s + Number(a.amount || 0), 0)

  return (
    <div className="rpm-stack">
      <PageHeader
        title={emp.name}
        subtitle={`${emp.job_title || 'بدون مسمى'} · ملف الموظف الكامل`}
        action={
          <>
            <BackNavButton to="/staff" label="رجوع للموظفين" />
            <Button variant="secondary" onClick={() => setEditOpen(true)}>تعديل الموظف</Button>
            <Button variant="secondary" onClick={() => setAdvanceOpen(true)}>إضافة سلفة</Button>
            <Button onClick={() => setAssignOpen(true)}>تسليم عهدة</Button>
          </>
        }
      />

      <MetricCardGrid
        label="ملخص الموظف"
        items={[
          { id: 'salary', label: 'الراتب الأساسي', value: emp.base_salary, format: 'money', icon: <IconCash size={16} /> },
          { id: 'advances', label: 'سلف مفتوحة', value: openAdvances, format: 'money', tone: openAdvances > 0 ? 'warning' : 'success', icon: <IconTrend size={16} /> },
          { id: 'petty', label: 'رصيد العهدة', value: petty?.balance ?? 0, format: 'money', icon: <IconWallet size={16} /> },
        ]}
      />

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>البيانات الأساسية</h3></div>
        <DetailList
          rows={[
            { label: 'الاسم', value: emp.name },
            { label: 'المسمى الوظيفي', value: emp.job_title || '—' },
            { label: 'تاريخ التعيين', value: emp.hired_on ? formatDate(emp.hired_on) : '—' },
            { label: 'الراتب الأساسي', value: formatMoney(emp.base_salary) },
            {
              label: 'الحالة',
              value: (
                <StatusPill
                  status={emp.status === 'active' ? 'active' : 'completed'}
                  label={emp.status === 'active' ? 'على رأس العمل' : 'منتهٍ'}
                />
              ),
            },
          ]}
        />
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head">
          <h3>حساب الدخول والدور</h3>
          {user ? (
            <div className="rpm-card__actions">
              <Button size="sm" variant="secondary" onClick={() => { setAccountErrors({}); setAccountOpen(true) }}>تعديل الحساب</Button>
              <Button size="sm" variant="secondary" onClick={() => { setPasswordErrors({}); setPasswordForm({ password: '', password_confirmation: '' }); setPasswordOpen(true) }}>تعيين كلمة مرور</Button>
              <Button size="sm" variant="ghost" onClick={() => navigate('/staff?tab=permissions')}>الصلاحيات</Button>
            </div>
          ) : null}
        </div>
        {user ? (
          <DetailList
            rows={[
              { label: 'اسم الحساب', value: user.name },
              { label: 'الموبايل', value: user.phone ? <span dir="ltr">{user.phone}</span> : '—' },
              { label: 'البريد', value: user.email ? <span dir="ltr">{user.email}</span> : '—' },
              { label: 'الدور', value: user.role?.label || '—' },
              {
                label: 'الحساب',
                value: <StatusPill status={user.is_active ? 'active' : 'cancelled'} label={user.is_active ? 'نشط' : 'موقوف'} />,
              },
              {
                label: 'الصلاحيات',
                value: user.role?.permissions?.length
                  ? `${user.role.permissions.length} صلاحية`
                  : '—',
              },
            ]}
          />
        ) : (
          <p className="muted">لا يوجد حساب دخول مربوط بهذا الموظف بعد.</p>
        )}
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head">
          <h3>العهدة النقدية</h3>
          {petty ? (
            <Link className="rpm-link-btn" to={`/petty-cash/${petty.id}`}>فتح سجل العهدة</Link>
          ) : (
            <Link className="rpm-link-btn" to="/petty-cash">فتح العهدة</Link>
          )}
        </div>
        {petty ? (
          <DetailList
            rows={[
              { label: 'الرصيد الحالي', value: formatMoney(petty.balance) },
              {
                label: 'الحالة',
                value: <StatusPill status={petty.is_active ? 'active' : 'cancelled'} label={petty.is_active ? 'نشطة' : 'موقوفة'} />,
              },
            ]}
          />
        ) : (
          <p className="muted">لا توجد عهدة نقدية مسجّلة لهذا الموظف.</p>
        )}
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>السلف النشطة</h3></div>
        {advances.length === 0 ? <p className="muted">لا سلف مفتوحة.</p> : (
          <div className="rpm-card-list">
            {advances.map((a) => (
              <article key={a.id} className="rpm-card">
                <div className="rpm-card__row">
                  <strong>{formatMoney(a.amount)}</strong>
                  <span className="muted">{formatDate(a.advanced_on)}</span>
                </div>
                <p className="muted">{a.method || 'نقدي'}{a.notes ? ` · ${a.notes}` : ''}</p>
              </article>
            ))}
          </div>
        )}
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>العهد والأصول المسلَّمة</h3></div>
        {assignments.length === 0 ? <p className="muted">لا تسليمات مسجّلة.</p> : (
          <div className="rpm-card-list">
            {assignments.map((a) => (
              <article key={a.id} className="rpm-card">
                <div className="rpm-card__row">
                  <strong>{a.title}</strong>
                  <StatusPill status={a.status === 'acknowledged' ? 'completed' : 'pending'} label={a.status === 'acknowledged' ? 'مؤكَّد' : 'بانتظار'} />
                </div>
                <p className="muted">{a.assigned_at ? formatDate(a.assigned_at) : '—'}{a.notes ? ` · ${a.notes}` : ''}</p>
              </article>
            ))}
          </div>
        )}
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>سجل الرواتب</h3></div>
        {payroll.length === 0 ? <p className="muted">لا مسيرات رواتب بعد.</p> : (
          <div className="rpm-card-list">
            {payroll.map((p) => (
              <article key={p.id} className="rpm-card">
                <div className="rpm-card__row">
                  <strong>{p.year}/{String(p.month || '').padStart(2, '0')}</strong>
                  <span>{formatMoney(p.net_amount)}</span>
                </div>
                <p className="muted">
                  أساسي {formatMoney(p.base_salary)} · سلف {formatMoney(p.advances)} · مدفوع {formatMoney(p.paid_amount)}
                  {p.run_status ? ` · ${p.run_status}` : ''}
                </p>
              </article>
            ))}
          </div>
        )}
      </section>


      <Sheet
        open={editOpen}
        title="تعديل الموظف"
        onClose={() => setEditOpen(false)}
        footer={<><Button loading={saving} onClick={() => void saveEmployee()}>حفظ</Button><Button variant="secondary" onClick={() => setEditOpen(false)}>إلغاء</Button></>}
      >
        <div className="rpm-form-grid">
          <TextField label="الاسم" required value={editForm.name} onChange={(e) => setEditForm({ ...editForm, name: e.target.value })} />
          <TextField label="المسمى الوظيفي" value={editForm.job_title} onChange={(e) => setEditForm({ ...editForm, job_title: e.target.value })} />
          <TextField label="تاريخ التعيين" type="date" value={editForm.hired_on} onChange={(e) => setEditForm({ ...editForm, hired_on: e.target.value })} />
          <MoneyField label="الراتب الأساسي" value={editForm.base_salary} onChange={(e) => setEditForm({ ...editForm, base_salary: e.target.value })} />
          <SelectField
            label="الحالة"
            value={editForm.status}
            onChange={(e) => setEditForm({ ...editForm, status: e.target.value })}
            options={[
              { value: 'active', label: 'على رأس العمل' },
              { value: 'ended', label: 'منتهٍ' },
            ]}
          />
        </div>
      </Sheet>

      <Sheet
        open={accountOpen}
        title="تعديل حساب الدخول"
        onClose={() => setAccountOpen(false)}
        footer={<><Button loading={saving} onClick={() => void saveAccount()}>حفظ</Button><Button variant="secondary" onClick={() => setAccountOpen(false)}>إلغاء</Button></>}
      >
        <div className="rpm-form-grid">
          <TextField label="الاسم" required value={accountForm.name} error={accountErrors.name} onChange={(e) => setAccountForm({ ...accountForm, name: e.target.value })} />
          <TextField
            label="رقم الموبايل"
            required
            type="tel"
            inputMode="tel"
            dir="ltr"
            value={accountForm.phone}
            error={accountErrors.phone}
            onChange={(e) => setAccountForm({ ...accountForm, phone: e.target.value })}
          />
          <TextField
            label="البريد الإلكتروني"
            type="email"
            dir="ltr"
            value={accountForm.email}
            error={accountErrors.email}
            hint="اختياري"
            onChange={(e) => setAccountForm({ ...accountForm, email: e.target.value })}
          />
          <SwitchField
            label="الحساب نشط"
            checked={accountForm.is_active}
            onChange={(next) => setAccountForm({ ...accountForm, is_active: next })}
          />
        </div>
      </Sheet>

      <Sheet
        open={passwordOpen}
        title="تعيين كلمة مرور"
        onClose={() => setPasswordOpen(false)}
        footer={<><Button loading={saving} onClick={() => void resetPassword()}>حفظ</Button><Button variant="secondary" onClick={() => setPasswordOpen(false)}>إلغاء</Button></>}
      >
        <div className="rpm-form-grid">
          <TextField
            label="كلمة المرور الجديدة"
            required
            type="password"
            value={passwordForm.password}
            error={passwordErrors.password}
            hint="٨ أحرف على الأقل"
            onChange={(e) => setPasswordForm({ ...passwordForm, password: e.target.value })}
          />
          <TextField
            label="تأكيد كلمة المرور"
            required
            type="password"
            value={passwordForm.password_confirmation}
            error={passwordErrors.password_confirmation}
            onChange={(e) => setPasswordForm({ ...passwordForm, password_confirmation: e.target.value })}
          />
        </div>
      </Sheet>

      <Sheet
        open={advanceOpen}
        title="إضافة سلفة"
        onClose={() => setAdvanceOpen(false)}
        footer={<><Button loading={saving} onClick={() => void saveAdvance()}>حفظ</Button><Button variant="secondary" onClick={() => setAdvanceOpen(false)}>إلغاء</Button></>}
      >
        <div className="rpm-form-grid">
          <MoneyField label="المبلغ" required value={advanceForm.amount} onChange={(e) => setAdvanceForm({ ...advanceForm, amount: e.target.value })} />
          <TextField label="التاريخ" type="date" value={advanceForm.advanced_on} onChange={(e) => setAdvanceForm({ ...advanceForm, advanced_on: e.target.value })} />
          <SelectField
            label="الطريقة"
            value={advanceForm.method}
            onChange={(e) => setAdvanceForm({ ...advanceForm, method: e.target.value })}
            options={[
              { value: 'cash', label: 'نقدي' },
              { value: 'transfer', label: 'تحويل' },
              { value: 'card', label: 'بطاقة' },
            ]}
          />
          <TextAreaField label="ملاحظات" value={advanceForm.notes} onChange={(e) => setAdvanceForm({ ...advanceForm, notes: e.target.value })} />
        </div>
      </Sheet>


      <Sheet
        open={assignOpen}
        title="تسليم عهدة / أصل"
        onClose={() => setAssignOpen(false)}
        footer={<><Button loading={saving} onClick={() => void saveAssignment()}>حفظ</Button><Button variant="secondary" onClick={() => setAssignOpen(false)}>إلغاء</Button></>}
      >
        <div className="rpm-form-grid">
          <TextAreaField label="ملاحظات / وصف التسليم" required value={assignForm.notes} onChange={(e) => setAssignForm({ ...assignForm, notes: e.target.value })} />
          <TextField label="تاريخ التسليم" type="date" value={assignForm.handed_on} onChange={(e) => setAssignForm({ ...assignForm, handed_on: e.target.value })} />
        </div>
      </Sheet>
    </div>
  )
}
